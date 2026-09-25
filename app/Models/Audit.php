<?php

namespace App\Models;

use App\Core\Database;

class Audit
{
    public static function log(string $entityType, int $entityId, string $action, ?int $userId, ?array $changes = null): void
    {
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
            'user' => ['users', 'full_name'],
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
            'milestone_id' => ['milestones', 'title'],
            'related_task_id' => ['tasks', 'title'],
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
        if (empty($ids)) {
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
