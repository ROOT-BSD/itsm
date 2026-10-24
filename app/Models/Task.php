<?php

namespace App\Models;

use App\Core\Database;
use App\Services\NotificationService;

class Task
{
    /** Допустимі пріоритети задачі (єдиний перелік для веб-форми, REST API й вебхуків). */
    public const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    /** Вага пріоритету для сортування на екрані: critical → 0, high → 1, normal → 2, low → 3. */
    public static function priorityRank(?string $priority): int
    {
        $i = array_search($priority, ['critical', 'high', 'normal', 'low'], true);
        return $i === false ? 2 : (int) $i;
    }

    /**
     * Порядок для списків «Мої відкриті задачі» та «Відкриті задачі»: спершу проєкти, де є задачі critical, далі high, normal, low
     * (за найвищим пріоритетом серед задач проєкту); у проєкті — за пріоритетом, терміном (без терміну в кінці), статусом, номером.
     * Потрібні поля project_id, project_name, priority, due_date, status_order, id.
     */
    public static function sortForDisplay(array $tasks): array
    {
        $best = [];
        foreach ($tasks as $t) {
            $pid = (int) $t['project_id'];
            $best[$pid] = min($best[$pid] ?? 9, self::priorityRank($t['priority'] ?? null));
        }
        $key = static fn(array $t): array => [
            $best[(int) $t['project_id']], (string) $t['project_name'], (int) $t['project_id'],
            self::priorityRank($t['priority'] ?? null), $t['due_date'] === null ? 1 : 0, (string) $t['due_date'], (int) ($t['status_order'] ?? 0), (int) $t['id'],
        ];
        usort($tasks, static fn($a, $b) => $key($a) <=> $key($b));
        return $tasks;
    }

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
                       p.id AS project_id, p.name AS project_name" . TaskChecklist::progressSelect() . "
                FROM tasks t
                JOIN task_statuses ts ON ts.id = t.status_id
                JOIN projects p ON p.id = t.project_id
                WHERE ts.is_closed = 0 -- закриті задачі в календарі не показуються
                  AND (t.start_date IS NOT NULL OR t.due_date IS NOT NULL)
                  AND COALESCE(t.start_date, t.due_date) <= :range_end
                  AND COALESCE(t.due_date, t.start_date) >= :range_start";
        $params = ['range_start' => $rangeStart, 'range_end' => $rangeEnd];

        if (!$isAdmin) {
            [$accessSql, $accessParams] = Project::accessCondition('p', 'acc', $userId);
            $sql .= ' AND ' . $accessSql;
            $params = array_merge($params, $accessParams);
        }

        $sql .= ' ORDER BY COALESCE(t.start_date, t.due_date) ASC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Прострочена — має термін виконання в минулому і ще не закрита. Потребує ts.is_closed з того самого запиту (find(), forProject() уже його повертають). */
    public static function isOverdue(array $task): bool
    {
        if (empty($task['due_date']) || !empty($task['is_closed'])) {
            return false;
        }
        return $task['due_date'] < date('Y-m-d');
    }

    public static function forProject(int $projectId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT t.*, ts.name AS status_name, ts.code AS status_code, ts.is_closed, tt.name AS type_name,
                    au.full_name AS author_name, asg.full_name AS assignee_name' . TaskChecklist::progressSelect() . '
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

    /** Те саме, що forProject(), але без закритих — для таблиці задач на сторінці проєкту. Канбан і Гант і далі показують усі статуси (потрібні для картини процесу); закриті задачі — на сторінці «Архів». */
    public static function openForProject(int $projectId): array
    {
        return array_values(array_filter(
            self::forProject($projectId),
            fn(array $t): bool => empty($t['is_closed']) || $t['status_code'] === 'resolved' // «Вирішена» завжди видно в таблиці проєкту
        ));
    }

    /**
     * Усі відкриті задачі з проєктів, видимих користувачу (адмін/автор
     * проєкту/відповідальний за проєкт) — для загальної сторінки "Відкриті
     * задачі", на яку веде картка дашборду.
     */
    public static function allOpenVisibleTo(int $userId, bool $isAdmin): array
    {
        $sql = "SELECT t.*, ts.name AS status_name, ts.sort_order AS status_order, tt.name AS type_name, p.name AS project_name,
                       asg.full_name AS assignee_name,
                       DATE_FORMAT(t.created_at, '%d.%m.%Y') AS created_at_formatted" . TaskChecklist::progressSelect() . "
                FROM tasks t
                JOIN task_statuses ts ON ts.id = t.status_id
                JOIN task_types tt ON tt.id = t.type_id
                JOIN projects p ON p.id = t.project_id
                LEFT JOIN users asg ON asg.id = t.assignee_id
                WHERE ts.is_closed = 0";
        $params = [];

        if (!$isAdmin) {
            [$accessSql, $accessParams] = Project::accessCondition('p', 'acc', $userId);
            $sql .= ' AND ' . $accessSql;
            $params = array_merge($params ?? [], $accessParams);
        }

        $sql .= ' ORDER BY t.due_date IS NULL, t.due_date ASC, p.name ASC, t.id ASC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Те саме, що allOpenVisibleTo(), але лише ЗАКРИТІ задачі — для сторінки «Архів». */
    public static function allClosedVisibleTo(int $userId, bool $isAdmin): array
    {
        $sql = "SELECT t.*, ts.name AS status_name, tt.name AS type_name, p.name AS project_name,
                       asg.full_name AS assignee_name,
                       DATE_FORMAT(t.created_at, '%d.%m.%Y') AS created_at_formatted" . TaskChecklist::progressSelect() . "
                FROM tasks t
                JOIN task_statuses ts ON ts.id = t.status_id
                JOIN task_types tt ON tt.id = t.type_id
                JOIN projects p ON p.id = t.project_id
                LEFT JOIN users asg ON asg.id = t.assignee_id
                WHERE ts.is_closed = 1";
        $params = [];

        if (!$isAdmin) {
            [$accessSql, $accessParams] = Project::accessCondition('p', 'acc', $userId);
            $sql .= ' AND ' . $accessSql;
            $params = array_merge($params ?? [], $accessParams);
        }

        $sql .= ' ORDER BY p.name ASC, t.updated_at DESC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT t.*, ts.name AS status_name, ts.is_closed, tt.name AS type_name,
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
        $parentId = !empty($data['parent_task_id']) ? (int) $data['parent_task_id'] : null;
        $tracked = self::datesTracked(); // міграція 043: початкові терміни = значення при створенні
        $stmt = Database::connection()->prepare(
            'INSERT INTO tasks (project_id, ' . ($parentId !== null ? 'parent_task_id, ' : '') . 'type_id, status_id, title, description, priority, author_id, assignee_id, start_date, due_date, ' . ($tracked ? 'planned_start_date, planned_due_date, ' : '') . 'milestone_id)
             VALUES (:project_id, ' . ($parentId !== null ? ':parent_task_id, ' : '') . ':type_id, :status_id, :title, :description, :priority, :author_id, :assignee_id, :start_date, :due_date, ' . ($tracked ? ':planned_start_date, :planned_due_date, ' : '') . ':milestone_id)'
        );
        $params = [
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
        ];
        if ($parentId !== null) {
            $params['parent_task_id'] = $parentId;
        }
        if ($tracked) {
            $params['planned_start_date'] = $params['start_date'];
            $params['planned_due_date'] = $params['due_date'];
        }
        $stmt->execute($params);

        $taskId = (int) Database::connection()->lastInsertId();
        Audit::log('task', $taskId, 'created', $data['author_id'], $parentId !== null ? ['parent_task_id' => $parentId] : null);
        NotificationService::taskCreated($taskId, $data['assignee_id'] ?: null, (int) $data['author_id']);
        \App\Services\TaskRuleService::apply($taskId, 'created');
        return $taskId;
    }

    /** Причини в історії змін дат, за якими термін прибирають/відновлюють автоматично. */
    private const REASON_CLEARED = 'Задачу вирішено/закрито — термін виконання прибрано';
    private const REASON_RESTORED = 'Задачу відкрито знову — термін виконання відновлено';

    public static function updateStatus(int $id, int $statusId, int $userId): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT t.status_id, t.start_date, t.due_date, ts.is_closed, ts.code FROM tasks t JOIN task_statuses ts ON ts.id = t.status_id WHERE t.id = :id');
        $stmt->execute(['id' => $id]);
        $old = $stmt->fetch();

        $stmt = $db->prepare('UPDATE tasks SET status_id = :status_id WHERE id = :id');
        $stmt->execute(['status_id' => $statusId, 'id' => $id]);
        Audit::log('task', $id, 'status_changed', $userId, ['status_id' => $statusId]);

        if (!$old) {
            return;
        }
        $nowClosed = self::statusClearsDue($statusId);
        $wasClosed = !empty($old['is_closed']) || $old['code'] === 'resolved';
        if ($nowClosed && !empty($old['due_date'])) {
            // «Вирішена»/«Закрита»: терміну більше немає (задача не прострочується), але лишається в списках проєкту, на Ганті й у календарі (за початком).
            self::updateDates($id, $old['start_date'] ?: null, null, $userId, self::REASON_CLEARED);
        } elseif (!$nowClosed && $wasClosed && empty($old['due_date']) && self::datesTracked()) {
            // Задачу відкрили знову — повертаємо термін, який прибрали при завершенні.
            $stmt = $db->prepare('SELECT old_due_date FROM task_date_changes WHERE task_id = :id AND reason = :r AND old_due_date IS NOT NULL ORDER BY id DESC LIMIT 1');
            $stmt->execute(['id' => $id, 'r' => self::REASON_CLEARED]);
            if (($due = $stmt->fetchColumn()) !== false && $due !== null) {
                self::updateDates($id, $old['start_date'] ?: null, (string) $due, $userId, self::REASON_RESTORED);
            }
        }
        \App\Services\TaskRuleService::apply($id, 'status', $statusId);
    }

    public static function updateAssignee(int $id, ?int $assigneeId, int $actingUserId): void
    {
        $previousStmt = Database::connection()->prepare('SELECT assignee_id FROM tasks WHERE id = :id');
        $previousStmt->execute(['id' => $id]);
        $previousAssigneeId = $previousStmt->fetchColumn();
        $previousAssigneeId = $previousAssigneeId !== false && $previousAssigneeId !== null ? (int) $previousAssigneeId : null;

        $stmt = Database::connection()->prepare('UPDATE tasks SET assignee_id = :assignee_id WHERE id = :id');
        $stmt->execute(['assignee_id' => $assigneeId ?: null, 'id' => $id]);
        Audit::log('task', $id, 'assignee_changed', $actingUserId, ['assignee_id' => $assigneeId]);
        NotificationService::taskAssigneeChanged($id, $assigneeId ?: null, $previousAssigneeId, $actingUserId);
    }

    /** Прив'язка задачі до етапу/контрольної точки (дорожня карта) — або зняття прив'язки. */
    public static function updateMilestone(int $id, ?int $milestoneId, int $actingUserId): void
    {
        $stmt = Database::connection()->prepare('UPDATE tasks SET milestone_id = :milestone_id WHERE id = :id');
        $stmt->execute(['milestone_id' => $milestoneId ?: null, 'id' => $id]);
        Audit::log('task', $id, 'milestone_changed', $actingUserId, ['milestone_id' => $milestoneId]);
    }

    public static function updateTitle(int $id, string $title, string $oldTitle, int $actingUserId): void
    {
        Database::connection()->prepare('UPDATE tasks SET title = :t WHERE id = :id')->execute(['t' => $title, 'id' => $id]);
        Audit::log('task', $id, 'title_changed', $actingUserId, ['from' => mb_substr($oldTitle, 0, 255), 'to' => $title]);
    }

    public static function updateDescription(int $id, ?string $description, int $actingUserId): void
    {
        $stmt = Database::connection()->prepare('UPDATE tasks SET description = :description WHERE id = :id');
        $stmt->execute(['description' => $description, 'id' => $id]);
        Audit::log('task', $id, 'description_changed', $actingUserId);
    }

    /**
     * Переносить задачу в інший проєкт (основний або підпроєкт, будь-який
     * видимий користувачу — перевірка в контролері). Заразом прибирає те,
     * що більше не має сенсу після переносу в інший проєкт:
     *  - зв'язки з задачами, які лишились у старому/іншому проєкті
     *    (залежність технічно можлива лише між задачами одного проєкту —
     *    те саме правило, що діє при створенні зв'язку);
     *  - прив'язку до етапу дорожньої карти, якщо етап належить іншому проєкту.
     */
    public static function updateProject(int $id, int $newProjectId, int $actingUserId): void
    {
        $conn = Database::connection();

        $conn->prepare(
            "DELETE tr FROM task_relations tr
             JOIN tasks other ON other.id = tr.related_task_id
             WHERE tr.task_id = :task_id AND other.project_id != :new_project_id"
        )->execute(['task_id' => $id, 'new_project_id' => $newProjectId]);

        $conn->prepare(
            "DELETE tr FROM task_relations tr
             JOIN tasks other ON other.id = tr.task_id
             WHERE tr.related_task_id = :task_id AND other.project_id != :new_project_id"
        )->execute(['task_id' => $id, 'new_project_id' => $newProjectId]);

        $conn->prepare(
            "UPDATE tasks t
             LEFT JOIN milestones m ON m.id = t.milestone_id
             SET t.milestone_id = NULL
             WHERE t.id = :task_id AND m.project_id IS NOT NULL AND m.project_id != :new_project_id"
        )->execute(['task_id' => $id, 'new_project_id' => $newProjectId]);

        $conn->prepare('UPDATE tasks SET project_id = :project_id WHERE id = :id')
            ->execute(['project_id' => $newProjectId, 'id' => $id]);

        Audit::log('task', $id, 'project_changed', $actingUserId, ['project_id' => $newProjectId]);
    }

    /** Незворотне видалення задачі. Коментарі, записи обліку часу й зв'язки видаляються каскадно (FK ON DELETE CASCADE). */
    public static function delete(int $id, string $title, int $actingUserId): void
    {
        // Спершу — аудит зі знімком назви, бо після видалення задачі її вже не буде звідки прочитати.
        Audit::log('task', $id, 'deleted', $actingUserId, ['title' => $title]);

        // Імена файлів вкладень треба зібрати ДО видалення: каскад у БД прибере рядки таблиці
        // attachments, але не самі файли на диску — без цього вони лишились би без жодного посилання.
        $attachmentFiles = Attachment::storedNamesForTask($id);

        $stmt = Database::connection()->prepare('DELETE FROM tasks WHERE id = :id');
        $stmt->execute(['id' => $id]);

        \App\Services\AttachmentService::deleteFiles($attachmentFiles);
    }

    /**
     * Усі задачі проєкту, прив'язані до якогось етапу, — ОДНИМ запитом,
     * для дорожньої карти. Раніше викликалось по одному запиту на кожен
     * етап (N+1) — при 10+ етапах це 10+ окремих звернень до БД замість
     * одного; групування по milestone_id тепер робиться в PHP.
     */
    public static function forProjectMilestones(int $projectId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT t.id, t.title, t.priority, t.milestone_id, ts.name AS status_name, ts.is_closed, asg.full_name AS assignee_name" . TaskChecklist::progressSelect() . "
             FROM tasks t
             JOIN task_statuses ts ON ts.id = t.status_id
             LEFT JOIN users asg ON asg.id = t.assignee_id
             WHERE t.project_id = :project_id AND t.milestone_id IS NOT NULL
             ORDER BY ts.is_closed ASC, t.created_at ASC"
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /** Оновлення дати початку/завершення задачі — використовується діаграмою Ганта (drag/resize). */
    /** Чи застосована міграція 043 (початкові терміни й історія змін). */
    public static function datesTracked(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = (int) Database::connection()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_date_changes'"
            )->fetchColumn() > 0;
        }
        return $ok;
    }

    /**
     * Єдине місце зміни дат (сторінка задачі, діаграма Ганта, API): записує історію, фіксує ПОЧАТКОВІ значення
     * (якщо ще не було) і скидає нагадування про термін. Без змін — нічого не робить.
     */
    public static function updateDates(int $id, ?string $startDate, ?string $dueDate, int $actingUserId, ?string $reason = null): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT start_date, due_date FROM tasks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $old = $stmt->fetch();
        if (!$old) {
            return;
        }
        if ($old['start_date'] === $startDate && $old['due_date'] === $dueDate) {
            return;
        }
        $tracked = self::datesTracked();
        $sql = 'UPDATE tasks SET start_date = :start_date, due_date = :due_date';
        if ($tracked) {
            $sql .= ', planned_start_date = COALESCE(planned_start_date, :new_ps), planned_due_date = COALESCE(planned_due_date, :new_pd)';
        }
        $params = ['start_date' => $startDate, 'due_date' => $dueDate, 'id' => $id];
        if ($tracked) {
            $params['new_ps'] = $startDate;
            $params['new_pd'] = $dueDate;
        }
        $db->prepare($sql . ' WHERE id = :id')->execute($params);
        if ($tracked) {
            $reason = $reason !== null ? mb_substr(trim($reason), 0, 255) : null;
            $db->prepare(
                'INSERT INTO task_date_changes (task_id, user_id, old_start_date, old_due_date, new_start_date, new_due_date, reason)
                 VALUES (:t, :u, :os, :od, :ns, :nd, :r)'
            )->execute(['t' => $id, 'u' => $actingUserId ?: null, 'os' => $old['start_date'], 'od' => $old['due_date'], 'ns' => $startDate, 'nd' => $dueDate, 'r' => $reason !== '' ? $reason : null]);
        }
        Audit::log('task', $id, 'dates_changed', $actingUserId, ['start_date' => $startDate, 'due_date' => $dueDate, 'was_start' => $old['start_date'], 'was_due' => $old['due_date']]);

        // Термін переносять — старі нагадування (2 дні/1 день/настав термін) уже не відповідають
        // новій даті; прибираємо їх, щоб вони мали шанс спрацювати заново для нового терміну.
        $db->prepare('DELETE FROM task_due_reminders WHERE task_id = :id')->execute(['id' => $id]);
    }

    /** @return array<int, array<string, mixed>> історія змін дат, нові зверху */
    public static function dateChanges(int $taskId): array
    {
        if (!self::datesTracked()) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT c.*, u.full_name AS user_name FROM task_date_changes c LEFT JOIN users u ON u.id = c.user_id WHERE c.task_id = :id ORDER BY c.id DESC'
        );
        $stmt->execute(['id' => $taskId]);
        return $stmt->fetchAll();
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
    /**
     * Обмеження «лише проєкти, що стосуються підрозділу» для загальних списків (канбан, Гант, облік часу).
     * Без $unitAdminId — без обмежень. $col — стовпець id проєкту в запиті.
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function projectScope(?int $unitAdminId, string $col, string $prefix = 'sc'): array
    {
        if ($unitAdminId === null) {
            return ['', []];
        }
        [$sql, $params] = Project::accessCondition('ps', $prefix, $unitAdminId);
        return [" AND {$col} IN (SELECT ps.id FROM projects ps WHERE {$sql})", $params];
    }

    /** Те саме для запитів лише по time_logs (без приєднаних задач): обмеження через задачу запису. */
    private static function timeScope(?int $unitAdminId): array
    {
        [$w, $p] = self::projectScope($unitAdminId, 'tx.project_id');
        if ($w === '') {
            return ['', []];
        }
        return [' AND tl.task_id IN (SELECT tx.id FROM tasks tx WHERE 1 = 1' . $w . ')', $p];
    }

    private static function runScoped(string $sql, array $params): array
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function allWithProject(?int $unitAdminId = null): array
    {
        [$w, $wp] = self::projectScope($unitAdminId, 't.project_id');
        return self::runScoped(
            "SELECT t.*, ts.name AS status_name, ts.code AS status_code, ts.is_closed, tt.name AS type_name,
                    au.full_name AS author_name, asg.full_name AS assignee_name, p.name AS project_name
             FROM tasks t
             JOIN task_statuses ts ON ts.id = t.status_id
             JOIN task_types tt ON tt.id = t.type_id
             JOIN users au ON au.id = t.author_id
             JOIN projects p ON p.id = t.project_id
             LEFT JOIN users asg ON asg.id = t.assignee_id
             WHERE 1 = 1{$w}
             ORDER BY p.name ASC, t.created_at DESC",
            $wp
        );
    }

    /** Усі зв'язки залежності в системі (для загальної діаграми Ганта адміністратора). */
    public static function allRelations(?int $unitAdminId = null): array
    {
        [$w, $wp] = self::projectScope($unitAdminId, 't.project_id');
        return self::runScoped(
            "SELECT tr.task_id, tr.related_task_id, tr.relation_type FROM task_relations tr
             JOIN tasks t ON t.id = tr.task_id WHERE 1 = 1{$w}",
            $wp
        );
    }

    // ------------------------------------------------------------------ Журнал робіт (міграція 052)
    // Запис журналу = рядок time_logs: текст (comment), години (0 — без часу), дата, категорія. «Витрачено годин» = сума годин.

    /** Розбирає введені години: «1,5», «1.5», «1:30», «90 хв», «2 год», «1 год 30 хв». null — не розпізнано. */
    public static function parseHours(string $input): ?float
    {
        $s = mb_strtolower(trim($input));
        if ($s === '') {
            return 0.0;
        }
        $s = str_replace(',', '.', $s);
        if (preg_match('/^(\d{1,3}):([0-5]?\d)$/', $s, $m)) {
            return round((int) $m[1] + (int) $m[2] / 60, 4);
        }
        if (preg_match('/^\d+(\.\d+)?$/', $s)) {
            return round((float) $s, 4);
        }
        if (preg_match('/^(?:(\d+(?:\.\d+)?)\s*(?:год|г|h)\.?)?\s*(?:(\d+(?:\.\d+)?)\s*(?:хв|х|м|m|min)\.?)?$/u', $s, $m) && (($m[1] ?? '') !== '' || ($m[2] ?? '') !== '')) {
            return round((float) ($m[1] ?? 0) + (float) ($m[2] ?? 0) / 60, 4);
        }
        return null;
    }

    /** Години для показу: «1 год 30 хв», «45 хв», «2 год». */
    public static function formatHours(float $hours): string
    {
        $min = (int) round($hours * 60);
        if ($min <= 0) {
            return '';
        }
        $h = intdiv($min, 60);
        $m = $min % 60;
        return trim(($h > 0 ? $h . ' год' : '') . ($m > 0 ? ' ' . $m . ' хв' : ''));
    }

    /** Перераховує tasks.actual_hours як суму годин журналу. */
    public static function recalcHours(int $taskId): void
    {
        Database::connection()->prepare(
            'UPDATE tasks SET actual_hours = (SELECT COALESCE(SUM(hours), 0) FROM time_logs WHERE task_id = :t1) WHERE id = :t2'
        )->execute(['t1' => $taskId, 't2' => $taskId]);
    }

    /** Додає запис у журнал робіт. @return int id запису */
    public static function addEntry(int $taskId, int $userId, string $text, float $hours, string $date, ?string $category): int
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $db->prepare(
                'INSERT INTO time_logs (task_id, user_id, activity_category, hours, log_date, comment)
                 VALUES (:task_id, :user_id, :category, :hours, :log_date, :comment)'
            )->execute([
                'task_id' => $taskId, 'user_id' => $userId, 'category' => $category !== '' ? $category : null,
                'hours' => $hours, 'log_date' => $date, 'comment' => $text !== '' ? $text : null,
            ]);
            $id = (int) $db->lastInsertId();
            self::recalcHours($taskId);
            $db->commit();
            return $id;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function findEntry(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT tl.*, u.full_name AS user_name FROM time_logs tl JOIN users u ON u.id = tl.user_id WHERE tl.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function updateEntry(int $id, string $text, float $hours, string $date, ?string $category): void
    {
        $e = self::findEntry($id);
        if (!$e) {
            return;
        }
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $db->prepare(
                'UPDATE time_logs SET comment = :c, hours = :h, log_date = :d, activity_category = :cat, edited_at = NOW() WHERE id = :id'
            )->execute(['c' => $text !== '' ? $text : null, 'h' => $hours, 'd' => $date, 'cat' => $category !== '' ? $category : null, 'id' => $id]);
            self::recalcHours((int) $e['task_id']);
            $db->commit();
        } catch (\Throwable $ex) {
            $db->rollBack();
            throw $ex;
        }
    }

    public static function deleteEntry(int $id): void
    {
        $e = self::findEntry($id);
        if (!$e) {
            return;
        }
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM time_logs WHERE id = :id')->execute(['id' => $id]);
            self::recalcHours((int) $e['task_id']);
            $db->commit();
        } catch (\Throwable $ex) {
            $db->rollBack();
            throw $ex;
        }
    }

    /** Журнал робіт задачі: від новіших до давніших (за датою запису, потім за порядком додавання). */
    public static function journal(int $taskId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT tl.*, u.full_name AS user_name
             FROM time_logs tl JOIN users u ON u.id = tl.user_id
             WHERE tl.task_id = :task_id ORDER BY tl.log_date DESC, tl.id DESC'
        );
        $stmt->execute(['task_id' => $taskId]);
        return $stmt->fetchAll();
    }

    /** Сумісність (REST API, вебхуки): «коментар» = запис журналу без годин. */
    public static function addComment(int $taskId, int $authorId, string $body, float $hours = 0.0): int
    {
        $id = self::addEntry($taskId, $authorId, $body, $hours, date('Y-m-d'), null);
        \App\Services\WebhookService::emitTaskComment($taskId, $id, $authorId);
        return $id;
    }

    /** Сумісність (REST API): записи журналу з текстом у вигляді «коментарів». */
    public static function comments(int $taskId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT tl.id, tl.task_id, tl.user_id AS author_id, tl.comment AS body, tl.hours, tl.created_at, u.full_name AS author_name
             FROM time_logs tl JOIN users u ON u.id = tl.user_id
             WHERE tl.task_id = :task_id AND tl.comment IS NOT NULL AND tl.comment <> '' ORDER BY tl.created_at ASC, tl.id ASC"
        );
        $stmt->execute(['task_id' => $taskId]);
        return $stmt->fetchAll();
    }

    public static function logTime(int $taskId, int $userId, float $hours, string $date, ?string $category, ?string $comment): void
    {
        self::addEntry($taskId, $userId, (string) $comment, $hours, $date, $category);
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
             WHERE tl.hours > 0 AND t.project_id = :project_id
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
             WHERE tl.hours > 0 AND t.project_id = :project_id
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
    public static function timeLogsAll(?int $unitAdminId = null): array
    {
        [$w, $wp] = self::projectScope($unitAdminId, 't.project_id');
        return self::runScoped(
            "SELECT tl.*, u.full_name AS user_name, t.id AS task_id, t.title AS task_title,
                    p.id AS project_id, p.name AS project_name
             FROM time_logs tl
             JOIN users u ON u.id = tl.user_id
             JOIN tasks t ON t.id = tl.task_id
             JOIN projects p ON p.id = t.project_id
             WHERE tl.hours > 0 AND 1 = 1{$w}
             ORDER BY tl.log_date DESC, tl.id DESC",
            $wp
        );
    }

    /** Сумарні години по кожному користувачу по всій системі (для /admin/time). */
    public static function hoursByUserAll(?int $unitAdminId = null): array
    {
        [$w, $wp] = self::timeScope($unitAdminId);
        return self::runScoped(
            "SELECT u.full_name AS user_name, SUM(tl.hours) AS total_hours
             FROM time_logs tl
             JOIN users u ON u.id = tl.user_id
             WHERE tl.hours > 0 AND 1 = 1{$w}
             GROUP BY tl.user_id, u.full_name
             ORDER BY total_hours DESC",
            $wp
        );
    }

    /** Сумарні години по кожному проєкту по всій системі (для /admin/time). */
    /** Підпроєкт рахується як частина основного (кореневого) проєкту — окремим рядком не показується. */
    public static function hoursByProjectAll(?int $unitAdminId = null): array
    {
        [$w, $wp] = self::projectScope($unitAdminId, 't.project_id');
        $raw = self::runScoped(
            "SELECT p.id AS project_id, p.name AS project_name, SUM(tl.hours) AS total_hours
             FROM time_logs tl
             JOIN tasks t ON t.id = tl.task_id
             JOIN projects p ON p.id = t.project_id
             WHERE tl.hours > 0 AND 1 = 1{$w}
             GROUP BY p.id, p.name",
            $wp
        );

        return self::rollUpToRootProjects($raw);
    }

    /**
     * Переносить години підпроєкту на його основний (кореневий) проєкт і
     * підсумовує в один рядок — щоб підпроєкт не з'являвся окремим рядком
     * поруч зі своїм основним проєктом у загальних звітах.
     *
     * @param array<array{project_id: int|string, project_name: string, total_hours: mixed}> $rows
     */
    private static function rollUpToRootProjects(array $rows): array
    {
        $rootMap = Project::rootProjectMap();

        $aggregated = [];
        foreach ($rows as $row) {
            $pid = (int) $row['project_id'];
            $root = $rootMap[$pid] ?? ['id' => $pid, 'name' => $row['project_name']];
            $rootId = $root['id'];

            if (!isset($aggregated[$rootId])) {
                $aggregated[$rootId] = ['project_id' => $rootId, 'project_name' => $root['name'], 'total_hours' => 0.0];
            }
            $aggregated[$rootId]['total_hours'] += (float) $row['total_hours'];
        }

        usort($aggregated, fn($a, $b) => $b['total_hours'] <=> $a['total_hours']);
        return array_values($aggregated);
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
             WHERE tl.hours > 0 AND t.project_id = :project_id
             GROUP BY {$groupExpr}
             ORDER BY MIN(tl.log_date) DESC"
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /** Те саме, але по всій системі одразу (для /admin/time). */
    public static function hoursByPeriodAll(string $period, ?int $unitAdminId = null): array
    {
        [$selectExpr, $groupExpr] = self::periodSql($period);
        [$w, $wp] = self::timeScope($unitAdminId);

        return self::runScoped(
            "SELECT {$selectExpr} AS period_label, SUM(tl.hours) AS total_hours
             FROM time_logs tl
             WHERE tl.hours > 0 AND 1 = 1{$w}
             GROUP BY {$groupExpr}
             ORDER BY MIN(tl.log_date) DESC",
            $wp
        );
    }

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
                WHERE tl.hours > 0 AND tl.log_date BETWEEN :date_from AND :date_to";
        $params = ['date_from' => $dateFrom, 'date_to' => $dateTo];

        if (!empty($filters['project_id'])) {
            // Фільтр за проєктом включає й усі його підпроєкти — робота над
            // підпроєктом має рахуватись як робота над батьківським проєктом.
            $projectIds = Project::descendantIdsOf((int) $filters['project_id']);
            $placeholders = [];
            foreach ($projectIds as $i => $pid) {
                $key = "project_id_{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = $pid;
            }
            $sql .= ' AND p.id IN (' . implode(',', $placeholders) . ')';
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
            [$accessSql, $accessParams] = Project::accessCondition('p', 'vis', $viewerId);
            $sql .= ' AND ' . $accessSql;
            $params = array_merge($params, $accessParams);
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
                WHERE tl.hours > 0 AND tl.activity_category IS NOT NULL AND tl.activity_category <> ''";
        $params = [];

        if (!$isAdmin) {
            [$accessSql, $accessParams] = Project::accessCondition('p', 'vis', $viewerId);
            $sql .= ' AND ' . $accessSql;
            $params = array_merge($params, $accessParams);
        }
        $sql .= ' ORDER BY tl.activity_category ASC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_column($stmt->fetchAll(), 'activity_category');
    }

    /**
     * SQL-вирази для групування записів обліку часу за період.
     * Тиждень — ISO 8601 (понеділок — перший день, режим 3 у WEEK()),
     * щоб збігалося зі звичним "робочим тижнем", а не американським.
     *
     * @return array{0: string, 1: string} [вираз для SELECT, вираз для GROUP BY]
     */
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
             WHERE tl.hours > 0 AND t.project_id = :project_id
             GROUP BY {$groupExpr}, tl.user_id, u.full_name
             ORDER BY MIN(tl.log_date) DESC, total_hours DESC"
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /** Те саме, але по всій системі (для /admin/time). */
    public static function hoursByPeriodAndUserAll(string $period, ?int $unitAdminId = null): array
    {
        [$selectExpr, $groupExpr] = self::periodSql($period);
        [$w, $wp] = self::timeScope($unitAdminId);

        return self::runScoped(
            "SELECT {$selectExpr} AS period_label, u.full_name AS user_name, SUM(tl.hours) AS total_hours
             FROM time_logs tl
             JOIN users u ON u.id = tl.user_id
             WHERE tl.hours > 0 AND 1 = 1{$w}
             GROUP BY {$groupExpr}, tl.user_id, u.full_name
             ORDER BY MIN(tl.log_date) DESC, total_hours DESC",
            $wp
        );
    }

    /** Розбивка годин по періоду ТА проєкту одночасно, по всій системі (для /admin/time). */
    /** Те саме, що вище, але підпроєкт так само згорнутий у свій основний (кореневий) проєкт у межах кожного періоду. */
    public static function hoursByPeriodAndProjectAll(string $period, ?int $unitAdminId = null): array
    {
        [$selectExpr, $groupExpr] = self::periodSql($period);
        [$w, $wp] = self::projectScope($unitAdminId, 't.project_id');

        $raw = self::runScoped(
            "SELECT {$selectExpr} AS period_label, MIN(tl.log_date) AS min_date,
                    p.id AS project_id, p.name AS project_name, SUM(tl.hours) AS total_hours
             FROM time_logs tl
             JOIN tasks t ON t.id = tl.task_id
             JOIN projects p ON p.id = t.project_id
             WHERE tl.hours > 0 AND 1 = 1{$w}
             GROUP BY {$groupExpr}, p.id, p.name",
            $wp
        );

        $rootMap = Project::rootProjectMap();

        $aggregated = [];
        foreach ($raw as $row) {
            $pid = (int) $row['project_id'];
            $root = $rootMap[$pid] ?? ['id' => $pid, 'name' => $row['project_name']];
            $key = $row['period_label'] . '|' . $root['id'];

            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'period_label' => $row['period_label'],
                    'project_id' => $root['id'],
                    'project_name' => $root['name'],
                    'total_hours' => 0.0,
                    'min_date' => $row['min_date'],
                ];
            }
            $aggregated[$key]['total_hours'] += (float) $row['total_hours'];
            $aggregated[$key]['min_date'] = min($aggregated[$key]['min_date'], $row['min_date']);
        }

        usort($aggregated, function ($a, $b) {
            return $a['min_date'] === $b['min_date']
                ? $b['total_hours'] <=> $a['total_hours']
                : strcmp($b['min_date'], $a['min_date']);
        });

        return array_map(
            fn($r) => ['period_label' => $r['period_label'], 'project_id' => $r['project_id'], 'project_name' => $r['project_name'], 'total_hours' => $r['total_hours']],
            array_values($aggregated)
        );
    }

    /** Чи прибирає статус термін виконання: «Вирішена» та будь-який завершальний (is_closed). */
    public static function statusClearsDue(int $statusId): bool
    {
        foreach (self::statuses() as $s) {
            if ((int) $s['id'] === $statusId) {
                return !empty($s['is_closed']) || $s['code'] === 'resolved';
            }
        }
        return false;
    }

    /** Чи є статус «закритим» (is_closed). */
    public static function statusIsClosed(int $statusId): bool
    {
        foreach (self::statuses() as $s) {
            if ((int) $s['id'] === $statusId) {
                return !empty($s['is_closed']);
            }
        }
        return false;
    }

    /** Текст помилки, якщо задачу не можна перевести в цей статус (закрити, поки є незакриті підзадачі); інакше null. */
    public static function statusChangeProblem(int $taskId, int $statusId): ?string
    {
        if (!TaskChecklist::ready() || !self::statusIsClosed($statusId)) {
            return null;
        }
        $open = TaskChecklist::openSubtaskCount($taskId);
        return $open > 0 ? 'Не можна закрити задачу, поки не закрито підзадачі (незакритих: ' . $open . ')' : null;
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
