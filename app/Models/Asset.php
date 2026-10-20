<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Unit;

/**
 * Реєстр обладнання й ПЗ (CMDB-мінімум, міграція 047): картки активів, зв'язки між ними, прив'язка до тікетів.
 * Історія змін — у журналі аудиту (entity_type = 'asset').
 *
 * Видимість: адміністратор, менеджер ІТ, оператор — усі активи; адміністратор підрозділу — активи, власник яких
 * з його підрозділу, і створені ним самим; решта ролей реєстру не бачить.
 */
class Asset
{
    public const TYPES = [
        'pc' => 'Комп\'ютер', 'laptop' => 'Ноутбук', 'server' => 'Сервер', 'network' => 'Мережеве обладнання',
        'printer' => 'Принтер / МФП', 'monitor' => 'Монітор', 'ups' => 'ДБЖ', 'mobile' => 'Телефон / планшет',
        'software' => 'ПЗ / ліцензія', 'other' => 'Інше',
    ];
    public const STATUSES = ['in_use' => 'Використовується', 'in_stock' => 'На складі', 'repair' => 'У ремонті', 'written_off' => 'Списано'];
    /** код => [прямий підпис, зворотний підпис] */
    public const RELATIONS = [
        'connected_to' => ['підключено до', 'до нього підключено'],
        'installed_on' => ['встановлено на', 'на ньому встановлено'],
        'part_of' => ['входить до складу', 'до складу входить'],
    ];
    public const FIELD_LABELS = [
        'asset_type' => 'Тип', 'name' => 'Назва', 'inventory_number' => 'Інвентарний №', 'serial_number' => 'Серійний №',
        'manufacturer' => 'Виробник', 'model' => 'Модель', 'status' => 'Статус', 'owner_user_id' => 'Власник',
        'location' => 'Місце', 'network_id' => 'ІП', 'purchase_date' => 'Дата придбання',
        'warranty_until' => 'Гарантія до', 'notes' => 'Примітки',
    ];

    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                Database::connection()->query('SELECT 1 FROM assets LIMIT 1');
                $ready = true;
            } catch (\Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    /** @return array{0: string, 1: array} додаткова умова видимості для запиту по таблиці assets з псевдонімом $a */
    public static function scopeSql(string $a, ?string $role, int $userId): array
    {
        if (in_array($role, ['admin', 'it_manager', 'support_operator'], true)) {
            return ['', []];
        }
        if ($role === Unit::ROLE) {
            $c = Unit::userCondition("{$a}.owner_user_id", 'asu', $userId);
            if ($c !== null) {
                return [" AND ({$c[0]} OR {$a}.created_by = :as_self)", $c[1] + ['as_self' => $userId]];
            }
            return [" AND {$a}.created_by = :as_self", ['as_self' => $userId]];
        }
        return [' AND 1 = 0', []];
    }

    public static function isVisible(array $asset, ?string $role, int $userId): bool
    {
        [$w, $p] = self::scopeSql('a', $role, $userId);
        if ($w === '') {
            return true;
        }
        $stmt = Database::connection()->prepare("SELECT 1 FROM assets a WHERE a.id = :id{$w}");
        $stmt->execute(['id' => (int) $asset['id']] + $p);
        return (bool) $stmt->fetchColumn();
    }

    private const SELECT = "SELECT a.*, o.full_name AS owner_name, o.email AS owner_email FROM assets a LEFT JOIN users o ON o.id = a.owner_user_id";

    /** @param array{q?: string, type?: string, status?: string, owner_id?: int, warranty?: string} $f */
    private static function where(array $f, ?string $role, int $userId): array
    {
        [$w, $p] = self::scopeSql('a', $role, $userId);
        $w = ' WHERE 1 = 1' . $w;
        if (!empty($f['q'])) {
            $w .= ' AND (' . implode(' OR ', array_map(fn($c, $i) => $c . ' LIKE :q' . $i, ['a.name','a.inventory_number','a.serial_number','a.model','a.manufacturer','a.network_id','a.location','o.full_name'], range(0, 7))) . ')';
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            for ($i = 0; $i < 8; $i++) {
                $p['q' . $i] = $like;
            }
        }
        if (!empty($f['type']) && isset(self::TYPES[$f['type']])) {
            $w .= ' AND a.asset_type = :type';
            $p['type'] = $f['type'];
        }
        if (!empty($f['status']) && isset(self::STATUSES[$f['status']])) {
            $w .= ' AND a.status = :status';
            $p['status'] = $f['status'];
        }
        if (!empty($f['owner_id'])) {
            $w .= ' AND a.owner_user_id = :owner';
            $p['owner'] = (int) $f['owner_id'];
        }
        if (($f['warranty'] ?? '') === 'expiring') {
            $w .= " AND a.status <> 'written_off' AND a.warranty_until IS NOT NULL AND a.warranty_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
        } elseif (($f['warranty'] ?? '') === 'expired') {
            $w .= " AND a.status <> 'written_off' AND a.warranty_until IS NOT NULL AND a.warranty_until < CURDATE()";
        }
        return [$w, $p];
    }

    public static function search(array $f, ?string $role, int $userId, int $limit = 50, int $offset = 0): array
    {
        if (!self::ready()) {
            return [];
        }
        [$w, $p] = self::where($f, $role, $userId);
        $stmt = Database::connection()->prepare(self::SELECT . $w . ' ORDER BY a.name, a.id LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset);
        $stmt->execute($p);
        return $stmt->fetchAll();
    }

    public static function count(array $f, ?string $role, int $userId): int
    {
        if (!self::ready()) {
            return 0;
        }
        [$w, $p] = self::where($f, $role, $userId);
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM assets a LEFT JOIN users o ON o.id = a.owner_user_id' . $w);
        $stmt->execute($p);
        return (int) $stmt->fetchColumn();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE a.id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByInventory(string $inv): ?array
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE a.inventory_number = :i');
        $stmt->execute(['i' => $inv]);
        return $stmt->fetch() ?: null;
    }

    /** @param array<string, mixed> $d значення полів з FIELD_LABELS (вже перевірені) */
    public static function create(array $d, int $userId): int
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO assets (asset_type, name, inventory_number, serial_number, manufacturer, model, status, owner_user_id, location, network_id, purchase_date, warranty_until, notes, created_by)
             VALUES (:asset_type, :name, :inventory_number, :serial_number, :manufacturer, :model, :status, :owner_user_id, :location, :network_id, :purchase_date, :warranty_until, :notes, :cb)'
        )->execute(self::params($d) + ['cb' => $userId]);
        $id = (int) $db->lastInsertId();
        Audit::log('asset', $id, 'created', $userId, ['name' => $d['name'], 'inventory_number' => $d['inventory_number'] ?? null]);
        return $id;
    }

    /** @return array<string, array{0: mixed, 1: mixed}> змінені поля [було, стало] */
    public static function update(int $id, array $d, int $userId): array
    {
        $old = self::find($id);
        $new = self::params($d);
        $diff = [];
        foreach (array_keys(self::FIELD_LABELS) as $k) {
            $o = $old[$k] ?? null;
            $n = $new[$k] ?? null;
            if ((string) $o !== (string) $n) {
                $diff[$k] = [$o, $n];
            }
        }
        if (!$diff) {
            return [];
        }
        Database::connection()->prepare(
            'UPDATE assets SET asset_type = :asset_type, name = :name, inventory_number = :inventory_number, serial_number = :serial_number, manufacturer = :manufacturer,
             model = :model, status = :status, owner_user_id = :owner_user_id, location = :location, network_id = :network_id, purchase_date = :purchase_date,
             warranty_until = :warranty_until, notes = :notes WHERE id = :id'
        )->execute($new + ['id' => $id]);
        Audit::log('asset', $id, 'updated', $userId, ['fields' => $diff]);
        return $diff;
    }

    private static function params(array $d): array
    {
        $out = [];
        foreach (array_keys(self::FIELD_LABELS) as $k) {
            $v = $d[$k] ?? null;
            $out[$k] = ($v === '' || $v === null) ? null : $v;
        }
        $out['asset_type'] = $out['asset_type'] ?? 'other';
        $out['status'] = $out['status'] ?? 'in_use';
        return $out;
    }

    public static function delete(int $id, int $userId, string $name): void
    {
        Database::connection()->prepare('DELETE FROM assets WHERE id = :id')->execute(['id' => $id]);
        Audit::log('asset', $id, 'deleted', $userId, ['name' => $name]);
    }

    // ---------- Зв'язки між активами ----------

    /** @return array[] [link_id, relation, label, other_id, other_name, other_inventory, direction] */
    public static function links(int $id): array
    {
        $db = Database::connection();
        $out = [];
        $stmt = $db->prepare('SELECT l.id AS link_id, l.relation, a.id AS other_id, a.name AS other_name, a.inventory_number AS other_inventory, a.asset_type AS other_type
                              FROM asset_links l JOIN assets a ON a.id = l.linked_id WHERE l.asset_id = :id ORDER BY a.name');
        $stmt->execute(['id' => $id]);
        foreach ($stmt->fetchAll() as $r) {
            $out[] = $r + ['label' => self::RELATIONS[$r['relation']][0]];
        }
        $stmt = $db->prepare('SELECT l.id AS link_id, l.relation, a.id AS other_id, a.name AS other_name, a.inventory_number AS other_inventory, a.asset_type AS other_type
                              FROM asset_links l JOIN assets a ON a.id = l.asset_id WHERE l.linked_id = :id ORDER BY a.name');
        $stmt->execute(['id' => $id]);
        foreach ($stmt->fetchAll() as $r) {
            $out[] = $r + ['label' => self::RELATIONS[$r['relation']][1]];
        }
        return $out;
    }

    public static function addLink(int $id, int $otherId, string $relation, int $userId): bool
    {
        if (!isset(self::RELATIONS[$relation]) || $id === $otherId) {
            return false;
        }
        $stmt = Database::connection()->prepare('INSERT IGNORE INTO asset_links (asset_id, linked_id, relation, created_by) VALUES (:a, :b, :r, :u)');
        $stmt->execute(['a' => $id, 'b' => $otherId, 'r' => $relation, 'u' => $userId]);
        if ($stmt->rowCount() > 0) {
            Audit::log('asset', $id, 'link_added', $userId, ['relation' => self::RELATIONS[$relation][0], 'asset' => $otherId]);
            return true;
        }
        return false;
    }

    public static function removeLink(int $assetId, int $linkId, int $userId): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM asset_links WHERE id = :l AND (asset_id = :a OR linked_id = :a)');
        $stmt->execute(['l' => $linkId, 'a' => $assetId]);
        if ($stmt->rowCount() > 0) {
            Audit::log('asset', $assetId, 'link_removed', $userId, ['link' => $linkId]);
            return true;
        }
        return false;
    }

    // ---------- Тікети ----------

    public static function ticketsOf(int $assetId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT t.id, t.subject, t.status, t.created_at FROM asset_tickets x JOIN tickets t ON t.id = x.ticket_id WHERE x.asset_id = :a ORDER BY t.created_at DESC, t.id DESC'
        );
        $stmt->execute(['a' => $assetId]);
        return $stmt->fetchAll();
    }

    /** Активи, прив'язані до тікета (без перевірки видимості — її робить викликач). */
    public static function assetsOfTicket(int $ticketId): array
    {
        if (!self::ready()) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT a.id, a.name, a.inventory_number, a.asset_type, a.status FROM asset_tickets x JOIN assets a ON a.id = x.asset_id WHERE x.ticket_id = :t ORDER BY a.name'
        );
        $stmt->execute(['t' => $ticketId]);
        return $stmt->fetchAll();
    }

    public static function linkTicket(int $assetId, int $ticketId, int $userId): bool
    {
        $stmt = Database::connection()->prepare('INSERT IGNORE INTO asset_tickets (asset_id, ticket_id, created_by) VALUES (:a, :t, :u)');
        $stmt->execute(['a' => $assetId, 't' => $ticketId, 'u' => $userId]);
        if ($stmt->rowCount() > 0) {
            Audit::log('asset', $assetId, 'ticket_linked', $userId, ['ticket' => $ticketId]);
            Audit::log('ticket', $ticketId, 'asset_linked', $userId, ['asset' => $assetId]);
            return true;
        }
        return false;
    }

    public static function unlinkTicket(int $assetId, int $ticketId, int $userId): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM asset_tickets WHERE asset_id = :a AND ticket_id = :t');
        $stmt->execute(['a' => $assetId, 't' => $ticketId]);
        if ($stmt->rowCount() > 0) {
            Audit::log('asset', $assetId, 'ticket_unlinked', $userId, ['ticket' => $ticketId]);
            Audit::log('ticket', $ticketId, 'asset_unlinked', $userId, ['asset' => $assetId]);
            return true;
        }
        return false;
    }

    // ---------- Історія й довідники ----------

    public static function history(int $id, int $limit = 100): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT a.*, u.full_name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.entity_type = 'asset' AND a.entity_id = :id ORDER BY a.created_at DESC, a.id DESC LIMIT " . (int) $limit
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetchAll();
    }

    /** Можливі власники для поточної ролі (для адміністратора підрозділу — лише його підрозділ). */
    public static function owners(?string $role, int $userId): array
    {
        $all = Database::connection()->query('SELECT id, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name')->fetchAll();
        if ($role !== Unit::ROLE) {
            return $all;
        }
        return array_values(array_filter($all, fn($u) => Unit::containsUser($userId, (int) $u['id'])));
    }
}
