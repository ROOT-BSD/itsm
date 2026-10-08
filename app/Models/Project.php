<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Unit;
use App\Services\NotificationService;

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
                    parent.name AS parent_name,
                    COALESCE(ot.open_count, 0) AS open_tasks_count,
                    COALESCE(sp.sub_count, 0) AS sub_projects_count
             FROM projects p
             JOIN users u ON u.id = p.created_by
             LEFT JOIN users r ON r.id = p.responsible_user_id
             LEFT JOIN projects parent ON parent.id = p.parent_id
             LEFT JOIN (
                 SELECT t.project_id, COUNT(*) AS open_count
                 FROM tasks t
                 JOIN task_statuses ts ON ts.id = t.status_id AND ts.is_closed = 0
                 GROUP BY t.project_id
             ) ot ON ot.project_id = p.id
             LEFT JOIN (
                 SELECT parent_id, COUNT(*) AS sub_count
                 FROM projects
                 WHERE parent_id IS NOT NULL AND status != 'closed'
                 GROUP BY parent_id
             ) sp ON sp.parent_id = p.id
             ORDER BY p.created_at DESC"
        )->fetchAll();
    }

    /** Основні (не підпроєкти) проєкти, видимі цьому користувачу, БЕЗ закритих — для головного списку `/projects`. Закриті — на сторінці «Архів». */
    public static function topLevelVisibleTo(int $userId, bool $isAdmin): array
    {
        $visible = self::allVisibleTo($userId, $isAdmin);
        $visibleIds = array_flip(array_map('intval', array_column($visible, 'id')));

        // Підпроєкти зазвичай показуються на сторінці основного проєкту. Але якщо користувач бачить підпроєкт,
        // а батьківський проєкт — ні (доступ надається кожному проєкту окремо), підпроєкт без цього нізвідки було б
        // відкрити зі списку, тож він потрапляє у список сам — із позначкою «Підпроєкт».
        return array_values(array_filter(
            $visible,
            fn(array $p): bool => $p['status'] !== 'closed'
                && (empty($p['parent_id']) || !isset($visibleIds[(int) $p['parent_id']]))
        ));
    }

    /** Лише закриті проєкти (основні й підпроєкти), видимі цьому користувачу — для сторінки «Архів». */
    public static function closedVisibleTo(int $userId, bool $isAdmin): array
    {
        return array_values(array_filter(
            self::allVisibleTo($userId, $isAdmin),
            fn(array $p): bool => $p['status'] === 'closed'
        ));
    }

    /**
     * Проєкти, видимі конкретному користувачу: адміністратор бачить усі,
     * решта — лише ті, де вони автор (created_by), відповідальний
     * (responsible_user_id) або явно доданий учасник (project_members).
     * Використовується замість all() усюди, де список показується не-адміну
     * (список проєктів, дашборд).
     */
    public static function allVisibleTo(int $userId, bool $isAdmin): array
    {
        if ($isAdmin) {
            return self::all();
        }

        [$accessSql, $accessParams] = self::accessCondition('p', 'acc', $userId);
        $stmt = Database::connection()->prepare(
            "SELECT p.*, u.full_name AS created_by_name, r.full_name AS responsible_name,
                    parent.name AS parent_name,
                    COALESCE(ot.open_count, 0) AS open_tasks_count,
                    COALESCE(sp.sub_count, 0) AS sub_projects_count
             FROM projects p
             JOIN users u ON u.id = p.created_by
             LEFT JOIN users r ON r.id = p.responsible_user_id
             LEFT JOIN projects parent ON parent.id = p.parent_id
             LEFT JOIN (
                 SELECT t.project_id, COUNT(*) AS open_count
                 FROM tasks t
                 JOIN task_statuses ts ON ts.id = t.status_id AND ts.is_closed = 0
                 GROUP BY t.project_id
             ) ot ON ot.project_id = p.id
             LEFT JOIN (
                 SELECT parent_id, COUNT(*) AS sub_count
                 FROM projects
                 WHERE parent_id IS NOT NULL AND status != 'closed'
                 GROUP BY parent_id
             ) sp ON sp.parent_id = p.id
             WHERE {$accessSql}
             ORDER BY p.created_at DESC"
        );
        $stmt->execute($accessParams);
        return $stmt->fetchAll();
    }

    /**
     * SQL-умова «цей користувач має доступ до проєкту» (для не-адміністратора): він автор, відповідальний або учасник.
     * ЄДИНЕ місце цього правила — його підставляють і Project, і Task, і дашборд, тож новий спосіб отримати доступ
     * додається лише тут. Імена параметрів мають унікальний префікс: в одному запиті умова інколи підставляється
     * кілька разів, а PDO не дозволяє повторювати іменований параметр.
     *
     * @param string $table псевдонім або ім'я таблиці projects у запиті ("p" чи "projects") — лише внутрішні константи, не ввід користувача
     * @param string $prefix унікальний у межах запиту префікс імен параметрів
     * @return array{0: string, 1: array<string, int>} [фрагмент SQL у дужках, параметри]
     */
    public static function accessCondition(string $table, string $prefix, int $userId): array
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $prefix)) {
            throw new \InvalidArgumentException('Недопустимий псевдонім таблиці чи префікс параметрів');
        }
        $params = ["{$prefix}1" => $userId, "{$prefix}2" => $userId];
        $sql = "({$table}.created_by = :{$prefix}1 OR {$table}.responsible_user_id = :{$prefix}2";
        // Якщо міграцію 021 ще не виконано (таблиці немає), учасників просто немає — доступ такий, як до появи функції.
        // Без цієї перевірки пропущений update.sh перетворював би на помилку 500 КОЖЕН запит, що перевіряє доступ до проєкту.
        if (self::membersTableExists()) {
            $sql .= " OR EXISTS (SELECT 1 FROM project_members pm_{$prefix} WHERE pm_{$prefix}.project_id = {$table}.id AND pm_{$prefix}.user_id = :{$prefix}3)";
            $params["{$prefix}3"] = $userId;
        }
        // Адміністратор підрозділу додатково бачить проєкти, де автор, відповідальний або учасник — з його AD OU.
        $unitParts = [];
        if (($c = Unit::userCondition("{$table}.created_by", "{$prefix}ua", $userId)) !== null) {
            $unitParts[] = $c[0];
            $params += $c[1];
        }
        if (($c = Unit::userCondition("{$table}.responsible_user_id", "{$prefix}ub", $userId)) !== null) {
            $unitParts[] = $c[0];
            $params += $c[1];
        }
        if (self::membersTableExists()
            && ($c = Unit::userCondition("pmu_{$prefix}.user_id", "{$prefix}uc", $userId)) !== null) {
            $unitParts[] = "EXISTS (SELECT 1 FROM project_members pmu_{$prefix} WHERE pmu_{$prefix}.project_id = {$table}.id AND {$c[0]})";
            $params += $c[1];
        }
        // …а також проєкти, де людина підрозділу — виконавець чи автор хоча б однієї задачі або вела облік часу:
        // «показувати все, що стосується підрозділу» (канбан, Гант, облік часу працюють через видимість проєкту).
        $ca = Unit::userCondition("tu_{$prefix}.assignee_id", "{$prefix}ud", $userId);
        $cb = Unit::userCondition("tu_{$prefix}.author_id", "{$prefix}ue", $userId);
        if ($ca !== null && $cb !== null) {
            $unitParts[] = "EXISTS (SELECT 1 FROM tasks tu_{$prefix} WHERE tu_{$prefix}.project_id = {$table}.id AND ({$ca[0]} OR {$cb[0]}))";
            $params += $ca[1] + $cb[1];
        }
        $cc = Unit::userCondition("tl_{$prefix}.user_id", "{$prefix}uf", $userId);
        if ($cc !== null) {
            $unitParts[] = "EXISTS (SELECT 1 FROM time_logs tl_{$prefix} JOIN tasks tk_{$prefix} ON tk_{$prefix}.id = tl_{$prefix}.task_id"
                . " WHERE tk_{$prefix}.project_id = {$table}.id AND {$cc[0]})";
            $params += $cc[1];
        }
        if ($unitParts) {
            $sql .= ' OR ' . implode(' OR ', $unitParts);
        }
        return [$sql . ')', $params];
    }

    private static ?bool $membersTableExists = null;

    /**
     * Чи створено таблицю учасників (міграція 021). Перевіряється раз на запит; відповідь «ні» означає, що на сервері
     * оновили файли, але не запустили update.sh — застосунок тоді працює без учасників і показує адміністратору підказку.
     */
    public static function membersTableExists(): bool
    {
        if (self::$membersTableExists === null) {
            $stmt = Database::connection()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_members'"
            );
            self::$membersTableExists = $stmt !== false && (int) $stmt->fetchColumn() > 0;
        }
        return self::$membersTableExists;
    }

    /** Чи бачить цей користувач цей проєкт: адмін / автор / відповідальний / доданий учасник. */
    public static function isVisibleTo(array $project, int $userId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }
        if ((int) $project['created_by'] === $userId
            || (int) ($project['responsible_user_id'] ?? 0) === $userId
            || self::isMember((int) $project['id'], $userId)) {
            return true;
        }
        // Адміністратор підрозділу: те саме правило, що й у списках (accessCondition) — одне джерело істини.
        if (Unit::scopeOu($userId) !== null) {
            [$accessSql, $accessParams] = self::accessCondition('p', 'iv', $userId);
            $stmt = Database::connection()->prepare("SELECT 1 FROM projects p WHERE p.id = :pid AND {$accessSql}");
            $stmt->execute($accessParams + ['pid' => (int) $project['id']]);
            return (bool) $stmt->fetchColumn();
        }
        return false;
    }

    // ------------------------------------------------------------------ учасники проєкту

    public static function isMember(int $projectId, int $userId): bool
    {
        if (!self::membersTableExists()) {
            return false;
        }
        $stmt = Database::connection()->prepare('SELECT 1 FROM project_members WHERE project_id = :project AND user_id = :user');
        $stmt->execute(['project' => $projectId, 'user' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Учасники проєкту (без автора й відповідального — вони мають доступ завжди й показуються окремо). @return array<int, array<string, mixed>> */
    public static function members(int $projectId): array
    {
        if (!self::membersTableExists()) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.full_name, u.email, u.is_active, pm.created_at, a.full_name AS added_by_name
             FROM project_members pm
             JOIN users u ON u.id = pm.user_id
             LEFT JOIN users a ON a.id = pm.added_by
             WHERE pm.project_id = :project
             ORDER BY u.full_name'
        );
        $stmt->execute(['project' => $projectId]);
        return $stmt->fetchAll();
    }

    /** Керувати складом учасників можуть адміністратор, автор і відповідальний проєкту; сам учасник — ні (права не розповзаються). */
    public static function canManageMembers(array $project, int $userId, bool $isAdmin): bool
    {
        return $isAdmin
            || (int) $project['created_by'] === $userId
            || (int) ($project['responsible_user_id'] ?? 0) === $userId;
    }

    /**
     * Кого можна додати: активні користувачі, які ще не мають доступу до цього проєкту
     * (крім автора, відповідального та вже доданих учасників).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function memberCandidates(array $project): array
    {
        if (!self::membersTableExists()) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.full_name, u.email
             FROM users u
             WHERE u.is_active = 1
               AND u.id <> :creator
               AND u.id <> :responsible
               AND NOT EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = :project AND pm.user_id = u.id)
             ORDER BY u.full_name'
        );
        $stmt->execute([
            'creator' => (int) $project['created_by'],
            'responsible' => (int) ($project['responsible_user_id'] ?? 0),
            'project' => (int) $project['id'],
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Надає користувачу доступ до проєкту.
     *
     * @return string 'added' — додано; 'exists' — уже учасник; 'owner' — автор чи відповідальний (мають доступ і так);
     *                'invalid' — такого активного користувача немає; 'unavailable' — таблицю учасників ще не створено (update.sh)
     */
    public static function addMember(int $projectId, int $userId, int $actingUserId): string
    {
        if (!self::membersTableExists()) {
            return 'unavailable';
        }
        $project = self::find($projectId);
        $user = $project ? User::findById($userId) : null;
        if (!$project || !$user || !(int) $user['is_active']) {
            return 'invalid';
        }
        if ((int) $project['created_by'] === $userId || (int) ($project['responsible_user_id'] ?? 0) === $userId) {
            return 'owner';
        }

        // INSERT IGNORE: два одночасні додавання того самого користувача не дадуть помилки, а лише один з них побачить «added».
        $stmt = Database::connection()->prepare('INSERT IGNORE INTO project_members (project_id, user_id, added_by) VALUES (:project, :user, :by)');
        $stmt->execute(['project' => $projectId, 'user' => $userId, 'by' => $actingUserId]);
        if ($stmt->rowCount() === 0) {
            return 'exists';
        }

        Audit::log('project', $projectId, 'member_added', $actingUserId, ['member_user_id' => $userId]);
        NotificationService::projectMemberAdded($projectId, $userId, $actingUserId);
        return 'added';
    }

    /** Забирає доступ в учасника. @return bool true — його було в учасниках */
    public static function removeMember(int $projectId, int $userId, int $actingUserId): bool
    {
        if (!self::membersTableExists()) {
            return false;
        }
        $stmt = Database::connection()->prepare('DELETE FROM project_members WHERE project_id = :project AND user_id = :user');
        $stmt->execute(['project' => $projectId, 'user' => $userId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        Audit::log('project', $projectId, 'member_removed', $actingUserId, ['member_user_id' => $userId]);
        return true;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT p.*, u.full_name AS created_by_name, r.full_name AS responsible_name,
                    parent.name AS parent_name
             FROM projects p
             JOIN users u ON u.id = p.created_by
             LEFT JOIN users r ON r.id = p.responsible_user_id
             LEFT JOIN projects parent ON parent.id = p.parent_id
             WHERE p.id = :id"
        );
        $stmt->execute(['id' => $id]);
        $project = $stmt->fetch();
        return $project ?: null;
    }

    /** Прямі підпроєкти цього проєкту (без вкладених онуків) — для розділу «Підпроєкти» на сторінці проєкту. */
    /** Прямі підпроєкти цього проєкту (без вкладених онуків), БЕЗ закритих — для розділу «Підпроєкти» на сторінці проєкту. Закриті — на сторінці «Архів». */
    public static function subProjectsOf(int $parentId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT p.*, r.full_name AS responsible_name, COALESCE(ot.open_count, 0) AS open_tasks_count
             FROM projects p
             LEFT JOIN users r ON r.id = p.responsible_user_id
             LEFT JOIN (
                 SELECT t.project_id, COUNT(*) AS open_count
                 FROM tasks t
                 JOIN task_statuses ts ON ts.id = t.status_id AND ts.is_closed = 0
                 GROUP BY t.project_id
             ) ot ON ot.project_id = p.id
             WHERE p.parent_id = :parent_id AND p.status != 'closed'
             ORDER BY p.created_at DESC"
        );
        $stmt->execute(['parent_id' => $parentId]);
        return $stmt->fetchAll();
    }

    /**
     * Мапа [id проєкту => ['id' => ID кореневого проєкту, 'name' => назва кореневого]]
     * для АБСОЛЮТНО всіх проєктів у системі — щоб «згорнути» підпроєкт до його
     * основного (найвищого в ієрархії) проєкту в загальних звітах. Основний
     * проєкт мапиться сам на себе. Один запит на всю таблицю замість запиту
     * на кожен рядок звіту (N+1) — таблиця проєктів невелика, тримати її в
     * пам'яті й пройтись по ланцюжку parent_id дешевше, ніж рекурсивний SQL.
     */
    public static function rootProjectMap(): array
    {
        $rows = Database::connection()->query('SELECT id, parent_id, name FROM projects')->fetchAll();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = ['parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null, 'name' => $row['name']];
        }

        $map = [];
        foreach ($byId as $id => $info) {
            $currentId = $id;
            $visited = [$currentId => true]; // захист від циклу, якщо він колись з'явиться напряму в БД
            while ($byId[$currentId]['parent_id'] !== null && isset($byId[$byId[$currentId]['parent_id']]) && !isset($visited[$byId[$currentId]['parent_id']])) {
                $currentId = $byId[$currentId]['parent_id'];
                $visited[$currentId] = true;
            }
            $map[$id] = ['id' => $currentId, 'name' => $byId[$currentId]['name']];
        }

        return $map;
    }

    /**
     * ID цього проєкту й УСІХ його підпроєктів на будь-яку глибину вкладеності
     * (обхід у ширину) — для звітів, де робота над підпроєктом має враховуватись
     * як робота над батьківським проєктом. Інтерфейс наразі створює підпроєкти
     * лише в один рівень, але метод коректно обробить і глибшу вкладеність,
     * якщо вона колись з'явиться (наприклад, через прямі зміни в БД).
     */
    /**
     * ID усіх проєктів, у яких є хоча б один прострочений етап дорожньої
     * карти (target_date у минулому, статус не «завершено») — один запит
     * для всього списку проєктів, а не по одному на кожен рядок.
     */
    public static function overdueMilestoneProjectIds(): array
    {
        $stmt = Database::connection()->query(
            "SELECT DISTINCT project_id FROM milestones
             WHERE status != 'completed' AND target_date IS NOT NULL AND target_date < CURDATE()"
        );
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Самі прострочені етапи конкретного проєкту — для попередження на сторінці проєкту. */
    public static function overdueMilestonesFor(int $projectId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM milestones
             WHERE project_id = :project_id AND status != 'completed' AND target_date IS NOT NULL AND target_date < CURDATE()
             ORDER BY target_date ASC"
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    public static function descendantIdsOf(int $projectId): array
    {
        $ids = [$projectId];
        $queue = [$projectId];

        while (!empty($queue)) {
            $currentId = array_shift($queue);
            $stmt = Database::connection()->prepare('SELECT id FROM projects WHERE parent_id = :parent_id');
            $stmt->execute(['parent_id' => $currentId]);

            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $childId) {
                $childId = (int) $childId;
                if (!in_array($childId, $ids, true)) {
                    $ids[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return $ids;
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
        NotificationService::projectCreated($projectId, $responsibleUserId ?: null, $createdBy);
        return $projectId;
    }

    public static function updateResponsible(int $id, ?int $responsibleUserId, int $actingUserId): void
    {
        // Читаємо ПОПЕРЕДНЄ значення до оновлення — щоб не слати сповіщення повторно,
        // якщо адміністратор просто зберіг форму з тим самим відповідальним.
        $previousStmt = Database::connection()->prepare('SELECT responsible_user_id FROM projects WHERE id = :id');
        $previousStmt->execute(['id' => $id]);
        $previousResponsibleUserId = $previousStmt->fetchColumn();
        $previousResponsibleUserId = $previousResponsibleUserId !== false && $previousResponsibleUserId !== null ? (int) $previousResponsibleUserId : null;

        $stmt = Database::connection()->prepare(
            'UPDATE projects SET responsible_user_id = :responsible_user_id WHERE id = :id'
        );
        $stmt->execute(['responsible_user_id' => $responsibleUserId ?: null, 'id' => $id]);
        Audit::log('project', $id, 'responsible_changed', $actingUserId, ['responsible_user_id' => $responsibleUserId]);
        NotificationService::projectResponsibleChanged($id, $responsibleUserId ?: null, $previousResponsibleUserId, $actingUserId);
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

        // Видалення проєкту каскадно видаляє його задачі, а з ними — рядки їхніх вкладень у БД.
        // Файли на диску так не зникнуть, тож імена збираємо заздалегідь (див. Task::delete).
        $attachmentFiles = Attachment::storedNamesForProject($id);

        $stmt = Database::connection()->prepare('DELETE FROM projects WHERE id = :id');
        $stmt->execute(['id' => $id]);

        \App\Services\AttachmentService::deleteFiles($attachmentFiles);

        return true;
    }
}
