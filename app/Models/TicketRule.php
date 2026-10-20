<?php

namespace App\Models;

use App\Core\Database;

/**
 * Автоматичні правила черги тікетів (міграція 046). Правила зберігаються тут, застосовує їх {@see \App\Services\TicketRuleService}.
 */
class TicketRule
{
    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                Database::connection()->query('SELECT 1 FROM ticket_rules LIMIT 1');
                $ready = true;
            } catch (\Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    private const SELECT = "SELECT r.*, fq.name AS from_queue_name, aq.name AS action_queue_name, ao.full_name AS action_operator_name,
                                   so.full_name AS scope_name
                            FROM ticket_rules r
                            LEFT JOIN ticket_queues fq ON fq.id = r.from_queue_id
                            LEFT JOIN ticket_queues aq ON aq.id = r.action_queue_id
                            LEFT JOIN users ao ON ao.id = r.action_operator_id
                            LEFT JOIN users so ON so.id = r.scope_user_id";

    /** @param int|null $scopeUserId null — усі правила (адміністратор системи); число — лише правила цього адміністратора підрозділу */
    public static function all(?int $scopeUserId = null): array
    {
        if (!self::ready()) {
            return [];
        }
        $sql = self::SELECT . ($scopeUserId !== null ? ' WHERE r.scope_user_id = :s' : '') . ' ORDER BY r.sort_order, r.id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($scopeUserId !== null ? ['s' => $scopeUserId] : []);
        return $stmt->fetchAll();
    }

    /** Активні правила у порядку перевірки. */
    public static function active(): array
    {
        if (!self::ready()) {
            return [];
        }
        return Database::connection()->query(self::SELECT . ' WHERE r.is_active = 1 ORDER BY r.sort_order, r.id')->fetchAll();
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
            'name' => $d['name'], 'scope' => $d['scope_user_id'], 'sort' => $d['sort_order'], 'fq' => $d['from_queue_id'],
            'kw' => $d['keywords'], 'mi' => $d['match_in'], 'aq' => $d['action_queue_id'], 'ao' => $d['action_operator_id'],
            'ne' => $d['notify_emails'], 'no' => $d['notify_operator'] ? 1 : 0, 'act' => $d['is_active'] ? 1 : 0,
        ];
        $db = Database::connection();
        if ($id === null) {
            $db->prepare('INSERT INTO ticket_rules (name, scope_user_id, sort_order, from_queue_id, keywords, match_in, action_queue_id, action_operator_id, notify_emails, notify_operator, is_active, created_by)
                          VALUES (:name, :scope, :sort, :fq, :kw, :mi, :aq, :ao, :ne, :no, :act, :cb)')->execute($p + ['cb' => $d['created_by']]);
            return (int) $db->lastInsertId();
        }
        $db->prepare('UPDATE ticket_rules SET name = :name, sort_order = :sort, from_queue_id = :fq, keywords = :kw, match_in = :mi,
                      action_queue_id = :aq, action_operator_id = :ao, notify_emails = :ne, notify_operator = :no, is_active = :act WHERE id = :id')
           ->execute(array_diff_key($p, ['scope' => 1]) + ['id' => $id]);
        return $id;
    }

    public static function setActive(int $id, bool $active): void
    {
        Database::connection()->prepare('UPDATE ticket_rules SET is_active = :a WHERE id = :id')->execute(['a' => $active ? 1 : 0, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM ticket_rules WHERE id = :id')->execute(['id' => $id]);
    }

    public static function markHit(int $id): void
    {
        Database::connection()->prepare('UPDATE ticket_rules SET hits = hits + 1, last_hit_at = NOW() WHERE id = :id')->execute(['id' => $id]);
    }
}
