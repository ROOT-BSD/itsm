<?php

namespace App\Services;

use App\Core\Database;
use App\Core\WebhookClient;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookEndpoint;

/**
 * Вебхуки: подія в системі → запис у чергу webhook_deliveries → підписаний HTTP POST на адресу підписника.
 *
 * Надійність:
 *  - Подія ЗАПИСУЄТЬСЯ в чергу тим самим з'єднанням, що й зміна, яка її спричинила, тож це одна транзакція: відкат зміни
 *    (наприклад, збій обробки листа) скасовує й подію — одержувач не побачить подій про те, чого насправді не сталось.
 *  - Відправка йде ПІСЛЯ відповіді користувачу (PHP-FPM) або з cron-скрипта bin/deliver-webhooks.php; збій одержувача
 *    ніколи не впливає на роботу самої системи. Помилка самого механізму вебхуків теж проковтується (лог), щоб не
 *    ламати збереження тікета чи задачі.
 *  - Кожна доставка «орендується» (UPDATE … next_attempt_at = через 2 хв) перед відправкою: два процеси не відправлять
 *    одне й те саме двічі; якщо процес загинув посеред відправки, доставка повторюється після закінчення оренди.
 *  - Невдача → повтор через 1 хв, 5 хв, 30 хв, 2 год, 12 год (разом 6 спроб), потім статус failed. Поспіль 20 невдач
 *    автоматично вимикають ендпоінт; відповідь 410 Gone вимикає його одразу.
 *
 * Підпис: заголовок X-ITSM-Signature: sha256=<HMAC-SHA256(секрет, "<X-ITSM-Timestamp>.<тіло>")>. Підписується і час, тож
 * перехоплений запит не можна відтворити пізніше (одержувач відхиляє запити зі старою позначкою часу).
 * Дублі можливі (гарантія «принаймні раз»): одержувач дедуплікує за полем id події (однаковий при повторах).
 */
final class WebhookService
{
    public const MAX_ATTEMPTS = 6;
    /** Пауза після невдалої спроби N (1-based), секунд. */
    private const BACKOFF = [1 => 60, 2 => 300, 3 => 1800, 4 => 7200, 5 => 43200];
    private const DISABLE_AFTER_FAILURES = 20;
    private const LEASE_SECONDS = 120;
    private const KEEP_DAYS = 30;
    private const STALE_PENDING_DAYS = 3;

    /** @var int[] id доставок, створених у цьому запиті, — їх відправляємо після відповіді користувачу */
    private static array $pending = [];
    private static bool $flushRegistered = false;

    // ------------------------------------------------------------------ відповідність аудиту подіям

    /** Подія за записом журналу аудиту (null — така дія вебхуків не стосується). */
    public static function eventFromAudit(string $entityType, string $action): ?string
    {
        $map = [
            'ticket' => ['created' => 'ticket.created', 'operator_assigned' => 'ticket.assigned', 'auto_assigned_operator' => 'ticket.assigned', 'csat_submitted' => 'ticket.rated'],
            'task' => ['created' => 'task.created', 'status_changed' => 'task.status_changed', 'assignee_changed' => 'task.assigned',
                       'description_changed' => 'task.updated', 'dates_changed' => 'task.updated', 'project_changed' => 'task.updated', 'milestone_changed' => 'task.updated',
                       'deleted' => 'task.deleted'],
            'project' => ['created' => 'project.created', 'responsible_changed' => 'project.responsible_changed', 'member_added' => 'project.member_added',
                          'member_removed' => 'project.member_removed', 'deleted' => 'project.deleted'],
        ];
        if (isset($map[$entityType][$action])) {
            return $map[$entityType][$action];
        }
        // Статус кодується в самій назві дії: status_changed_to_resolved.
        if ($entityType === 'ticket' && str_starts_with($action, 'status_changed_to_')) {
            return 'ticket.status_changed';
        }
        if ($entityType === 'project' && str_starts_with($action, 'status_changed_to_')) {
            return 'project.status_changed';
        }
        return null;
    }

    /** Викликається з Audit::log() для кожного запису журналу. Ніколи не кидає виняток. */
    public static function fromAudit(string $entityType, int $entityId, string $action, ?int $userId, ?array $changes): void
    {
        $event = self::eventFromAudit($entityType, $action);
        if ($event !== null) {
            self::emit($event, $entityType, $entityId, $userId, $changes);
        }
    }

    // ------------------------------------------------------------------ постановка події в чергу

    /**
     * Ставить подію в чергу для всіх підписаних активних ендпоінтів. Ніколи не кидає виняток: збій вебхуків не повинен
     * зривати збереження того, що їх спричинило.
     *
     * @param array<string, mixed>|null $changes додаткові відомості з журналу аудиту (було/стало тощо)
     * @param array<string, mixed>|null $comment для подій-коментарів: сам коментар
     */
    public static function emit(string $event, string $entityType, int $entityId, ?int $actorId, ?array $changes = null, ?array $comment = null): void
    {
        try {
            if (!WebhookEndpoint::available()) {
                return;
            }
            $endpoints = WebhookEndpoint::activeFor($event);
            if (!$endpoints) {
                return;
            }

            // Для подій з журналу аудиту канал уже в changes; для явних (коментарі) беремо поточний канал запиту.
            $via = $changes['via'] ?? \App\Models\Audit::via();
            unset($changes['via']);
            $payload = [
                'id' => self::uuid(),
                'event' => $event,
                'created_at' => date('c'),
                'actor' => self::actor($actorId),
                'via' => $via,
                'data' => self::resource($entityType, $entityId),
            ];
            if ($comment !== null) {
                $payload['comment'] = $comment;
            }
            if ($changes) {
                $payload['changes'] = $changes;
            }
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            $stmt = Database::connection()->prepare(
                "INSERT INTO webhook_deliveries (endpoint_id, event, event_id, payload, status, next_attempt_at) VALUES (:endpoint, :event, :event_id, :payload, 'pending', NOW())"
            );
            foreach ($endpoints as $endpoint) {
                $stmt->execute(['endpoint' => $endpoint['id'], 'event' => $event, 'event_id' => $payload['id'], 'payload' => $json]);
                self::$pending[] = (int) Database::connection()->lastInsertId();
            }
            self::registerFlush();
        } catch (\Throwable $e) {
            error_log('[itsm] webhook emit failed: ' . $e->getMessage());
        }
    }

    /** Подія «новий коментар у тікеті»: у payload — тікет і сам коментар. Викликається з Ticket::addComment() — тож покриває веб, портал, пошту й API. */
    public static function emitTicketComment(int $ticketId, int $commentId, ?int $authorId): void
    {
        try {
            $stmt = Database::connection()->prepare('SELECT c.*, u.full_name AS author_name FROM ticket_comments c LEFT JOIN users u ON u.id = c.author_id WHERE c.id = :id');
            $stmt->execute(['id' => $commentId]);
            $row = $stmt->fetch();
            if ($row) {
                self::emit('ticket.comment_added', 'ticket', $ticketId, $authorId, null, ApiSerializer::ticketComment($row));
            }
        } catch (\Throwable $e) {
            error_log('[itsm] webhook comment failed: ' . $e->getMessage());
        }
    }

    public static function emitTaskComment(int $taskId, int $commentId, ?int $authorId): void
    {
        try {
            $stmt = Database::connection()->prepare('SELECT c.*, u.full_name AS author_name FROM comments c JOIN users u ON u.id = c.author_id WHERE c.id = :id');
            $stmt->execute(['id' => $commentId]);
            $row = $stmt->fetch();
            if ($row) {
                self::emit('task.comment_added', 'task', $taskId, $authorId, null, ApiSerializer::taskComment($row));
            }
        } catch (\Throwable $e) {
            error_log('[itsm] webhook comment failed: ' . $e->getMessage());
        }
    }

    /** @return array<string, mixed> JSON-представлення об'єкта (те саме, що й у REST API) */
    private static function resource(string $entityType, int $id): array
    {
        $row = match ($entityType) {
            'ticket' => Ticket::find($id),
            'task' => Task::find($id),
            'project' => Project::find($id),
            default => null,
        };
        if ($row === null) {
            return ['id' => $id, 'deleted' => true]; // об'єкт уже видалено — віддаємо хоча б ідентифікатор
        }
        return match ($entityType) {
            'ticket' => ApiSerializer::ticket($row),
            'task' => ApiSerializer::task($row),
            default => ApiSerializer::project($row),
        };
    }

    /** @return array{id: int, full_name: string}|null */
    private static function actor(?int $userId): ?array
    {
        $user = $userId ? User::findById($userId) : null;
        return $user ? ['id' => (int) $user['id'], 'full_name' => $user['full_name']] : null;
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    // ------------------------------------------------------------------ відправка

    private static function registerFlush(): void
    {
        if (!self::$flushRegistered) {
            self::$flushRegistered = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /**
     * Відправляє доставки, створені в цьому запиті, ПІСЛЯ того, як відповідь пішла користувачу (PHP-FPM:
     * fastcgi_finish_request) — користувач не чекає на чужий сервер. Без FPM (mod_php) відправку не робимо: клієнт
     * чекав би до таймаутів одержувача, тож доставки лишаються в черзі для cron-скрипта bin/deliver-webhooks.php.
     */
    public static function flush(): void
    {
        if (!self::$pending) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (PHP_SAPI !== 'cli') {
            return;
        }
        $ids = self::$pending;
        self::$pending = [];
        $deadline = time() + 25;
        foreach ($ids as $id) {
            if (time() > $deadline) {
                break;
            }
            try {
                self::deliver($id);
            } catch (\Throwable $e) {
                error_log('[itsm] webhook deliver failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Відправляє доставку, якщо вона готова: «орендує» її (щоб не відправити двічі) й записує результат.
     *
     * @param bool $force true — ігнорувати розклад і стан ендпоінта (для тесту й ручного повтору з адмін-панелі)
     * @return array{status: ?int, error: ?string}|null null — доставку забрав інший процес або вона не готова
     */
    public static function deliver(int $deliveryId, bool $force = false): ?array
    {
        $pdo = Database::connection();
        $sql = "UPDATE webhook_deliveries d JOIN webhook_endpoints e ON e.id = d.endpoint_id
                SET d.next_attempt_at = DATE_ADD(NOW(), INTERVAL " . self::LEASE_SECONDS . " SECOND)
                WHERE d.id = :id AND d.status = 'pending'" . ($force ? '' : ' AND d.next_attempt_at <= NOW() AND e.is_active = 1');
        $claim = $pdo->prepare($sql);
        $claim->execute(['id' => $deliveryId]);
        if ($claim->rowCount() !== 1) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT d.*, e.url, e.secret, e.verify_tls, e.consecutive_failures FROM webhook_deliveries d JOIN webhook_endpoints e ON e.id = d.endpoint_id WHERE d.id = :id');
        $stmt->execute(['id' => $deliveryId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $timestamp = (string) time();
        $result = WebhookClient::post($row['url'], $row['payload'], [
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent' => 'ITSM-Webhook/1.0',
            'X-ITSM-Event' => $row['event'],
            'X-ITSM-Delivery' => (string) $row['id'],
            'X-ITSM-Event-Id' => $row['event_id'],
            'X-ITSM-Timestamp' => $timestamp,
            'X-ITSM-Signature' => 'sha256=' . self::sign($row['secret'], $timestamp, $row['payload']),
        ], (bool) $row['verify_tls'], WebhookEndpoint::allowPrivate());

        self::record($row, $result);
        return $result;
    }

    /** Підпис тіла: HMAC-SHA256 від «мітка_часу.тіло» ключем-секретом (hex). */
    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /** Записує результат спроби й вирішує, що далі: успіх, повтор, остаточна невдача, вимкнення ендпоінта. */
    private static function record(array $row, array $result): void
    {
        $pdo = Database::connection();
        $attempts = (int) $row['attempts'] + 1;
        $status = $result['status'];
        $ok = $status !== null && $status >= 200 && $status < 300;

        if ($ok) {
            $pdo->prepare("UPDATE webhook_deliveries SET status = 'success', attempts = :a, last_status_code = :code, last_error = NULL, delivered_at = NOW(), next_attempt_at = NULL WHERE id = :id")
                ->execute(['a' => $attempts, 'code' => $status, 'id' => $row['id']]);
            $pdo->prepare('UPDATE webhook_endpoints SET consecutive_failures = 0 WHERE id = :id')->execute(['id' => $row['endpoint_id']]);
            return;
        }

        $error = $result['error'] ?? ($status !== null && $status >= 300 && $status < 400
            ? "Одержувач відповів перенаправленням ({$status}); перенаправлення не виконуються."
            : "Одержувач відповів кодом {$status}.");
        $final = $attempts >= self::MAX_ATTEMPTS;
        $pdo->prepare(
            'UPDATE webhook_deliveries SET status = :st, attempts = :a, last_status_code = :code, last_error = :err,
                    next_attempt_at = ' . ($final ? 'NULL' : 'DATE_ADD(NOW(), INTERVAL :wait SECOND)') . ' WHERE id = :id'
        )->execute(array_filter([
            'st' => $final ? 'failed' : 'pending', 'a' => $attempts, 'code' => $status, 'err' => mb_substr($error, 0, 500),
            'wait' => $final ? null : (self::BACKOFF[$attempts] ?? 43200), 'id' => $row['id'],
        ], static fn($v, $k) => $k !== 'wait' || $v !== null, ARRAY_FILTER_USE_BOTH));

        $failures = (int) $row['consecutive_failures'] + 1;
        $pdo->prepare('UPDATE webhook_endpoints SET consecutive_failures = :n WHERE id = :id')->execute(['n' => $failures, 'id' => $row['endpoint_id']]);

        if ($status === 410) {
            self::disable((int) $row['endpoint_id'], 'Одержувач відповів 410 Gone — він більше не приймає вебхуки.');
        } elseif ($failures >= self::DISABLE_AFTER_FAILURES) {
            self::disable((int) $row['endpoint_id'], 'Автоматично вимкнено: ' . self::DISABLE_AFTER_FAILURES . ' невдалих доставок поспіль (остання причина: ' . mb_substr($error, 0, 120) . ').');
        }
    }

    private static function disable(int $endpointId, string $reason): void
    {
        Database::connection()->prepare('UPDATE webhook_endpoints SET is_active = 0, disabled_reason = :r WHERE id = :id AND is_active = 1')
            ->execute(['r' => mb_substr($reason, 0, 255), 'id' => $endpointId]);
        \App\Models\Audit::log('webhook_endpoint', $endpointId, 'auto_disabled', null, ['reason' => $reason]);
    }

    // ------------------------------------------------------------------ дії адміністратора

    /** Тестова подія «webhook.ping» на конкретний ендпоінт (навіть вимкнений), відправляється одразу. @return array{status: ?int, error: ?string} */
    public static function ping(int $endpointId, ?int $actorId): array
    {
        $payload = json_encode([
            'id' => self::uuid(), 'event' => 'webhook.ping', 'created_at' => date('c'), 'actor' => self::actor($actorId), 'via' => null,
            'data' => ['message' => 'Тестова подія з ITSM: якщо ви її бачите і підпис збігається — вебхук налаштовано правильно.'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO webhook_deliveries (endpoint_id, event, event_id, payload, status, next_attempt_at) VALUES (:e, 'webhook.ping', :id, :p, 'pending', NOW())")
            ->execute(['e' => $endpointId, 'id' => json_decode($payload, true)['id'], 'p' => $payload]);
        return self::deliver((int) $pdo->lastInsertId(), true) ?? ['status' => null, 'error' => 'Доставку не вдалося забрати в роботу.'];
    }

    /** Повторна відправка доставки (будь-якого стану, окрім успішної) з нуля. @return array{status: ?int, error: ?string}|null */
    public static function retry(int $deliveryId): ?array
    {
        $upd = Database::connection()->prepare(
            "UPDATE webhook_deliveries SET status = 'pending', attempts = 0, next_attempt_at = NOW(), last_error = NULL WHERE id = :id AND status <> 'success'"
        );
        $upd->execute(['id' => $deliveryId]);
        return $upd->rowCount() === 1 ? self::deliver($deliveryId, true) : null;
    }

    // ------------------------------------------------------------------ cron

    /** Відправляє всі доставки, термін яких настав. @return array{sent: int, ok: int} */
    public static function processDue(int $limit = 100): array
    {
        $ids = Database::connection()->query(
            "SELECT d.id FROM webhook_deliveries d JOIN webhook_endpoints e ON e.id = d.endpoint_id
             WHERE d.status = 'pending' AND d.next_attempt_at <= NOW() AND e.is_active = 1 ORDER BY d.id LIMIT " . (int) $limit
        )->fetchAll(\PDO::FETCH_COLUMN);
        $sent = $ok = 0;
        foreach ($ids as $id) {
            $result = self::deliver((int) $id);
            if ($result !== null) {
                $sent++;
                $ok += ($result['status'] !== null && $result['status'] >= 200 && $result['status'] < 300) ? 1 : 0;
            }
        }
        return ['sent' => $sent, 'ok' => $ok];
    }

    /** Прибирає старе: завершені доставки старші за 30 днів і «завислі» в очікуванні понад 3 дні (ендпоінт давно вимкнений). */
    public static function purge(): void
    {
        $pdo = Database::connection();
        $pdo->exec("UPDATE webhook_deliveries SET status = 'failed', next_attempt_at = NULL, last_error = 'Прострочено: ендпоінт не приймав доставку понад " . self::STALE_PENDING_DAYS . " дні.'
                    WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL " . self::STALE_PENDING_DAYS . " DAY)");
        $pdo->exec("DELETE FROM webhook_deliveries WHERE status <> 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL " . self::KEEP_DAYS . " DAY)");
    }

    /** @return array<int, array<string, mixed>> останні доставки ендпоінта */
    public static function recent(int $endpointId, int $limit = 100): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, event, event_id, status, attempts, next_attempt_at, last_status_code, last_error, created_at, delivered_at
             FROM webhook_deliveries WHERE endpoint_id = :e ORDER BY id DESC LIMIT ' . (int) $limit
        );
        $stmt->execute(['e' => $endpointId]);
        return $stmt->fetchAll();
    }

    public static function find(int $deliveryId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM webhook_deliveries WHERE id = :id');
        $stmt->execute(['id' => $deliveryId]);
        return $stmt->fetch() ?: null;
    }
}
