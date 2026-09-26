<?php

namespace App\Models;

use App\Core\Database;

/**
 * Мінімальна реалізація Service Desk (Епік 12): створення тікета,
 * список, перегляд, призначення оператора з наявних користувачів.
 * Повний портал самообслуговування, SLA-ескалація, email-to-ticket
 * тощо — окремі майбутні кроки (див. DEV_START-документ, Епік 12).
 */
class Ticket
{
    public static function queues(): array
    {
        return Database::connection()->query('SELECT * FROM ticket_queues ORDER BY id')->fetchAll();
    }

    /** Черги з кількістю тікетів у кожній — для списку в адмін-панелі. */
    public static function queuesWithTicketCount(): array
    {
        return Database::connection()->query(
            "SELECT q.*, COUNT(t.id) AS tickets_count
             FROM ticket_queues q
             LEFT JOIN tickets t ON t.queue_id = q.id
             GROUP BY q.id
             ORDER BY q.id"
        )->fetchAll();
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
    public static function allVisibleTo(int $userId, bool $isAdmin): array
    {
        if ($isAdmin) {
            return self::all();
        }

        $stmt = Database::connection()->prepare(
            "SELECT t.*, q.name AS queue_name, op.full_name AS operator_name, p.name AS project_name
             FROM tickets t
             JOIN ticket_queues q ON q.id = t.queue_id
             LEFT JOIN users op ON op.id = t.assigned_operator_id
             LEFT JOIN projects p ON p.id = t.project_id
             WHERE t.requester_user_id = :uid1 OR t.assigned_operator_id = :uid2
             ORDER BY t.created_at DESC"
        );
        $stmt->execute(['uid1' => $userId, 'uid2' => $userId]);
        return $stmt->fetchAll();
    }

    /** Чи бачить цей користувач цей тікет: адмін / заявник / призначений оператор. */
    public static function isVisibleTo(array $ticket, int $userId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }
        return (int) ($ticket['requester_user_id'] ?? 0) === $userId
            || (int) ($ticket['assigned_operator_id'] ?? 0) === $userId;
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
    public static function forProject(int $projectId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT t.*, q.name AS queue_name, op.full_name AS operator_name
             FROM tickets t
             JOIN ticket_queues q ON q.id = t.queue_id
             LEFT JOIN users op ON op.id = t.assigned_operator_id
             WHERE t.project_id = :project_id
             ORDER BY t.created_at DESC"
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    public static function create(int $queueId, string $requesterName, string $requesterEmail, ?int $requesterUserId, string $subject, ?string $description, ?int $projectId = null): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO tickets (queue_id, project_id, requester_name, requester_email, requester_user_id, subject, description, status)
             VALUES (:queue_id, :project_id, :requester_name, :requester_email, :requester_user_id, :subject, :description, "new")'
        );
        $stmt->execute([
            'queue_id' => $queueId,
            'project_id' => $projectId,
            'requester_name' => $requesterName,
            'requester_email' => $requesterEmail,
            'requester_user_id' => $requesterUserId,
            'subject' => $subject,
            'description' => $description,
        ]);

        $ticketId = (int) Database::connection()->lastInsertId();
        Audit::log('ticket', $ticketId, 'created', $requesterUserId);
        return $ticketId;
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

        // Перша відповідь оператора — фіксуємо час для майбутньої SLA-звітності
        if ($authorType === 'operator') {
            Database::connection()->prepare(
                'UPDATE tickets SET first_response_at = COALESCE(first_response_at, NOW()) WHERE id = :id'
            )->execute(['id' => $ticketId]);
        }
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
