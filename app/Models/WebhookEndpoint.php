<?php

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\WebhookClient;

/**
 * Адреси, на які система надсилає події. Керує ними лише адміністратор. Секрет (для підпису HMAC) лежить у БД відкрито —
 * він потрібен, щоб підписувати кожен запит, — тому показується ОДИН раз (при створенні чи заміні) і далі лише в закритому вигляді.
 */
class WebhookEndpoint
{
    /** Каталог подій: код → [група, опис]. Код — стабільний контракт для одержувачів: перейменовувати не можна. */
    public const EVENTS = [
        'ticket.created'          => ['Тікети', 'Створено тікет (веб, портал, лист або API)'],
        'ticket.status_changed'   => ['Тікети', 'Змінено статус тікета'],
        'ticket.assigned'         => ['Тікети', 'Призначено чи знято оператора тікета'],
        'ticket.comment_added'    => ['Тікети', 'Новий коментар у тікеті'],
        'ticket.rated'            => ['Тікети', 'Заявник оцінив вирішення (CSAT)'],
        'task.created'            => ['Задачі', 'Створено задачу'],
        'task.status_changed'     => ['Задачі', 'Змінено статус задачі'],
        'task.assigned'           => ['Задачі', 'Змінено виконавця задачі'],
        'task.updated'            => ['Задачі', 'Змінено опис, терміни, етап чи проєкт задачі'],
        'task.comment_added'      => ['Задачі', 'Новий коментар до задачі'],
        'task.deleted'            => ['Задачі', 'Видалено задачу'],
        'project.created'         => ['Проєкти', 'Створено проєкт'],
        'project.status_changed'  => ['Проєкти', 'Змінено статус проєкту'],
        'project.responsible_changed' => ['Проєкти', 'Змінено відповідального за проєкт'],
        'project.member_added'    => ['Проєкти', 'Користувачу надано доступ до проєкту'],
        'project.member_removed'  => ['Проєкти', 'У користувача забрано доступ до проєкту'],
        'project.deleted'         => ['Проєкти', 'Видалено проєкт'],
    ];

    public static function available(): bool
    {
        return Database::tableExists('webhook_endpoints') && Database::tableExists('webhook_deliveries');
    }

    /** Чи дозволені вебхуки на внутрішні адреси (за замовчуванням так; WEBHOOK_ALLOW_PRIVATE=false забороняє). */
    public static function allowPrivate(): bool
    {
        return (bool) Config::get('webhooks.allow_private', true);
    }

    public static function newSecret(): string
    {
        return 'whsec_' . bin2hex(random_bytes(24));
    }

    /**
     * Перевіряє дані ендпоінта. @param string[] $events
     * @return array<string, string> помилки за полями (порожньо — все гаразд)
     */
    public static function validate(string $name, string $url, array $events): array
    {
        $errors = [];
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Вкажіть назву (до 100 символів).';
        }
        $parts = parse_url($url);
        if ($url === '' || mb_strlen($url) > 500 || !$parts || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            $errors['url'] = 'Потрібна адреса виду https://хост/шлях (http або https, без логіна й пароля, до 500 символів).';
        } elseif (filter_var(trim($parts['host'], '[]'), FILTER_VALIDATE_IP)) {
            $why = WebhookClient::blockedReason(trim($parts['host'], '[]'), self::allowPrivate());
            if ($why !== null) {
                $errors['url'] = "Цю адресу використовувати не можна: {$why}.";
            }
        }
        if (!$events) {
            $errors['events'] = 'Оберіть хоча б одну подію (або «Усі події»).';
        } elseif ($events !== ['*'] && array_diff($events, array_keys(self::EVENTS))) {
            $errors['events'] = 'Невідома подія у списку.';
        }
        return $errors;
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return Database::connection()->query(
            "SELECT e.*,
                    (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.endpoint_id = e.id AND d.status = 'pending') AS pending_count,
                    (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.endpoint_id = e.id AND d.status = 'failed') AS failed_count,
                    (SELECT MAX(d.delivered_at) FROM webhook_deliveries d WHERE d.endpoint_id = e.id AND d.status = 'success') AS last_success_at
             FROM webhook_endpoints e ORDER BY e.id"
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM webhook_endpoints WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @param string[] $events @return array{id: int, secret: string} секрет показується один раз */
    public static function create(string $name, string $url, array $events, bool $verifyTls, int $actingUserId): array
    {
        $secret = self::newSecret();
        $stmt = Database::connection()->prepare(
            'INSERT INTO webhook_endpoints (name, url, secret, events, verify_tls, created_by) VALUES (:name, :url, :secret, :events, :tls, :user)'
        );
        $stmt->execute(['name' => $name, 'url' => $url, 'secret' => $secret, 'events' => self::encodeEvents($events), 'tls' => $verifyTls ? 1 : 0, 'user' => $actingUserId]);
        $id = (int) Database::connection()->lastInsertId();
        Audit::log('webhook_endpoint', $id, 'created', $actingUserId, ['name' => $name, 'url' => $url]);
        return ['id' => $id, 'secret' => $secret];
    }

    /** @param string[] $events */
    public static function update(int $id, string $name, string $url, array $events, bool $verifyTls, int $actingUserId): void
    {
        Database::connection()->prepare(
            'UPDATE webhook_endpoints SET name = :name, url = :url, events = :events, verify_tls = :tls WHERE id = :id'
        )->execute(['name' => $name, 'url' => $url, 'events' => self::encodeEvents($events), 'tls' => $verifyTls ? 1 : 0, 'id' => $id]);
        Audit::log('webhook_endpoint', $id, 'updated', $actingUserId, ['name' => $name, 'url' => $url]);
    }

    public static function setActive(int $id, bool $active, ?string $reason, int $actingUserId): void
    {
        Database::connection()->prepare(
            'UPDATE webhook_endpoints SET is_active = :a, disabled_reason = :r, consecutive_failures = IF(:a2 = 1, 0, consecutive_failures) WHERE id = :id'
        )->execute(['a' => $active ? 1 : 0, 'a2' => $active ? 1 : 0, 'r' => $active ? null : $reason, 'id' => $id]);
        Audit::log('webhook_endpoint', $id, $active ? 'enabled' : 'disabled', $actingUserId, $reason ? ['reason' => $reason] : null);
    }

    /** @return string новий секрет (показується один раз) */
    public static function rotateSecret(int $id, int $actingUserId): string
    {
        $secret = self::newSecret();
        Database::connection()->prepare('UPDATE webhook_endpoints SET secret = :s WHERE id = :id')->execute(['s' => $secret, 'id' => $id]);
        Audit::log('webhook_endpoint', $id, 'secret_rotated', $actingUserId);
        return $secret;
    }

    public static function delete(int $id, string $name, int $actingUserId): void
    {
        Database::connection()->prepare('DELETE FROM webhook_endpoints WHERE id = :id')->execute(['id' => $id]);
        Audit::log('webhook_endpoint', $id, 'deleted', $actingUserId, ['name' => $name]);
    }

    /** Активні ендпоінти, підписані на подію. @return array<int, array<string, mixed>> */
    public static function activeFor(string $event): array
    {
        $rows = Database::connection()->query('SELECT * FROM webhook_endpoints WHERE is_active = 1')->fetchAll();
        return array_values(array_filter($rows, static fn(array $e): bool => self::subscribes($e['events'], $event)));
    }

    public static function subscribes(string $stored, string $event): bool
    {
        $list = self::decodeEvents($stored);
        return in_array('*', $list, true) || in_array($event, $list, true);
    }

    /** @param string[] $events */
    private static function encodeEvents(array $events): string
    {
        return $events === ['*'] ? '*' : implode(',', array_values(array_unique($events)));
    }

    /** @return string[] */
    public static function decodeEvents(string $stored): array
    {
        return $stored === '*' ? ['*'] : array_values(array_filter(explode(',', $stored)));
    }

    /** Секрет у закритому вигляді для показу: лише кінець, щоб відрізняти секрети один від одного. */
    public static function maskSecret(string $secret): string
    {
        return 'whsec_••••••••' . substr($secret, -4);
    }
}
