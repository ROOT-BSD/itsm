<?php

namespace App\Models;

use App\Core\Database;

class Project
{
    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT p.*, u.full_name AS created_by_name,
                    (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status_id NOT IN
                        (SELECT id FROM task_statuses WHERE is_closed = 1)) AS open_tasks_count
             FROM projects p
             JOIN users u ON u.id = p.created_by
             ORDER BY p.created_at DESC'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.*, u.full_name AS created_by_name
             FROM projects p JOIN users u ON u.id = p.created_by
             WHERE p.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $project = $stmt->fetch();
        return $project ?: null;
    }

    public static function create(string $name, ?string $description, string $visibility, int $createdBy, ?int $parentId = null): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO projects (parent_id, name, description, visibility, status, created_by)
             VALUES (:parent_id, :name, :description, :visibility, "active", :created_by)'
        );
        $stmt->execute([
            'parent_id' => $parentId,
            'name' => $name,
            'description' => $description,
            'visibility' => $visibility,
            'created_by' => $createdBy,
        ]);

        $projectId = (int) Database::connection()->lastInsertId();
        Audit::log('project', $projectId, 'created', $createdBy);
        return $projectId;
    }

    public static function updateStatus(int $id, string $status, int $userId): void
    {
        $stmt = Database::connection()->prepare('UPDATE projects SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
        Audit::log('project', $id, 'status_changed_to_' . $status, $userId);
    }

    /**
     * Повне видалення проєкту разом з усіма пов'язаними задачами, коментарями,
     * вкладеннями, обліком часу тощо (забезпечується ON DELETE CASCADE у схемі БД).
     * Незворотна операція — доступна лише адміністратору (перевірка в контролері).
     */
    public static function delete(int $id, int $userId): bool
    {
        $project = self::find($id);
        if (!$project) {
            return false;
        }

        // Аудит-запис лишаємо ДО видалення проєкту, оскільки після видалення
        // сам проєкт (і, за бажання, записи аудиту щодо нього) вже не існуватиме
        // як окрема сутність для довідки — тут фіксуємо сам факт і назву.
        Audit::log('project', $id, 'deleted', $userId, ['name' => $project['name']]);

        $stmt = Database::connection()->prepare('DELETE FROM projects WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return true;
    }
}
