<?php

namespace App\Models;

use App\Core\Database;

class Audit
{
    /** Українські підписи типів сутностей — для фільтра, таблиці й експорту журналу. */
    public const ENTITY_TYPE_LABELS = [
        'project' => 'Проєкт',
        'task' => 'Задача',
        'ticket' => 'Тікет',
        'wiki_page' => 'Сторінка вікі',
        'library_document' => 'Документ бібліотеки',
        'forum_topic' => 'Тема форуму',
        'forum_board' => 'Розділ форуму',
        'milestone' => 'Етап',
        'task_relation' => "Зв'язок задач",
        'template' => 'Шаблон',
        'ticket_queue' => 'Черга тікетів',
        'ticket_rule' => 'Правило черги тікетів',
        'asset' => 'Актив',
        'user' => 'Користувач',
        'app_settings' => 'Налаштування',
        'ad_group_role_mapping' => 'Відповідність AD-груп ролям',
        'backup' => 'Резервна копія',
    ];

    /** Підписи полів усередині JSON-колонки «changes». */
    public const CHANGE_KEY_LABELS = [
        'status_id' => 'Статус',
        'assignee_id' => 'Виконавець',
        'responsible_user_id' => 'Відповідальний',
        'member_user_id' => 'Учасник проєкту',
        'via' => 'Через',
        'operator_id' => 'Оператор',
        'default_operator_id' => 'Оператор за замовчуванням',
        'assigned_operator_id' => 'Призначений оператор',
        'milestone_id' => 'Етап',
        'related_task_id' => "Пов'язана задача",
        'relation_type' => "Тип зв'язку",
        'start_date' => 'Дата початку',
        'due_date' => 'Термін виконання',
        'name' => 'Назва',
        'title' => 'Назва',
        'visibility' => 'Хто бачить',
        'category_id' => 'Розділ',
        'versions' => 'Версій',
        'board' => 'Розділ',
        'post' => 'Повідомлення №',
        'replies' => 'Відповідей',
        'parent' => 'Батьківська сторінка №',
        'attachments' => 'Вкладень',
        'from_board' => 'З розділу',
        'to_board' => 'До розділу',
        'description_changed' => 'Опис змінено',
        'title_changed' => 'Назву змінено',
        'ticket' => 'Тікет №',
        'original' => 'Оригінал №',
        'duplicate' => 'Дубль №',
        'email' => 'Email',
        'first_response_minutes' => 'Перша відповідь (хв)',
        'resolution_minutes' => 'Вирішення (хв)',
        'email_ticket_enabled' => 'Обробка пошти',
        'ad_sync_enabled' => 'Синхронізація з AD',
        'ad_role_locked' => 'Роль AD закріплено',
        'old' => 'Було',
        'new' => 'Стало',
        'ad_host' => 'Сервер AD',
        'ad_encrypted' => 'Шифрування',
        'ad_port' => 'Порт AD',
        'principal' => 'Принципал SSO',
        'unit_ou' => 'Підрозділ',
        'ad_group' => 'Група AD',
        'ad_username' => 'Логін AD',
        'role_id' => 'Роль',
        'email_ticket_queue_id' => 'Черга для пошти (0 = перша)',
        'email_autoreply_enabled' => 'Автовідповідь заявнику',
        'email_notify_tickets_enabled' => 'Сповіщення про тікети',
        'email_notify_projects_enabled' => 'Сповіщення про проєкти',
        'email_notify_tasks_enabled' => 'Сповіщення про задачі',
        'email_notify_forum_enabled' => 'Сповіщення про форум',
        'email_notify_reminders_enabled' => 'Нагадування про термін',
        'app_url' => 'Домен застосунку',
        'error' => 'Помилка',
        'to' => 'Кому',
        'csat_score' => 'Оцінка CSAT',
        'slug' => 'Адреса',
        'version' => 'Версія',
        'restored_version' => 'Відновлено з версії',
        'file' => 'Файл',
        'size_kb' => 'Розмір, КБ',
        'from' => 'Відправник',
    ];

    /** Канал, яким виконано запит (наприклад, 'api'); додається до кожного запису цього запиту. Для вебу — null. */
    private static ?string $via = null;

    public static function setVia(?string $via): void
    {
        self::$via = $via;
    }

    /** Канал поточного запиту ('api' чи null для вебу) — його використовують і вебхуки, щоб події-коментарі теж мали позначку. */
    public static function via(): ?string
    {
        return self::$via;
    }

    public static function log(string $entityType, int $entityId, string $action, ?int $userId, ?array $changes = null): void
    {
        if (self::$via !== null) {
            $changes = ($changes ?? []) + ['via' => self::$via];
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO audit_log (entity_type, entity_id, action, user_id, changes)
             VALUES (:entity_type, :entity_id, :action, :user_id, :changes)'
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'user_id' => $userId,
            'changes' => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
        ]);

        // Журнал аудиту — єдина точка, через яку проходять усі зміни (веб, портал, пошта, API), тож саме тут ловимо події
        // для вебхуків: жоден шлях не доводиться правити окремо. Збій вебхуків не має впливати на основну дію.
        try {
            \App\Services\WebhookService::fromAudit($entityType, $entityId, $action, $userId, $changes);
        } catch (\Throwable $e) {
            error_log('[itsm] webhook hook failed: ' . $e->getMessage());
        }
    }

    /**
     * Записи журналу аудиту за фільтрами, з пагінацією — для сторінки
     * перегляду /admin/audit. Усі фільтри необов'язкові.
     *
     * @param array{entity_type?: string, user_id?: int, date_from?: string, date_to?: string} $filters
     */
    public static function filtered(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = self::buildWhere($filters);

        $stmt = Database::connection()->prepare(
            "SELECT a.*, u.full_name AS user_name
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             {$where}
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Загальна кількість записів за тими самими фільтрами — для розрахунку сторінок. */
    public static function countFiltered(array $filters): int
    {
        [$where, $params] = self::buildWhere($filters);

        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM audit_log a {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Список типів сутностей, які вже трапляються в журналі — для випадаючого списку фільтра. */
    public static function distinctEntityTypes(): array
    {
        return array_column(
            Database::connection()->query('SELECT DISTINCT entity_type FROM audit_log ORDER BY entity_type')->fetchAll(),
            'entity_type'
        );
    }

    /**
     * Назви самих сутностей (проєкт/задача/тікет/...) для показаних на сторінці
     * записів журналу — одним запитом на кожен тип, а не окремим запитом на
     * кожен рядок (N+1). Повертає [entity_type => [id => назва]].
     */
    public static function entityNamesFor(array $entries): array
    {
        $tableByType = [
            'project' => ['projects', 'name'],
            'task' => ['tasks', 'title'],
            'ticket' => ['tickets', 'subject'],
            'milestone' => ['milestones', 'title'],
            'ticket_queue' => ['ticket_queues', 'name'],
            'ticket_rule' => ['ticket_rules', 'name'],
            'asset' => ['assets', 'name'],
            'user' => ['users', 'full_name'],
            'library_document' => ['library_documents', 'title'],
            'forum_topic' => ['forum_topics', 'title'],
            'forum_board' => ['forum_boards', 'name'],
        ];

        $idsByType = [];
        foreach ($entries as $entry) {
            $idsByType[$entry['entity_type']][] = (int) $entry['entity_id'];
        }

        $names = [];
        foreach ($idsByType as $type => $ids) {
            if (!isset($tableByType[$type])) {
                continue;
            }
            [$table, $column] = $tableByType[$type];
            $names[$type] = self::lookupNames($table, $column, $ids);
        }
        return $names;
    }

    /**
     * Розшифровка ID-подібних полів усередині JSON-колонки `changes`
     * (наприклад, status_id → реальна назва статусу) — так само одним
     * запитом на кожне поле для всіх показаних записів одразу.
     * Повертає [назва_поля => [id => назва]].
     */
    public static function referencedNamesFor(array $entries): array
    {
        $tableByField = [
            'status_id' => ['task_statuses', 'name'],
            'assignee_id' => ['users', 'full_name'],
            'responsible_user_id' => ['users', 'full_name'],
            'operator_id' => ['users', 'full_name'],
            'member_user_id' => ['users', 'full_name'],
            'milestone_id' => ['milestones', 'title'],
            'related_task_id' => ['tasks', 'title'],
            'category_id' => ['library_categories', 'name'],
        ];

        $idsByField = [];
        foreach ($entries as $entry) {
            if (empty($entry['changes'])) {
                continue;
            }
            $decoded = json_decode($entry['changes'], true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $key => $value) {
                if (isset($tableByField[$key]) && $value !== null) {
                    $idsByField[$key][] = (int) $value;
                }
            }
        }

        $resolved = [];
        foreach ($idsByField as $field => $ids) {
            [$table, $column] = $tableByField[$field];
            $resolved[$field] = self::lookupNames($table, $column, $ids);
        }
        return $resolved;
    }

    /** @return array<int, string> [id => назва] */
    private static function lookupNames(string $table, string $column, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        // Таблиці, що з'явились у новіших версіях (бібліотека документів), можуть ще не існувати, якщо міграцію не застосовано:
        // сторінка журналу тоді просто показує номери замість назв, а не падає.
        if (empty($ids) || !Database::tableExists($table)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare("SELECT id, {$column} AS name FROM {$table} WHERE id IN ({$placeholders})");
        $stmt->execute($ids);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['id']] = $row['name'];
        }
        return $result;
    }

    /**
     * @param array{entity_type?: string, user_id?: int, date_from?: string, date_to?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        // Журнал підрозділу: дії людей підрозділу + події над їхніми обліковими записами (зміна ролі, блокування тощо).
        if (!empty($filters['unit_admin_id'])) {
            $c1 = \App\Core\Unit::userCondition('a.user_id', 'auu', (int) $filters['unit_admin_id']);
            $c2 = \App\Core\Unit::userCondition('a.entity_id', 'aue', (int) $filters['unit_admin_id']);
            if ($c1 === null || $c2 === null) {
                $conditions[] = '1 = 0';
            } else {
                $conditions[] = "({$c1[0]} OR (a.entity_type = 'user' AND {$c2[0]}))";
                $params += $c1[1] + $c2[1];
            }
        }
        if (!empty($filters['entity_type'])) {
            $conditions[] = 'a.entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }
        if (!empty($filters['user_id'])) {
            $conditions[] = 'a.user_id = :user_id';
            $params['user_id'] = $filters['user_id'];
        }
        if (!empty($filters['date_from'])) {
            $conditions[] = 'a.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $conditions[] = 'a.created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        return [$where, $params];
    }
}
