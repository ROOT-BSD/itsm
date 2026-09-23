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
                    p.name AS project_name, ms.title AS milestone_title
             FROM tasks t
             JOIN task_statuses ts ON ts.id = t.status_id
             JOIN task_types tt ON tt.id = t.type_id
             JOIN users au ON au.id = t.author_id
             JOIN projects p ON p.id = t.project_id
             LEFT JOIN users asg ON asg.id = t.assignee_id
             LEFT JOIN milestones ms ON ms.id = t.milestone_id
             WHERE t.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $task = $stmt->fetch();
        return $task ?: null;
    }

    public static function create(array $data): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO tasks (project_id, type_id, status_id, title, description, priority, author_id, assignee_id, start_date, due_date, milestone_id)
             VALUES (:project_id, :type_id, :status_id, :title, :description, :priority, :author_id, :assignee_id, :start_date, :due_date, :milestone_id)'
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
            'milestone_id' => $data['milestone_id'] ?? null,
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

    /** Прив'язка задачі до етапу/контрольної точки (дорожня карта) — або зняття прив'язки. */
    public static function updateMilestone(int $id, ?int $milestoneId, int $actingUserId): void
    {
        $stmt = Database::connection()->prepare('UPDATE tasks SET milestone_id = :milestone_id WHERE id = :id');
        $stmt->execute(['milestone_id' => $milestoneId ?: null, 'id' => $id]);
        Audit::log('task', $id, 'milestone_changed', $actingUserId, ['milestone_id' => $milestoneId]);
    }

    /** Задачі, прив'язані до конкретного етапу — для відображення на дорожній карті. */
    public static function forMilestone(int $milestoneId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT t.id, t.title, t.priority, ts.name AS status_name, ts.is_closed, asg.full_name AS assignee_name
             FROM tasks t
             JOIN task_statuses ts ON ts.id = t.status_id
             LEFT JOIN users asg ON asg.id = t.assignee_id
             WHERE t.milestone_id = :milestone_id
             ORDER BY ts.is_closed ASC, t.created_at ASC"
        );
        $stmt->execute(['milestone_id' => $milestoneId]);
        return $stmt->fetchAll();
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

    /** Усі записи обліку часу для однієї задачі (для відображення історії на сторінці задачі). */
    public static function timeLogsForTask(int $taskId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT tl.*, u.full_name AS user_name
             FROM time_logs tl
             JOIN users u ON u.id = tl.user_id
             WHERE tl.task_id = :task_id
             ORDER BY tl.log_date DESC, tl.id DESC"
        );
        $stmt->execute(['task_id' => $taskId]);
        return $stmt->fetchAll();
    }

    /**
     * Усі записи обліку часу по всіх задачах проєкту одразу — для зведеної
     * сторінки обліку часу проєкту (/projects/{id}/time).
     */
    public static function timeLogsForProject(int $projectId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT tl.*, u.full_name AS user_name, t.id AS task_id, t.title AS task_title
             FROM time_logs tl
             JOIN users u ON u.id = tl.user_id
             JOIN tasks t ON t.id = tl.task_id
             WHERE t.project_id = :project_id
             ORDER BY tl.log_date DESC, tl.id DESC"
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /**
     * Сумарні години по кожному користувачу для проєкту — для зведеної
     * таблиці "хто скільки часу витратив" на сторінці обліку часу проєкту.
     */
    public static function hoursByUserForProject(int $projectId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT u.full_name AS user_name, SUM(tl.hours) AS total_hours
             FROM time_logs tl
             JOIN users u ON u.id = tl.user_id
             JOIN tasks t ON t.id = tl.task_id
             WHERE t.project_id = :project_id
             GROUP BY tl.user_id, u.full_name
             ORDER BY total_hours DESC"
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /**
     * Усі записи обліку часу по ВСІХ проєктах одразу — для загальної сторінки
     * обліку часу адміністратора (/admin/time). Без фільтра видимості,
     * оскільки доступ до цієї сторінки й так обмежений роллю 'admin'
     * (перевіряється в AdminController, не тут).
     */
    public static function timeLogsAll(): array
    {
        return Database::connection()->query(
            "SELECT tl.*, u.full_name AS user_name, t.id AS task_id, t.title AS task_title,
                    p.id AS project_id, p.name AS project_name
             FROM time_logs tl
             JOIN users u ON u.id = tl.user_id
             JOIN tasks t ON t.id = tl.task_id
             JOIN projects p ON p.id = t.project_id
             ORDER BY tl.log_date DESC, tl.id DESC"
        )->fetchAll();
    }

    /** Сумарні години по кожному користувачу по всій системі (для /admin/time). */
    public static function hoursByUserAll(): array
    {
        return Database::connection()->query(
            "SELECT u.full_name AS user_name, SUM(tl.hours) AS total_hours
             FROM time_logs tl
             JOIN users u ON u.id = tl.user_id
             GROUP BY tl.user_id, u.full_name
             ORDER BY total_hours DESC"
        )->fetchAll();
    }

    /** Сумарні години по кожному проєкту по всій системі (для /admin/time). */
    public static function hoursByProjectAll(): array
    {
        return Database::connection()->query(
            "SELECT p.id AS project_id, p.name AS project_name, SUM(tl.hours) AS total_hours
             FROM time_logs tl
             JOIN tasks t ON t.id = tl.task_id
             JOIN projects p ON p.id = t.project_id
             GROUP BY p.id, p.name
             ORDER BY total_hours DESC"
        )->fetchAll();
    }

    /**
     * Розбивка годин по днях/тижнях/місяцях для ОДНОГО проєкту.
     * $period: 'day' | 'week' | 'month'.
     */
    public static function hoursByPeriodForProject(int $projectId, string $period): array
    {
        [$selectExpr, $groupExpr] = self::periodSql($period);

        $stmt = Database::connection()->prepare(
            "SELECT {$selectExpr} AS period_label, SUM(tl.hours) AS total_hours
             FROM time_logs tl
             JOIN tasks t ON t.id = tl.task_id
             WHERE t.project_id = :project_id
             GROUP BY {$groupExpr}
             ORDER BY MIN(tl.log_date) DESC"
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /** Те саме, але по всій системі одразу (для /admin/time). */
    public static function hoursByPeriodAll(string $period): array
    {
        [$selectExpr, $groupExpr] = self::periodSql($period);

        return Database::connection()->query(
            "SELECT {$selectExpr} AS period_label, SUM(tl.hours) AS total_hours
             FROM time_logs tl
             GROUP BY {$groupExpr}
             ORDER BY MIN(tl.log_date) DESC"
        )->fetchAll();
    }

    /**
     * SQL-вирази для групування записів обліку часу за період.
     * Тиждень — ISO 8601 (понеділок — перший день, режим 3 у WEEK()),
     * щоб збігалося зі звичним "робочим тижнем", а не американським.
     *
     * @return array{0: string, 1: string} [вираз для SELECT, вираз для GROUP BY]
     */
    /**
     * Записи обліку часу за довільними фільтрами — основа для сторінки
     * "Звіти" (PDF). Усі фільтри необов'язкові, крім діапазону дат.
     * Видимість: не-адмін бачить лише записи з проєктів, де він автор
     * або відповідальний (та сама логіка, що й усюди в системі).
     *
     * @param array{project_id?: int, log_user_id?: int, category?: string} $filters
     */
    public static function timeLogsFilteredReport(string $dateFrom, string $dateTo, array $filters, int $viewerId, bool $isAdmin): array
    {
        $sql = "SELECT tl.*, u.full_name AS user_name, t.id AS task_id, t.title AS task_title,
                       p.id AS project_id, p.name AS project_name
                FROM time_logs tl
                JOIN users u ON u.id = tl.user_id
                JOIN tasks t ON t.id = tl.task_id
                JOIN projects p ON p.id = t.project_id
                WHERE tl.log_date BETWEEN :date_from AND :date_to";
        $params = ['date_from' => $dateFrom, 'date_to' => $dateTo];

        if (!empty($filters['project_id'])) {
            $sql .= ' AND p.id = :project_id';
            $params['project_id'] = $filters['project_id'];
        }
        if (!empty($filters['log_user_id'])) {
            $sql .= ' AND tl.user_id = :log_user_id';
            $params['log_user_id'] = $filters['log_user_id'];
        }
        if (!empty($filters['category'])) {
            $sql .= ' AND tl.activity_category LIKE :category';
            $params['category'] = '%' . $filters['category'] . '%';
        }
        if (!$isAdmin) {
            $sql .= ' AND (p.created_by = :vis_uid1 OR p.responsible_user_id = :vis_uid2)';
            $params['vis_uid1'] = $viewerId;
            $params['vis_uid2'] = $viewerId;
        }

        $sql .= ' ORDER BY tl.log_date ASC, p.name ASC, t.id ASC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Список унікальних категорій активності, вже використаних у видимих проєктах — для підказки у формі звіту. */
    public static function distinctCategoriesVisibleTo(int $viewerId, bool $isAdmin): array
    {
        $sql = "SELECT DISTINCT tl.activity_category
                FROM time_logs tl
                JOIN tasks t ON t.id = tl.task_id
                JOIN projects p ON p.id = t.project_id
                WHERE tl.activity_category IS NOT NULL AND tl.activity_category <> ''";
        $params = [];

        if (!$isAdmin) {
            $sql .= ' AND (p.created_by = :vis_uid1 OR p.responsible_user_id = :vis_uid2)';
            $params['vis_uid1'] = $viewerId;
            $params['vis_uid2'] = $viewerId;
        }
        $sql .= ' ORDER BY tl.activity_category ASC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_column($stmt->fetchAll(), 'activity_category');
    }

    private static function periodSql(string $period): array
    {
        return match ($period) {
            'day' => ["DATE_FORMAT(tl.log_date, '%d.%m.%Y')", 'tl.log_date'],
            'week' => [
                "CONCAT(YEAR(tl.log_date), '-W', LPAD(WEEK(tl.log_date, 3), 2, '0'))",
                'YEAR(tl.log_date), WEEK(tl.log_date, 3)',
            ],
            'month' => ["DATE_FORMAT(tl.log_date, '%m.%Y')", "DATE_FORMAT(tl.log_date, '%Y-%m')"],
            default => throw new \InvalidArgumentException("Невідомий період: {$period}"),
        };
    }

    /** Розбивка годин по періоду ТА користувачу одночасно, для одного проєкту. */
    public static function hoursByPeriodAndUserForProject(int $projectId, string $period): array
    {
        [$selectExpr, $groupExpr] = self::periodSql($period);

        $stmt = Database::connection()->prepare(
            "SELECT {$selectExpr} AS period_label, u.full_name AS user_name, SUM(tl.hours) AS total_hours
             FROM time_logs tl
             JOIN users u ON u.id = tl.user_id
             JOIN tasks t ON t.id = tl.task_id
             WHERE t.project_id = :project_id
             GROUP BY {$groupExpr}, tl.user_id, u.full_name
             ORDER BY MIN(tl.log_date) DESC, total_hours DESC"
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /** Те саме, але по всій системі (для /admin/time). */
    public static function hoursByPeriodAndUserAll(string $period): array
    {
        [$selectExpr, $groupExpr] = self::periodSql($period);

        return Database::connection()->query(
            "SELECT {$selectExpr} AS period_label, u.full_name AS user_name, SUM(tl.hours) AS total_hours
             FROM time_logs tl
             JOIN users u ON u.id = tl.user_id
             GROUP BY {$groupExpr}, tl.user_id, u.full_name
             ORDER BY MIN(tl.log_date) DESC, total_hours DESC"
        )->fetchAll();
    }

    /** Розбивка годин по періоду ТА проєкту одночасно, по всій системі (для /admin/time). */
    public static function hoursByPeriodAndProjectAll(string $period): array
    {
        [$selectExpr, $groupExpr] = self::periodSql($period);

        return Database::connection()->query(
            "SELECT {$selectExpr} AS period_label, p.id AS project_id, p.name AS project_name, SUM(tl.hours) AS total_hours
             FROM time_logs tl
             JOIN tasks t ON t.id = tl.task_id
             JOIN projects p ON p.id = t.project_id
             GROUP BY {$groupExpr}, p.id, p.name
             ORDER BY MIN(tl.log_date) DESC, total_hours DESC"
        )->fetchAll();
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
