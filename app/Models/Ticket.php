<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Unit;
use App\Services\NotificationService;

/**
 * Мінімальна реалізація Service Desk (Епік 12): створення тікета,
 * список, перегляд, призначення оператора з наявних користувачів.
 * Повний портал самообслуговування, SLA-ескалація, email-to-ticket
 * тощо — окремі майбутні кроки (див. DEV_START-документ, Епік 12).
 */
class Ticket
{
    /** Допустимі статуси тікета (єдиний перелік для веб-форми, REST API й вебхуків). */
    public const STATUSES = ['new', 'in_progress', 'waiting_customer', 'resolved', 'closed'];

    public static function queues(): array
    {
        return Database::connection()->query('SELECT * FROM ticket_queues ORDER BY id')->fetchAll();
    }

    /** Нормативи SLA по кожній черзі — [queue_id => ['first_response_minutes' => .., 'resolution_minutes' => ..]]. Один запит замість одного на кожен тікет. */
    public static function slaPoliciesByQueue(): array
    {
        $rows = Database::connection()->query('SELECT * FROM sla_policies')->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['queue_id']] = $row;
        }
        return $map;
    }

    /**
     * SLA-статус одного тікета відносно нормативу його черги — без звернення
     * до БД (нормативи вже передані з slaPoliciesByQueue()). Прострочення
     * рахується лише для ще не закритого тікета:
     *  - response_overdue: минув норматив першої відповіді, а відповіді ще не було;
     *  - resolution_overdue: минув норматив вирішення.
     */
    public static function slaStatus(array $ticket, array $policiesByQueue): array
    {
        $none = ['has_policy' => false, 'response_overdue' => false, 'resolution_overdue' => false, 'response_due_at' => null, 'resolution_due_at' => null];

        $policy = $policiesByQueue[(int) $ticket['queue_id']] ?? null;
        if (!$policy || in_array($ticket['status'], ['resolved', 'closed'], true)) {
            return $none;
        }

        $createdAt = strtotime($ticket['created_at']);
        $now = time();
        $responseDueAt = $createdAt + ((int) $policy['first_response_minutes']) * 60;
        $resolutionDueAt = $createdAt + ((int) $policy['resolution_minutes']) * 60;

        return [
            'has_policy' => true,
            'response_overdue' => empty($ticket['first_response_at']) && $now > $responseDueAt,
            'resolution_overdue' => $now > $resolutionDueAt,
            'response_due_at' => date('Y-m-d H:i', $responseDueAt),
            'resolution_due_at' => date('Y-m-d H:i', $resolutionDueAt),
        ];
    }

    /** Черги з кількістю тікетів у кожній — для списку в адмін-панелі. */
    /** Черги з кількістю тікетів і поточним SLA-нормативом (NULL, якщо ще не налаштований) — для сторінки керування чергами. */
    public static function queuesWithTicketCount(): array
    {
        return Database::connection()->query(
            "SELECT q.*, COUNT(t.id) AS tickets_count,
                    sp.first_response_minutes, sp.resolution_minutes,
                    op.full_name AS default_operator_name
             FROM ticket_queues q
             LEFT JOIN tickets t ON t.queue_id = q.id
             LEFT JOIN sla_policies sp ON sp.queue_id = q.id
             LEFT JOIN users op ON op.id = q.default_operator_id
             GROUP BY q.id, sp.first_response_minutes, sp.resolution_minutes, op.full_name
             ORDER BY q.id"
        )->fetchAll();
    }

    /** Автопризначення: якщо в черги є default_operator_id, новий тікет одразу отримує цього оператора. */
    public static function defaultOperatorForQueue(int $queueId): ?int
    {
        $stmt = Database::connection()->prepare('SELECT default_operator_id FROM ticket_queues WHERE id = :id');
        $stmt->execute(['id' => $queueId]);
        $value = $stmt->fetchColumn();
        return ($value !== false && $value !== null) ? (int) $value : null;
    }

    /** Оновлення автопризначеного оператора для черги (Адмін-панель → Керування → Черги тікетів). */
    public static function updateQueueDefaultOperator(int $queueId, ?int $operatorId, int $actingUserId): void
    {
        $stmt = Database::connection()->prepare('UPDATE ticket_queues SET default_operator_id = :operator_id WHERE id = :id');
        $stmt->execute(['operator_id' => $operatorId ?: null, 'id' => $queueId]);
        Audit::log('ticket_queue', $queueId, 'default_operator_changed', $actingUserId, ['default_operator_id' => $operatorId]);
    }

    /** Створює норматив SLA для черги, якщо його ще немає, або оновлює наявний — один рядок на чергу (queue_id UNIQUE). */
    public static function upsertSlaPolicy(int $queueId, int $firstResponseMinutes, int $resolutionMinutes): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO sla_policies (queue_id, first_response_minutes, resolution_minutes)
             VALUES (:queue_id, :first_response_minutes, :resolution_minutes)
             ON DUPLICATE KEY UPDATE
                first_response_minutes = VALUES(first_response_minutes),
                resolution_minutes = VALUES(resolution_minutes)'
        );
        $stmt->execute([
            'queue_id' => $queueId,
            'first_response_minutes' => $firstResponseMinutes,
            'resolution_minutes' => $resolutionMinutes,
        ]);
    }

    public static function createQueue(string $name, ?string $description, int $actingUserId): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO ticket_queues (name, description) VALUES (:name, :description)'
        );
        $stmt->execute(['name' => $name, 'description' => $description]);

        $queueId = (int) Database::connection()->lastInsertId();
        Audit::log('ticket_queue', $queueId, 'created', $actingUserId, ['name' => $name]);
        return $queueId;
    }

    public static function queueNameExists(string $name): bool
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM ticket_queues WHERE name = :name');
        $stmt->execute(['name' => $name]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function all(): array
    {
        return Database::connection()->query(
            "SELECT t.*, q.name AS queue_name, op.full_name AS operator_name, p.name AS project_name
             FROM tickets t
             JOIN ticket_queues q ON q.id = t.queue_id
             LEFT JOIN users op ON op.id = t.assigned_operator_id
             LEFT JOIN projects p ON p.id = t.project_id
             ORDER BY t.created_at DESC"
        )->fetchAll();
    }

    /**
     * Тікети, видимі конкретному користувачу: адміністратор бачить усі,
     * решта — лише ті, де вони заявник (requester_user_id) або призначений
     * оператор (assigned_operator_id).
     */
    /**
     * @param bool $canSeeUnassigned Оператори служби підтримки й керівники ІТ-підрозділу додатково бачать
     *                                непризначені тікети (без оператора) — щоб було що брати в роботу,
     *                                а не лише свої вже призначені. Для адміна не має значення — він бачить усе.
     */
    public static function allVisibleTo(int $userId, bool $isAdmin, bool $canSeeUnassigned = false): array
    {
        if ($isAdmin) {
            return self::all();
        }

        $params = ['uid1' => $userId, 'uid2' => $userId];
        $extra = '';
        // Адміністратор підрозділу додатково бачить тікети, де заявник або оператор — з його AD OU.
        foreach ([['t.requester_user_id', 'tua'], ['t.assigned_operator_id', 'tub']] as [$col, $pfx]) {
            if (($c = Unit::userCondition($col, $pfx, $userId)) !== null) {
                $extra .= ' OR ' . $c[0];
                $params += $c[1];
            }
        }

        $sql = "SELECT t.*, q.name AS queue_name, op.full_name AS operator_name, p.name AS project_name
             FROM tickets t
             JOIN ticket_queues q ON q.id = t.queue_id
             LEFT JOIN users op ON op.id = t.assigned_operator_id
             LEFT JOIN projects p ON p.id = t.project_id
             WHERE t.requester_user_id = :uid1 OR t.assigned_operator_id = :uid2"
            . ($canSeeUnassigned ? ' OR t.assigned_operator_id IS NULL' : '')
            . $extra
            . ' ORDER BY t.created_at DESC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Те саме, що allVisibleTo(), але без закритих тікетів — для головного списку `/tickets`. Закриті — на окремій сторінці «Архів». */
    public static function allOpenVisibleTo(int $userId, bool $isAdmin, bool $canSeeUnassigned = false): array
    {
        return array_values(array_filter(
            self::allVisibleTo($userId, $isAdmin, $canSeeUnassigned),
            fn(array $t): bool => $t['status'] !== 'closed'
        ));
    }

    /** Лише закриті тікети, видимі цьому користувачу — для сторінки «Архів». */
    public static function allClosedVisibleTo(int $userId, bool $isAdmin, bool $canSeeUnassigned = false): array
    {
        return array_values(array_filter(
            self::allVisibleTo($userId, $isAdmin, $canSeeUnassigned),
            fn(array $t): bool => $t['status'] === 'closed'
        ));
    }

    /** Чи бачить цей користувач цей тікет: адмін / заявник / призначений оператор / (якщо дозволено) непризначений тікет. */
    public static function isVisibleTo(array $ticket, int $userId, bool $isAdmin, bool $canSeeUnassigned = false): bool
    {
        if ($isAdmin) {
            return true;
        }
        if ($canSeeUnassigned && empty($ticket['assigned_operator_id'])) {
            return true;
        }
        if ((int) ($ticket['requester_user_id'] ?? 0) === $userId
            || (int) ($ticket['assigned_operator_id'] ?? 0) === $userId) {
            return true;
        }
        // Адміністратор підрозділу: заявник або оператор тікета — з його AD OU.
        return Unit::containsUser($userId, (int) ($ticket['requester_user_id'] ?? 0))
            || Unit::containsUser($userId, (int) ($ticket['assigned_operator_id'] ?? 0));
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT t.*, q.name AS queue_name, op.full_name AS operator_name, p.name AS project_name
             FROM tickets t
             JOIN ticket_queues q ON q.id = t.queue_id
             LEFT JOIN users op ON op.id = t.assigned_operator_id
             LEFT JOIN projects p ON p.id = t.project_id
             WHERE t.id = :id"
        );
        $stmt->execute(['id' => $id]);
        $ticket = $stmt->fetch();
        return $ticket ?: null;
    }

    /** Тікети, прив'язані до конкретного проєкту — для розділу «Пов'язані тікети» на сторінці проєкту. */
    /** Тікети, прив'язані до конкретного проєкту — для розділу «Пов'язані тікети» на сторінці проєкту. Закриті приховані — дивіться сторінку «Архів». */
    public static function forProject(int $projectId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT t.*, q.name AS queue_name, op.full_name AS operator_name
             FROM tickets t
             JOIN ticket_queues q ON q.id = t.queue_id
             LEFT JOIN users op ON op.id = t.assigned_operator_id
             WHERE t.project_id = :project_id AND t.status != 'closed'
             ORDER BY t.created_at DESC"
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    public static function create(int $queueId, string $requesterName, string $requesterEmail, ?int $requesterUserId, string $subject, ?string $description, ?int $projectId = null): int
    {
        // Автопризначення: якщо в черги налаштований оператор за замовчуванням — новий тікет одразу його отримує.
        // Той самий Ticket::create() використовують усі три шляхи створення тікета (звичайна форма, портал
        // самообслуговування, email-to-ticket), тож автопризначення працює однаково для всіх трьох.
        $defaultOperatorId = self::defaultOperatorForQueue($queueId);

        $stmt = Database::connection()->prepare(
            'INSERT INTO tickets (queue_id, project_id, access_token, requester_name, requester_email, requester_user_id, subject, description, status, assigned_operator_id)
             VALUES (:queue_id, :project_id, :access_token, :requester_name, :requester_email, :requester_user_id, :subject, :description, "new", :assigned_operator_id)'
        );
        $stmt->execute([
            'queue_id' => $queueId,
            'project_id' => $projectId,
            'access_token' => bin2hex(random_bytes(24)),
            'requester_name' => $requesterName,
            'requester_email' => $requesterEmail,
            'requester_user_id' => $requesterUserId,
            'subject' => $subject,
            'description' => $description,
            'assigned_operator_id' => $defaultOperatorId,
        ]);

        $ticketId = (int) Database::connection()->lastInsertId();
        Audit::log('ticket', $ticketId, 'created', $requesterUserId);
        if ($defaultOperatorId !== null) {
            Audit::log('ticket', $ticketId, 'auto_assigned_operator', null, ['assigned_operator_id' => $defaultOperatorId]);
        }
        return $ticketId;
    }

    /** Тікет за токеном доступу — для порталу самообслуговування (відстеження без входу в систему). */
    public static function findByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $stmt = Database::connection()->prepare(
            "SELECT t.*, q.name AS queue_name, op.full_name AS operator_name, p.name AS project_name
             FROM tickets t
             JOIN ticket_queues q ON q.id = t.queue_id
             LEFT JOIN users op ON op.id = t.assigned_operator_id
             LEFT JOIN projects p ON p.id = t.project_id
             WHERE t.access_token = :token"
        );
        $stmt->execute(['token' => $token]);
        $ticket = $stmt->fetch();
        return $ticket ?: null;
    }

    /** Оцінка якості обслуговування (1–5) від заявника — лише для вже вирішеного/закритого тікета, і лише один раз. */
    /** @param int|null $actingUserId null для анонімного заявника (портал самообслуговування), інакше його user_id.
     *  @return bool true, якщо оцінку справді застосовано (тікет був вирішений/закритий і ще не оцінений). */
    public static function submitCsat(int $id, int $score, ?int $actingUserId = null): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE tickets SET csat_score = :score
             WHERE id = :id AND status IN ('resolved','closed') AND csat_score IS NULL"
        );
        $stmt->execute(['score' => $score, 'id' => $id]);
        $applied = $stmt->rowCount() > 0;
        if ($applied) {
            Audit::log('ticket', $id, 'csat_submitted', $actingUserId, ['csat_score' => $score]);
        }
        return $applied;
    }

    /**
     * Додаткова умова «тікет підрозділу» (заявник або оператор — з AD OU / підрозділу адміністратора підрозділу).
     * Без $unitAdminId — без обмежень (адмін-звіт); якщо підрозділ не визначено — «нічого», а не «все».
     * @return array{0: string, 1: array<string, string>}
     */
    public static function unitWhere(string $alias, ?int $unitAdminId, string $prefix = 'cu'): array
    {
        if ($unitAdminId === null) {
            return ['', []];
        }
        $a = Unit::userCondition("{$alias}.requester_user_id", "{$prefix}a", $unitAdminId);
        $b = Unit::userCondition("{$alias}.assigned_operator_id", "{$prefix}b", $unitAdminId);
        if ($a === null || $b === null) {
            return [' AND 1 = 0', []];
        }
        return [" AND ({$a[0]} OR {$b[0]})", $a[1] + $b[1]];
    }

    /** Загальна картина по зібраних CSAT-оцінках — для адмін-звіту (з $unitAdminId — лише тікети підрозділу). */
    public static function csatSummary(?int $unitAdminId = null): array
    {
        [$w, $p] = self::unitWhere('t', $unitAdminId);
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) AS total, AVG(csat_score) AS avg_score,
                    COALESCE(SUM(csat_score = 1),0) AS c1, COALESCE(SUM(csat_score = 2),0) AS c2, COALESCE(SUM(csat_score = 3),0) AS c3,
                    COALESCE(SUM(csat_score = 4),0) AS c4, COALESCE(SUM(csat_score = 5),0) AS c5
             FROM tickets t WHERE t.csat_score IS NOT NULL{$w}"
        );
        $stmt->execute($p);
        $row = $stmt->fetch();
        $row['avg_score'] = $row['avg_score'] !== null ? round((float) $row['avg_score'], 2) : null;
        return $row;
    }

    /** Середня оцінка по кожній черзі, де є хоч одна оцінка. */
    public static function csatByQueue(?int $unitAdminId = null): array
    {
        [$w, $p] = self::unitWhere('t', $unitAdminId);
        $stmt = Database::connection()->prepare(
            "SELECT q.name AS queue_name, COUNT(*) AS total, ROUND(AVG(t.csat_score), 2) AS avg_score
             FROM tickets t
             JOIN ticket_queues q ON q.id = t.queue_id
             WHERE t.csat_score IS NOT NULL{$w}
             GROUP BY q.id, q.name
             ORDER BY avg_score DESC"
        );
        $stmt->execute($p);
        return $stmt->fetchAll();
    }

    /** Середня оцінка по кожному оператору, якому призначались оцінені тікети. */
    public static function csatByOperator(?int $unitAdminId = null): array
    {
        [$w, $p] = self::unitWhere('t', $unitAdminId);
        $stmt = Database::connection()->prepare(
            "SELECT u.full_name AS operator_name, COUNT(*) AS total, ROUND(AVG(t.csat_score), 2) AS avg_score
             FROM tickets t
             JOIN users u ON u.id = t.assigned_operator_id
             WHERE t.csat_score IS NOT NULL{$w}
             GROUP BY u.id, u.full_name
             ORDER BY avg_score DESC"
        );
        $stmt->execute($p);
        return $stmt->fetchAll();
    }

    /** Останні оцінені тікети — для таблиці в адмін-звіті. */
    public static function recentCsatRatings(int $limit = 50, ?int $unitAdminId = null): array
    {
        [$w, $p] = self::unitWhere('t', $unitAdminId);
        $stmt = Database::connection()->prepare(
            "SELECT t.id, t.subject, t.csat_score, t.updated_at, q.name AS queue_name, op.full_name AS operator_name
             FROM tickets t
             JOIN ticket_queues q ON q.id = t.queue_id
             LEFT JOIN users op ON op.id = t.assigned_operator_id
             WHERE t.csat_score IS NOT NULL{$w}
             ORDER BY t.updated_at DESC
             LIMIT :limit"
        );
        foreach ($p as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Черги з кількістю тікетів підрозділу: усього / відкритих / закритих / прострочених за SLA не рахуємо (див. SLA на тікеті). */
    public static function queuesForUnit(int $unitAdminId): array
    {
        [$w, $p] = self::unitWhere('t', $unitAdminId);
        $stmt = Database::connection()->prepare(
            "SELECT q.id, q.name, q.description,
                    COUNT(t.id) AS total,
                    COALESCE(SUM(t.status <> 'closed'), 0) AS open_count,
                    COALESCE(SUM(t.status = 'closed'), 0) AS closed_count,
                    COALESCE(SUM(t.status <> 'closed' AND t.assigned_operator_id IS NULL), 0) AS unassigned_count,
                    sp.first_response_minutes, sp.resolution_minutes,
                    op.full_name AS default_operator_name
             FROM ticket_queues q
             LEFT JOIN tickets t ON t.queue_id = q.id{$w}
             LEFT JOIN sla_policies sp ON sp.queue_id = q.id
             LEFT JOIN users op ON op.id = q.default_operator_id
             GROUP BY q.id, q.name, q.description, sp.first_response_minutes, sp.resolution_minutes, op.full_name
             ORDER BY q.id"
        );
        $stmt->execute($p);
        return $stmt->fetchAll();
    }

    /** Призначення оператора з наявних користувачів (виконавця тікета). */
    public static function assignOperator(int $id, ?int $operatorId, int $actingUserId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE tickets SET assigned_operator_id = :operator_id WHERE id = :id'
        );
        $stmt->execute(['operator_id' => $operatorId ?: null, 'id' => $id]);
        Audit::log('ticket', $id, 'operator_assigned', $actingUserId, ['operator_id' => $operatorId]);
    }

    public static function updateStatus(int $id, string $status, int $actingUserId): void
    {
        $fields = ['status' => $status];
        $sql = 'UPDATE tickets SET status = :status';

        // Фіксуємо час вирішення при переході у "resolved"/"closed" (для майбутньої SLA-звітності)
        if (in_array($status, ['resolved', 'closed'], true)) {
            $sql .= ', resolved_at = COALESCE(resolved_at, NOW())';
        }
        $sql .= ' WHERE id = :id';
        $fields['id'] = $id;

        Database::connection()->prepare($sql)->execute($fields);
        Audit::log('ticket', $id, 'status_changed_to_' . $status, $actingUserId);
        NotificationService::ticketStatusChanged($id, $status, $actingUserId);
    }

    public static function addComment(int $ticketId, string $authorType, ?int $authorId, string $body): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO ticket_comments (ticket_id, author_type, author_id, body)
             VALUES (:ticket_id, :author_type, :author_id, :body)'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'author_type' => $authorType,
            'author_id' => $authorId,
            'body' => $body,
        ]);
        $commentId = (int) Database::connection()->lastInsertId();

        // Перша відповідь оператора — фіксуємо час для майбутньої SLA-звітності
        if ($authorType === 'operator') {
            Database::connection()->prepare(
                'UPDATE tickets SET first_response_at = COALESCE(first_response_at, NOW()) WHERE id = :id'
            )->execute(['id' => $ticketId]);
        }

        NotificationService::ticketCommentAdded($ticketId, $authorType, $authorId);
        \App\Services\WebhookService::emitTicketComment($ticketId, $commentId, $authorId);
    }

    public static function comments(int $ticketId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT c.*, u.full_name AS author_name
             FROM ticket_comments c
             LEFT JOIN users u ON u.id = c.author_id
             WHERE c.ticket_id = :ticket_id
             ORDER BY c.created_at ASC"
        );
        $stmt->execute(['ticket_id' => $ticketId]);
        return $stmt->fetchAll();
    }
}
