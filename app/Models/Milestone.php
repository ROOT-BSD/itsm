<?php

namespace App\Models;

use App\Core\Database;

class Milestone
{
    /** Усі етапи проєкту, впорядковані для дорожньої карти. */
    public static function forProject(int $projectId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM milestones WHERE project_id = :project_id ORDER BY sort_order ASC, target_date IS NULL, target_date ASC, id ASC'
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM milestones WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $milestone = $stmt->fetch();
        return $milestone ?: null;
    }

    public static function create(int $projectId, string $title, ?string $description, ?string $targetDate, int $actingUserId): int
    {
        // Новий етап додається в кінець списку (наступний sort_order у межах проєкту).
        $stmt = Database::connection()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM milestones WHERE project_id = :project_id');
        $stmt->execute(['project_id' => $projectId]);
        $nextOrder = (int) $stmt->fetchColumn();

        $stmt = Database::connection()->prepare(
            'INSERT INTO milestones (project_id, title, description, target_date, sort_order, created_by)
             VALUES (:project_id, :title, :description, :target_date, :sort_order, :created_by)'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'title' => $title,
            'description' => $description,
            'target_date' => $targetDate ?: null,
            'sort_order' => $nextOrder,
            'created_by' => $actingUserId,
        ]);

        $milestoneId = (int) Database::connection()->lastInsertId();
        Audit::log('milestone', $milestoneId, 'created', $actingUserId, ['title' => $title]);
        return $milestoneId;
    }

    public static function updateStatus(int $id, string $status, int $actingUserId): void
    {
        $stmt = Database::connection()->prepare('UPDATE milestones SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
        Audit::log('milestone', $id, 'status_changed_to_' . $status, $actingUserId);
    }

    public static function delete(int $id, int $actingUserId): void
    {
        $milestone = self::find($id);
        // Аудит-запис лишаємо ДО видалення — щоб зберегти назву етапу для історії.
        Audit::log('milestone', $id, 'deleted', $actingUserId, ['title' => $milestone['title'] ?? null]);

        $stmt = Database::connection()->prepare('DELETE FROM milestones WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Кількість задач (усього і закритих) на кожен етап проєкту — для
     * індикатора прогресу на дорожній карті. Повертає масив
     * [milestone_id => ['total' => N, 'closed' => M]].
     */
    public static function taskCountsByMilestone(int $projectId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT t.milestone_id,
                    COUNT(*) AS total,
                    SUM(CASE WHEN ts.is_closed = 1 THEN 1 ELSE 0 END) AS closed
             FROM tasks t
             JOIN task_statuses ts ON ts.id = t.status_id
             WHERE t.project_id = :project_id AND t.milestone_id IS NOT NULL
             GROUP BY t.milestone_id"
        );
        $stmt->execute(['project_id' => $projectId]);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['milestone_id']] = [
                'total' => (int) $row['total'],
                'closed' => (int) $row['closed'],
            ];
        }
        return $counts;
    }
}
