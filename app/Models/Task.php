<?php

namespace App\Models;

use App\Core\Database;

class Task
{
    /**
     * Задачі з терміном виконання у заданому діапазоні дат, видимі користувачу
     * (адмін/автор проєкту/відповідальний за проєкт) — для сторінки «Календар».
     */
    /**
     * Задачі, чий діапазон [start_date; due_date] перетинається із заданим
     * періодом (для календаря) — на відміну від простого "due_date BETWEEN",
     * тут враховується й дата початку, щоб довга задача показувалась на
     * ВСІХ днях свого виконання, а не лише в день дедлайну. Якщо в задачі
     * вказана лише одна з дат — вона трактується як єдиний день (друга
     * дата "дорівнює" наявній).
     */
    public static function spanningVisibleTo(string $rangeStart, string $rangeEnd, int $userId, bool $isAdmin): array
    {
        $sql = "SELECT t.id, t.title, t.priority, t.start_date, t.due_date, ts.name AS status_name, ts.is_closed,
                       p.id AS project_id, p.name AS project_name
                FROM tasks t
                JOIN task_statuses ts ON ts.id = t.status_id
                JOIN projects p ON p.id = t.project_id
                WHERE (t.start_date IS NOT NULL OR t.due_date IS NOT NULL)
                  AND COALESCE(t.start_date, t.due_date) <= :range_end
                  AND COALESCE(t.due_date, t.start_date) >= :range_start";
        $params = ['range_start' => $rangeStart, 'range_end' => $rangeEnd];

        if (!$isAdmin) {
            $sql .= ' AND (p.created_by = :uid1 OR p.responsible_user_id = :uid2)';
            $params['uid1'] = $userId;
            $params['uid2'] = $userId;
        }

        $sql .= ' ORDER BY COALESCE(t.start_date, t.due_date) ASC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public static function forProject(int $projectId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT t.*, ts.name AS status_name, ts.code AS status_code, ts.is_closed, tt.name AS type_name,
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

    /**
     * Усі відкриті задачі з проєктів, видимих користувачу (адмін/автор
     * проєкту/відповідальний за проєкт) — для загальної сторінки "Відкриті
     * задачі", на яку веде картка дашборду.
     */
    public static function allOpenVisibleTo(int $userId, bool $isAdmin): array
    {
        $sql = "SELECT t.*, ts.name AS status_name, tt.name AS type_name, p.name AS project_name,
                       asg.full_name AS assignee_name,
                       DATE_FORMAT(t.created_at, '%d.%m.%Y') AS created_at_formatted
                FROM tasks t
                JOIN task_statuses ts ON ts.id = t.status_id
                JOIN task_types tt ON tt.id = t.type_id
                JOIN projects p ON p.id = t.project_id
                LEFT JOIN users asg ON asg.id = t.assignee_id
                WHERE ts.is_closed = 0";
        $params = [];

        if (!$isAdmin) {
            $sql .= ' AND (p.created_by = :uid1 OR p.responsible_user_id = :uid2)';
            $params = ['uid1' => $userId, 'uid2' => $userId];
        }

        $sql .= ' ORDER BY p.name ASC, t.due_date IS NULL, t.due_date ASC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
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
            'INSERT INTO tasks (project_id, type_id, status_id, title, description, priority, author_id, assignee_id, start_date, due_date)
             VALUES (:project_id, :type_id, :status_id, :title, :description, :priority, :author_id, :assignee_id, :start_date, :due_date)'
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
            'start_date' => $data['start_date'] ?: null,
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

    public static function updateAssignee(int $id, ?int $assigneeId, int $actingUserId): void
    {
        $stmt = Database::connection()->prepare('UPDATE tasks SET assignee_id = :assignee_id WHERE id = :id');
        $stmt->execute(['assignee_id' => $assigneeId ?: null, 'id' => $id]);
        Audit::log('task', $id, 'assignee_changed', $actingUserId, ['assignee_id' => $assigneeId]);
    }

    /** Оновлення дати початку/завершення задачі — використовується діаграмою Ганта (drag/resize). */
    public static function updateDates(int $id, ?string $startDate, ?string $dueDate, int $actingUserId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE tasks SET start_date = :start_date, due_date = :due_date WHERE id = :id'
        );
        $stmt->execute(['start_date' => $startDate, 'due_date' => $dueDate, 'id' => $id]);
        Audit::log('task', $id, 'dates_changed', $actingUserId, ['start_date' => $startDate, 'due_date' => $dueDate]);
    }

    /** Створення зв'язку залежності між двома задачами (для діаграми Ганта). */
    public static function addRelation(int $taskId, int $relatedTaskId, string $relationType, int $actingUserId): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO task_relations (task_id, related_task_id, relation_type) VALUES (:task_id, :related_task_id, :relation_type)'
        );
        $stmt->execute(['task_id' => $taskId, 'related_task_id' => $relatedTaskId, 'relation_type' => $relationType]);
        Audit::log('task', $taskId, 'relation_added', $actingUserId, ['related_task_id' => $relatedTaskId, 'relation_type' => $relationType]);
    }

    /**
     * Усі зв'язки залежності між задачами одного проєкту — використовується
     * для побудови стрілок залежності на діаграмі Ганта.
     */
    public static function relationsForProject(int $projectId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT tr.id, tr.task_id, tr.related_task_id, tr.relation_type
             FROM task_relations tr
             JOIN tasks t ON t.id = tr.task_id
             WHERE t.project_id = :project_id"
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /**
     * Знаходить зв'язок за id, але лише якщо ОБИДВІ задачі належать вказаному
     * проєкту — захист від редагування/видалення "чужого" зв'язку через
     * підміну id у прямому POST-запиті.
     */
    public static function findRelationInProject(int $relationId, int $projectId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT tr.id, tr.task_id, tr.related_task_id, tr.relation_type
             FROM task_relations tr
             JOIN tasks t1 ON t1.id = tr.task_id
             JOIN tasks t2 ON t2.id = tr.related_task_id
             WHERE tr.id = :id AND t1.project_id = :project_id AND t2.project_id = :project_id2"
        );
        $stmt->execute(['id' => $relationId, 'project_id' => $projectId, 'project_id2' => $projectId]);
        $relation = $stmt->fetch();
        return $relation ?: null;
    }

    public static function updateRelation(int $relationId, string $relationType, int $actingUserId): void
    {
        $stmt = Database::connection()->prepare('UPDATE task_relations SET relation_type = :relation_type WHERE id = :id');
        $stmt->execute(['relation_type' => $relationType, 'id' => $relationId]);
        Audit::log('task_relation', $relationId, 'relation_type_changed_to_' . $relationType, $actingUserId);
    }

    public static function deleteRelation(int $relationId, int $actingUserId): void
    {
        Audit::log('task_relation', $relationId, 'relation_deleted', $actingUserId);
        $stmt = Database::connection()->prepare('DELETE FROM task_relations WHERE id = :id');
        $stmt->execute(['id' => $relationId]);
    }

    /**
     * Усі задачі з усіх проєктів (без фільтра видимості) — лише для
     * загального огляду адміністратора (Канбан/Гант по всій системі).
     * Перевірка ролі 'admin' виконується в контролері, не тут.
     */
    public static function allWithProject(): array
    {
        return Database::connection()->query(
            "SELECT t.*, ts.name AS status_name, ts.code AS status_code, ts.is_closed, tt.name AS type_name,
                    au.full_name AS author_name, asg.full_name AS assignee_name, p.name AS project_name
             FROM tasks t
             JOIN task_statuses ts ON ts.id = t.status_id
             JOIN task_types tt ON tt.id = t.type_id
             JOIN users au ON au.id = t.author_id
             JOIN projects p ON p.id = t.project_id
             LEFT JOIN users asg ON asg.id = t.assignee_id
             ORDER BY p.name ASC, t.created_at DESC"
        )->fetchAll();
    }

    /** Усі зв'язки залежності в системі (для загальної діаграми Ганта адміністратора). */
    public static function allRelations(): array
    {
        return Database::connection()
            ->query('SELECT task_id, related_task_id, relation_type FROM task_relations')
            ->fetchAll();
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
