<?php

namespace App\Models;

use App\Core\Database;

class Project
{
    public static function all(): array
    {
        // Кількість відкритих задач рахується один раз через derived table (GROUP BY),
        // а не корельованим підзапитом на кожен рядок проєкту (який до того ж сам
        // містив вкладений підзапит по task_statuses) — суттєво дешевше при
        // зростанні кількості проєктів і задач.
        return Database::connection()->query(
            "SELECT p.*, u.full_name AS created_by_name, r.full_name AS responsible_name,
                    COALESCE(ot.open_count, 0) AS open_tasks_count
             FROM projects p
             JOIN users u ON u.id = p.created_by
             LEFT JOIN users r ON r.id = p.responsible_user_id
             LEFT JOIN (
                 SELECT t.project_id, COUNT(*) AS open_count
                 FROM tasks t
                 JOIN task_statuses ts ON ts.id = t.status_id AND ts.is_closed = 0
                 GROUP BY t.project_id
             ) ot ON ot.project_id = p.id
             ORDER BY p.created_at DESC"
        )->fetchAll();
    }

    /**
     * Проєкти, видимі конкретному користувачу: адміністратор бачить усі,
     * решта — лише ті, де вони автор (created_by) або відповідальний
     * (responsible_user_id). Використовується замість all() усюди, де
     * список показується не-адміну (список проєктів, дашборд).
     */
    public static function allVisibleTo(int $userId, bool $isAdmin): array
    {
        if ($isAdmin) {
            return self::all();
        }

        $stmt = Database::connection()->prepare(
            "SELECT p.*, u.full_name AS created_by_name, r.full_name AS responsible_name,
                    COALESCE(ot.open_count, 0) AS open_tasks_count
             FROM projects p
             JOIN users u ON u.id = p.created_by
             LEFT JOIN users r ON r.id = p.responsible_user_id
             LEFT JOIN (
                 SELECT t.project_id, COUNT(*) AS open_count
                 FROM tasks t
                 JOIN task_statuses ts ON ts.id = t.status_id AND ts.is_closed = 0
                 GROUP BY t.project_id
             ) ot ON ot.project_id = p.id
             WHERE p.created_by = :uid1 OR p.responsible_user_id = :uid2
             ORDER BY p.created_at DESC"
        );
        $stmt->execute(['uid1' => $userId, 'uid2' => $userId]);
        return $stmt->fetchAll();
    }

    /** Чи бачить цей користувач цей проєкт: адмін / автор / відповідальний. */
    public static function isVisibleTo(array $project, int $userId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }
        return (int) $project['created_by'] === $userId
            || (int) ($project['responsible_user_id'] ?? 0) === $userId;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT p.*, u.full_name AS created_by_name, r.full_name AS responsible_name
             FROM projects p
             JOIN users u ON u.id = p.created_by
             LEFT JOIN users r ON r.id = p.responsible_user_id
             WHERE p.id = :id"
        );
        $stmt->execute(['id' => $id]);
        $project = $stmt->fetch();
        return $project ?: null;
    }

    public static function create(string $name, ?string $description, string $visibility, int $createdBy, ?int $responsibleUserId = null, ?int $parentId = null): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO projects (parent_id, name, description, visibility, status, created_by, responsible_user_id)
             VALUES (:parent_id, :name, :description, :visibility, "active", :created_by, :responsible_user_id)'
        );
        $stmt->execute([
            'parent_id' => $parentId,
            'name' => $name,
            'description' => $description,
            'visibility' => $visibility,
            'created_by' => $createdBy,
            'responsible_user_id' => $responsibleUserId ?: null,
        ]);

        $projectId = (int) Database::connection()->lastInsertId();
        Audit::log('project', $projectId, 'created', $createdBy);
        return $projectId;
    }

    public static function updateResponsible(int $id, ?int $responsibleUserId, int $actingUserId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE projects SET responsible_user_id = :responsible_user_id WHERE id = :id'
        );
        $stmt->execute(['responsible_user_id' => $responsibleUserId ?: null, 'id' => $id]);
        Audit::log('project', $id, 'responsible_changed', $actingUserId, ['responsible_user_id' => $responsibleUserId]);
    }

    public static function updateVisibility(int $id, string $visibility, int $actingUserId): void
    {
        $stmt = Database::connection()->prepare('UPDATE projects SET visibility = :visibility WHERE id = :id');
        $stmt->execute(['visibility' => $visibility, 'id' => $id]);
        Audit::log('project', $id, 'visibility_changed_to_' . $visibility, $actingUserId);
    }

    /** Статус проєкту: активний / архівний / закритий — впливає лише на позначку в списку, не приховує сам проєкт. */
    public static function updateStatus(int $id, string $status, int $actingUserId): void
    {
        $stmt = Database::connection()->prepare('UPDATE projects SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
        Audit::log('project', $id, 'status_changed_to_' . $status, $actingUserId);
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
