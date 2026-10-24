<?php

namespace App\Models;

use App\Core\Database;

/**
 * Автоматичні правила для задач (міграція 055). Застосовує їх {@see \App\Services\TaskRuleService}.
 */
class TaskRule
{
    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                Database::connection()->query('SELECT 1 FROM task_rules LIMIT 1');
                $ready = true;
            } catch (\Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    private const SELECT = "SELECT r.*, p.name AS project_name, au.full_name AS action_assignee_name, ts.name AS when_status_name
                            FROM task_rules r
                            LEFT JOIN projects p ON p.id = r.project_id
                            LEFT JOIN users au ON au.id = r.action_assignee_id
                            LEFT JOIN task_statuses ts ON ts.id = r.when_status_id";

    public static function all(): array
    {
        return self::ready() ? Database::connection()->query(self::SELECT . ' ORDER BY r.sort_order, r.id')->fetchAll() : [];
    }

    /** Активні правила події у порядку перевірки. */
    public static function active(string $event): array
    {
        if (!self::ready()) {
            return [];
        }
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE r.is_active = 1 AND r.trigger_event = :e ORDER BY r.sort_order, r.id');
        $stmt->execute(['e' => $event]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE r.id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @param array<string, mixed> $d */
    public static function save(?int $id, array $d): int
    {
        $p = [
            'name' => $d['name'], 'sort' => $d['sort_order'], 'ev' => $d['trigger_event'], 'ws' => $d['when_status_id'], 'kw' => $d['keywords'], 'mi' => $d['match_in'],
            'pr' => $d['project_id'], 'cp' => $d['cond_priority'], 'ca' => $d['cond_assignee'], 'aa' => $d['action_assignee_id'], 'ap' => $d['action_priority'],
            'nsa' => $d['notify_system_assignee'] ? 1 : 0, 'nsu' => $d['notify_system_users'], 'act' => $d['is_active'] ? 1 : 0,
        ];
        $db = Database::connection();
        if ($id === null) {
            $db->prepare('INSERT INTO task_rules (name, sort_order, trigger_event, when_status_id, keywords, match_in, project_id, cond_priority, cond_assignee, action_assignee_id, action_priority, notify_system_assignee, notify_system_users, is_active, created_by)
                          VALUES (:name, :sort, :ev, :ws, :kw, :mi, :pr, :cp, :ca, :aa, :ap, :nsa, :nsu, :act, :cb)')->execute($p + ['cb' => $d['created_by']]);
            return (int) $db->lastInsertId();
        }
        $db->prepare('UPDATE task_rules SET name = :name, sort_order = :sort, trigger_event = :ev, when_status_id = :ws, keywords = :kw, match_in = :mi, project_id = :pr, cond_priority = :cp,
                      cond_assignee = :ca, action_assignee_id = :aa, action_priority = :ap, notify_system_assignee = :nsa, notify_system_users = :nsu, is_active = :act WHERE id = :id')->execute($p + ['id' => $id]);
        return $id;
    }

    public static function setActive(int $id, bool $active): void
    {
        Database::connection()->prepare('UPDATE task_rules SET is_active = :a WHERE id = :id')->execute(['a' => $active ? 1 : 0, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM task_rules WHERE id = :id')->execute(['id' => $id]);
    }

    public static function markHit(int $id): void
    {
        Database::connection()->prepare('UPDATE task_rules SET hits = hits + 1, last_hit_at = NOW() WHERE id = :id')->execute(['id' => $id]);
    }
}
