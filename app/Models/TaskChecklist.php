<?php

namespace App\Models;

use App\Core\Database;

/**
 * Чек-лист задачі (міграція 038) і підзадачі. Усі запити тут і в Task::progressSelect() терпимі до відсутності
 * міграції: до її застосування функція просто не показується, а списки працюють як раніше.
 */
class TaskChecklist
{
    public const MAX_ITEMS = 100;
    public const MAX_LENGTH = 255;

    /** Чи застосована міграція 038 (є таблиця чек-листів і колонка батьківської задачі). */
    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $pdo = Database::connection();
            $n = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_checklist_items'"
            )->fetchColumn();
            $c = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'parent_task_id'"
            )->fetchColumn();
            $ready = $n > 0 && $c > 0;
        }
        return $ready;
    }

    /** @return array<int, array<string, mixed>> */
    public static function forTask(int $taskId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT i.*, u.full_name AS done_by_name FROM task_checklist_items i
             LEFT JOIN users u ON u.id = i.done_by
             WHERE i.task_id = :t ORDER BY i.id ASC'
        );
        $stmt->execute(['t' => $taskId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM task_checklist_items WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function count(int $taskId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM task_checklist_items WHERE task_id = :t');
        $stmt->execute(['t' => $taskId]);
        return (int) $stmt->fetchColumn();
    }

    public static function add(int $taskId, string $body, int $userId): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO task_checklist_items (task_id, body, created_by) VALUES (:t, :b, :u)'
        );
        $stmt->execute(['t' => $taskId, 'b' => $body, 'u' => $userId]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function setDone(int $id, bool $done, int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE task_checklist_items SET is_done = :d, done_by = :u, done_at = ' . ($done ? 'NOW()' : 'NULL') . ' WHERE id = :id'
        );
        $stmt->execute(['d' => $done ? 1 : 0, 'u' => $done ? $userId : null, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM task_checklist_items WHERE id = :id')->execute(['id' => $id]);
    }

    /** Видаляє всі виконані пункти задачі; повертає їх кількість. */
    public static function deleteDone(int $taskId): int
    {
        $stmt = Database::connection()->prepare('DELETE FROM task_checklist_items WHERE task_id = :t AND is_done = 1');
        $stmt->execute(['t' => $taskId]);
        return $stmt->rowCount();
    }

    /** Підзадачі задачі зі статусом і виконавцем. */
    public static function subtasks(int $taskId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT t.id, t.title, t.priority, t.due_date, ts.name AS status_name, ts.is_closed, asg.full_name AS assignee_name
             FROM tasks t
             JOIN task_statuses ts ON ts.id = t.status_id
             LEFT JOIN users asg ON asg.id = t.assignee_id
             WHERE t.parent_task_id = :id
             ORDER BY ts.is_closed ASC, t.id ASC'
        );
        $stmt->execute(['id' => $taskId]);
        return $stmt->fetchAll();
    }

    /** Кількість незакритих підзадач (для заборони закрити батьківську). */
    public static function openSubtaskCount(int $taskId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM tasks t JOIN task_statuses ts ON ts.id = t.status_id
             WHERE t.parent_task_id = :id AND ts.is_closed = 0'
        );
        $stmt->execute(['id' => $taskId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Додаткові стовпці для списків задач: прогрес підзадач і чек-листа. Порожній рядок, якщо міграції немає.
     * Починається з коми; псевдонім таблиці задач — $alias.
     */
    public static function progressSelect(string $alias = 't'): string
    {
        if (!self::ready()) {
            return '';
        }
        return ", (SELECT COUNT(*) FROM tasks sc WHERE sc.parent_task_id = {$alias}.id) AS subtasks_total,
                 (SELECT COUNT(*) FROM tasks sc JOIN task_statuses scs ON scs.id = sc.status_id
                   WHERE sc.parent_task_id = {$alias}.id AND scs.is_closed = 1) AS subtasks_done,
                 (SELECT COUNT(*) FROM task_checklist_items ci WHERE ci.task_id = {$alias}.id) AS checklist_total,
                 (SELECT COUNT(*) FROM task_checklist_items ci WHERE ci.task_id = {$alias}.id AND ci.is_done = 1) AS checklist_done";
    }
}
