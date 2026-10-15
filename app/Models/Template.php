<?php

namespace App\Models;

use App\Core\Database;

/** Шаблони проєктів і задач (міграція 039). Шаблон проєкту — набір задач; шаблон задачі — одна типова задача. */
class Template
{
    public const KINDS = ['project' => 'Шаблон проєкту', 'task' => 'Шаблон задачі'];
    public const MAX_ITEMS = 100;

    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $n = (int) Database::connection()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('templates','template_items')"
            )->fetchColumn();
            $ready = $n === 2 && TaskChecklist::ready();
        }
        return $ready;
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(?string $kind = null): array
    {
        $sql = 'SELECT t.*, u.full_name AS author_name, (SELECT COUNT(*) FROM template_items i WHERE i.template_id = t.id) AS items_count
                FROM templates t LEFT JOIN users u ON u.id = t.created_by';
        $params = [];
        if ($kind !== null) {
            $sql .= ' WHERE t.kind = :k';
            $params['k'] = $kind;
        }
        $stmt = Database::connection()->prepare($sql . ' ORDER BY t.kind, t.name');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM templates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function items(int $templateId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT i.*, tt.name AS type_name FROM template_items i LEFT JOIN task_types tt ON tt.id = i.type_id
             WHERE i.template_id = :t ORDER BY i.position, i.id'
        );
        $stmt->execute(['t' => $templateId]);
        return $stmt->fetchAll();
    }

    /**
     * Створює шаблон разом з пунктами в одній транзакції.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public static function create(string $kind, string $name, ?string $description, ?string $projectDescription, array $items, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO templates (kind, name, description, project_description, created_by) VALUES (:k, :n, :d, :pd, :u)')
                ->execute(['k' => $kind, 'n' => $name, 'd' => $description, 'pd' => $projectDescription, 'u' => $userId]);
            $id = (int) $pdo->lastInsertId();
            self::insertItems($id, $items);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        Audit::log('template', $id, 'created', $userId, ['name' => $name, 'kind' => $kind, 'items' => count($items)]);
        return $id;
    }

    /** @param array<int, array<string, mixed>> $items */
    public static function update(int $id, string $name, ?string $description, ?string $projectDescription, array $items, int $userId): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE templates SET name = :n, description = :d, project_description = :pd WHERE id = :id')
                ->execute(['n' => $name, 'd' => $description, 'pd' => $projectDescription, 'id' => $id]);
            $pdo->prepare('DELETE FROM template_items WHERE template_id = :id')->execute(['id' => $id]);
            self::insertItems($id, $items);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        Audit::log('template', $id, 'updated', $userId, ['name' => $name, 'items' => count($items)]);
    }

    public static function delete(int $id, string $name, int $userId): void
    {
        Audit::log('template', $id, 'deleted', $userId, ['name' => $name]);
        Database::connection()->prepare('DELETE FROM templates WHERE id = :id')->execute(['id' => $id]);
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function insertItems(int $templateId, array $items): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO template_items (template_id, position, title, body, type_id, priority, start_offset, due_offset, checklist, subtasks)
             VALUES (:t, :p, :title, :body, :type, :prio, :so, :do, :cl, :st)'
        );
        $pos = 0;
        foreach ($items as $it) {
            $stmt->execute([
                't' => $templateId, 'p' => $pos++, 'title' => $it['title'], 'body' => $it['body'] ?? null,
                'type' => $it['type_id'] ?? null, 'prio' => $it['priority'] ?? 'normal',
                'so' => $it['start_offset'] ?? null, 'do' => $it['due_offset'] ?? null,
                'cl' => $it['checklist'] ?? null, 'st' => $it['subtasks'] ?? null,
            ]);
        }
    }
}
