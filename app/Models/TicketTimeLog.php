<?php

namespace App\Models;

use App\Core\Database;

/**
 * Облік часу по тікетах (міграція 044): години, витрачені персоналом підтримки на тікет.
 */
class TicketTimeLog
{
    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                Database::connection()->query('SELECT 1 FROM ticket_time_logs LIMIT 1');
                $ready = true;
            } catch (\Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    public static function add(int $ticketId, int $userId, float $hours, string $date, ?string $category, ?string $comment): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO ticket_time_logs (ticket_id, user_id, activity_category, hours, log_date, comment)
             VALUES (:t, :u, :c, :h, :d, :m)'
        );
        $stmt->execute(['t' => $ticketId, 'u' => $userId, 'c' => $category ?: null, 'h' => $hours, 'd' => $date, 'm' => $comment ?: null]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM ticket_time_logs WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM ticket_time_logs WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return array[] записи часу тікета (нові зверху) з іменем користувача */
    public static function forTicket(int $ticketId): array
    {
        if (!self::ready()) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT l.*, u.full_name AS user_name FROM ticket_time_logs l JOIN users u ON u.id = l.user_id
             WHERE l.ticket_id = :t ORDER BY l.log_date DESC, l.id DESC'
        );
        $stmt->execute(['t' => $ticketId]);
        return $stmt->fetchAll();
    }

    /**
     * Рядки обліку часу по тікетах за період — у тому ж вигляді, що й рядки задач у звіті обліку часу
     * (log_date, user_name, activity_category, hours, project_id/project_name, task_title) плюс ticket_id і kind = 'ticket'.
     *
     * Видимість: адміністратор системи — усе; адміністратор підрозділу — тікети підрозділу ({@see Ticket::unitWhere});
     * інші ролі — лише власні записи.
     *
     * @param array{project_id?: ?int, log_user_id?: ?int, category?: ?string} $filters
     */
    public static function reportRows(string $dateFrom, string $dateTo, array $filters, int $viewerId, ?string $role): array
    {
        if (!self::ready()) {
            return [];
        }
        $sql = "SELECT l.id AS log_id, l.log_date, l.hours, l.activity_category, l.comment, l.user_id, u.full_name AS user_name,
                       t.id AS ticket_id, t.subject, q.id AS queue_id, q.name AS queue_name, t.project_id AS ticket_project_id
                FROM ticket_time_logs l
                JOIN users u ON u.id = l.user_id
                JOIN tickets t ON t.id = l.ticket_id
                JOIN ticket_queues q ON q.id = t.queue_id
                WHERE l.log_date BETWEEN :df AND :dt";
        $params = ['df' => $dateFrom, 'dt' => $dateTo];
        if (!empty($filters['project_id'])) {
            $ids = Project::descendantIdsOf((int) $filters['project_id']);
            $ph = [];
            foreach ($ids as $i => $pid) {
                $ph[] = ":pid{$i}";
                $params["pid{$i}"] = $pid;
            }
            $sql .= ' AND t.project_id IN (' . implode(',', $ph) . ')';
        }
        if (!empty($filters['log_user_id'])) {
            $sql .= ' AND l.user_id = :lu';
            $params['lu'] = (int) $filters['log_user_id'];
        }
        if (!empty($filters['category'])) {
            $sql .= ' AND l.activity_category LIKE :cat';
            $params['cat'] = '%' . $filters['category'] . '%';
        }
        if ($role === 'admin') {
            // без обмежень
        } elseif ($role === 'unit_admin') {
            [$w, $p] = Ticket::unitWhere('t', $viewerId);
            $sql .= $w;
            $params += $p;
        } else {
            $sql .= ' AND l.user_id = :viewer';
            $params['viewer'] = $viewerId;
        }
        $sql .= ' ORDER BY l.log_date ASC, t.id ASC, l.id ASC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = $r + [
                'kind' => 'ticket',
                'task_id' => 0,
                'project_id' => -(int) $r['queue_id'],
                'project_name' => 'Тікети — ' . $r['queue_name'],
                'task_title' => '#' . $r['ticket_id'] . ' ' . $r['subject'],
            ];
        }
        return $out;
    }

    /** Години по тікетах (для сторінок «Облік часу»): [ticket_id, subject, queue_name, hours, entries]. */
    public static function hoursByTicket(?int $unitAdminId = null, int $limit = 200): array
    {
        if (!self::ready()) {
            return [];
        }
        [$w, $p] = Ticket::unitWhere('t', $unitAdminId);
        $stmt = Database::connection()->prepare(
            "SELECT t.id AS ticket_id, t.subject, q.name AS queue_name, SUM(l.hours) AS hours, COUNT(*) AS entries
             FROM ticket_time_logs l JOIN tickets t ON t.id = l.ticket_id JOIN ticket_queues q ON q.id = t.queue_id
             WHERE 1 = 1 {$w} GROUP BY t.id, t.subject, q.name ORDER BY hours DESC, t.id DESC LIMIT " . (int) $limit
        );
        $stmt->execute($p);
        return $stmt->fetchAll();
    }
}
