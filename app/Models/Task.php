<?php

namespace App\Models;

use App\Core\Database;

class Task
{
    public static function forProject(int $projectId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT t.*, ts.name AS status_name, ts.is_closed, tt.name AS type_name,
                    au.full_name AS author_name, asg.full_name AS assignee_name
             FROM tasks t
             JOIN task_statuses ts ON ts.id = t.status_id
             JOIN task_types tt ON tt.id = t.type_id
             JOIN users au ON au.id = t.author_id
             LEFT JOIN users asg ON asg.id = t.assignee_id
             WHERE t.project_id = :project_id
             ORDER BY t.created_at DESC'
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT t.*, ts.name AS status_name, tt.name AS type_name,
                    au.full_name AS author_name, asg.full_name AS assignee_name,
                    p.name AS project_name
             FROM tasks t
             JOIN task_statuses ts ON ts.id = t.status_id
             JOIN task_types tt ON tt.id = t.type_id
             JOIN users au ON au.id = t.author_id
             JOIN projects p ON p.id = t.project_id
             LEFT JOIN users asg ON asg.id = t.assignee_id
             WHERE t.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $task = $stmt->fetch();
        return $task ?: null;
    }

    public static function create(array $data): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO tasks (project_id, type_id, status_id, title, description, priority, author_id, assignee_id, due_date)
             VALUES (:project_id, :type_id, :status_id, :title, :description, :priority, :author_id, :assignee_id, :due_date)'
        );
        $stmt->execute([
            'project_id' => $data['project_id'],
            'type_id' => $data['type_id'],
            'status_id' => $data['status_id'], // за замовчуванням "new" визначається контролером
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? 'normal',
            'author_id' => $data['author_id'],
            'assignee_id' => $data['assignee_id'] ?: null,
            'due_date' => $data['due_date'] ?: null,
        ]);

        $taskId = (int) Database::connection()->lastInsertId();
        Audit::log('task', $taskId, 'created', $data['author_id']);
        return $taskId;
    }

    public static function updateStatus(int $id, int $statusId, int $userId): void
    {
        $stmt = Database::connection()->prepare('UPDATE tasks SET status_id = :status_id WHERE id = :id');
        $stmt->execute(['status_id' => $statusId, 'id' => $id]);
        Audit::log('task', $id, 'status_changed', $userId, ['status_id' => $statusId]);
    }

    public static function addComment(int $taskId, int $authorId, string $body): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO comments (task_id, author_id, body) VALUES (:task_id, :author_id, :body)'
        );
        $stmt->execute(['task_id' => $taskId, 'author_id' => $authorId, 'body' => $body]);
    }

    public static function comments(int $taskId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.*, u.full_name AS author_name
             FROM comments c JOIN users u ON u.id = c.author_id
             WHERE c.task_id = :task_id ORDER BY c.created_at ASC'
        );
        $stmt->execute(['task_id' => $taskId]);
        return $stmt->fetchAll();
    }

    public static function logTime(int $taskId, int $userId, float $hours, string $date, ?string $category, ?string $comment): void
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO time_logs (task_id, user_id, activity_category, hours, log_date, comment)
                 VALUES (:task_id, :user_id, :category, :hours, :log_date, :comment)'
            );
            $stmt->execute([
                'task_id' => $taskId, 'user_id' => $userId, 'category' => $category,
                'hours' => $hours, 'log_date' => $date, 'comment' => $comment,
            ]);
            $db->prepare('UPDATE tasks SET actual_hours = actual_hours + :hours WHERE id = :id')
                ->execute(['hours' => $hours, 'id' => $taskId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function types(): array
    {
        return Database::connection()->query('SELECT * FROM task_types ORDER BY id')->fetchAll();
    }

    public static function statuses(): array
    {
        return Database::connection()->query('SELECT * FROM task_statuses ORDER BY sort_order')->fetchAll();
    }
}
