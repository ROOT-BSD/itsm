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
    /**
     * Синоніми типів для імпорту (CSV/XLSX): різні написання, російські форми, англійські назви, скорочення.
     * Порівняння без урахування регістру, зайвих пробілів і виду апострофа. Код типу й офіційний підпис розпізнаються й без цього списку.
     */
    public const TYPE_SYNONYMS = [
        'pc' => ['пк', 'пэвм', 'пеом', 'комп\'ютер', 'компьютер', 'системний блок', 'системный блок', 'десктоп', 'робоча станція', 'рабочая станция', 'моноблок', 'моноблок пк', 'workstation', 'desktop', 'computer', 'pc', 'mini pc', 'міні-пк'],
        'laptop' => ['ноутбук', 'ноут', 'нетбук', 'ультрабук', 'portable', 'laptop', 'notebook'],
        'server' => ['сервер', 'server', 'серверне обладнання', 'серверное оборудование', 'хост', 'rack server', 'схд', 'сховище'],
        'network' => ['мережеве обладнання', 'сетевое оборудование', 'мережа', 'комутатор', 'коммутатор', 'свіч', 'свич', 'switch', 'маршрутизатор', 'роутер', 'router', 'точка доступу', 'точка доступа', 'access point', 'ap', 'wi-fi', 'wifi', 'міжмережевий екран', 'фаєрвол', 'файрвол', 'firewall', 'модем', 'патч-панель', 'network'],
        'printer' => ['принтер', 'printer', 'мфп', 'мфу', 'mfp', 'принтер / мфп', 'сканер', 'scanner', 'копір', 'ксерокс', 'плотер', 'плоттер'],
        'monitor' => ['монітор', 'монитор', 'monitor', 'дисплей', 'display', 'екран', 'телевізор', 'панель'],
        'ups' => ['дбж', 'ибп', 'ups', 'безперебійник', 'бесперебойник', 'джерело безперебійного живлення', 'источник бесперебойного питания', 'стабілізатор'],
        'mobile' => ['телефон', 'смартфон', 'phone', 'smartphone', 'планшет', 'tablet', 'мобільний', 'мобильный', 'мобільний телефон', 'ip-телефон', 'ip телефон', 'телефон / планшет', 'mobile'],
        'software' => ['по', 'пз', 'пз / ліцензія', 'програмне забезпечення', 'программное обеспечение', 'ліцензія', 'лицензия', 'software', 'license', 'licence', 'програма', 'підписка', 'subscription'],
        'other' => ['інше', 'другое', 'прочее', 'other', 'різне', 'інші'],
    ];
    /** Синоніми статусів для імпорту. */
    public const STATUS_SYNONYMS = [
        'in_use' => ['використовується', 'у використанні', 'в використанні', 'в експлуатації', 'експлуатується', 'в роботі', 'у роботі', 'працює', 'активний', 'в использовании', 'используется', 'в эксплуатации', 'active', 'in use', 'in_use'],
        'in_stock' => ['на складі', 'склад', 'резерв', 'у резерві', 'в резерві', 'на хранении', 'на складе', 'в запасі', 'stock', 'in stock', 'spare', 'reserve'],
        'repair' => ['у ремонті', 'в ремонті', 'ремонт', 'на ремонті', 'на обслуговуванні', 'в ремонте', 'repair', 'in repair', 'maintenance'],
        'written_off' => ['списано', 'списаний', 'списаны', 'списан', 'утилізовано', 'вибракувано', 'не використовується', 'retired', 'written off', 'disposed', 'decommissioned'],
    ];

    /** Нормалізація підпису для порівняння: нижній регістр, єдиний апостроф, без зайвих пробілів. */
    public static function normLabel(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace(['’', 'ʼ', '`', '´', '′'], "'", $s);
        return (string) preg_replace('/\s+/u', ' ', $s);
    }

    /** Тип за кодом, підписом чи синонімом; null — невідомий. Спершу точний збіг, потім слово-синонім усередині («Принтер HP LaserJet»). */
    public static function resolveType(string $raw): ?string
    {
        return self::resolveWithSynonyms($raw, self::TYPES, self::TYPE_SYNONYMS);
    }

    public static function resolveStatus(string $raw): ?string
    {
        return self::resolveWithSynonyms($raw, self::STATUSES, self::STATUS_SYNONYMS, false);
    }

    /** @param array<string,string> $labels @param array<string,string[]> $synonyms */
    private static function resolveWithSynonyms(string $raw, array $labels, array $synonyms, bool $contains = true): ?string
    {
        $n = self::normLabel($raw);
        if ($n === '') {
            return null;
        }
        $exact = [];
        foreach ($labels as $code => $label) {
            $exact[self::normLabel($label)] = $code;
            $exact[$code] = $code;
        }
        foreach ($synonyms as $code => $list) {
            foreach ($list as $s) {
                $exact[self::normLabel($s)] = $code;
            }
        }
        if (isset($exact[$n])) {
            return $exact[$n];
        }
        if (!$contains) {
            return null;
        }
        // Слово-синонім як окреме слово всередині тексту; довші синоніми мають перевагу. Короткі (до 2 літер, напр. «ПО») — лише точним збігом.
        uksort($exact, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach ($exact as $syn => $code) {
            if (mb_strlen($syn) >= 3 && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($syn, '/') . '(?![\p{L}\p{N}])/u', $n)) {
                return $code;
            }
        }
        return null;
    }

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
        'warranty_until' => 'Гарантія до', 'license_seats' => 'Кількість місць', 'notes' => 'Примітки',
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
            'INSERT INTO assets (asset_type, name, inventory_number, serial_number, manufacturer, model, status, owner_user_id, location, network_id, purchase_date, warranty_until, license_seats, notes, created_by)
             VALUES (:asset_type, :name, :inventory_number, :serial_number, :manufacturer, :model, :status, :owner_user_id, :location, :network_id, :purchase_date, :warranty_until, :license_seats, :notes, :cb)'
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
             warranty_until = :warranty_until, license_seats = :license_seats, notes = :notes WHERE id = :id'
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
        $stmt = Database::connection()->prepare('DELETE FROM asset_links WHERE id = :l AND (asset_id = :a1 OR linked_id = :a2)');
        $stmt->execute(['l' => $linkId, 'a1' => $assetId, 'a2' => $assetId]);
        if ($stmt->rowCount() > 0) {
            Audit::log('asset', $assetId, 'link_removed', $userId, ['link' => $linkId]);
            return true;
        }
        return false;
    }

    // ---------- Ліцензії ПЗ ----------

    /**
     * SQL-вираз «скільки різних пристроїв мають це ПЗ». Рахуємо зв'язок «встановлено на» в обидва боки: ПЗ → пристрій (основний запис)
     * і пристрій → ПЗ (якщо зв'язок додали з картки пристрою навпаки); другий кінець — не ПЗ.
     */
    private static function installedSql(string $p1, string $p2, string $p3): string
    {
        return "(SELECT COUNT(DISTINCT l.linked_id) FROM asset_links l WHERE l.asset_id = $p1 AND l.relation = 'installed_on')
                + (SELECT COUNT(DISTINCT l2.asset_id) FROM asset_links l2 JOIN assets x ON x.id = l2.asset_id
                   WHERE l2.linked_id = $p2 AND l2.relation = 'installed_on' AND x.asset_type <> 'software'
                     AND l2.asset_id NOT IN (SELECT l3.linked_id FROM asset_links l3 WHERE l3.asset_id = $p3 AND l3.relation = 'installed_on'))";
    }

    /** Скільки пристроїв має це ПЗ встановленим (зв'язки «встановлено на», де ПЗ — перший актив). */
    public static function installedCount(int $softwareId): int
    {
        $stmt = Database::connection()->prepare('SELECT ' . self::installedSql(':id1', ':id2', ':id3'));
        $stmt->execute(['id1' => $softwareId, 'id2' => $softwareId, 'id3' => $softwareId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Облік ліцензій: усі картки ПЗ, видимі користувачу, з кількістю встановлень і станом.
     * Стан: over — встановлено більше, ніж місць; expired — строк минув; expiring — ≤ 60 днів; free — є вільні місця; ok.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function licenses(?string $role, int $userId, string $q = ''): array
    {
        if (!self::ready()) {
            return [];
        }
        [$w, $p] = self::where(['type' => 'software', 'q' => $q], $role, $userId);
        $stmt = Database::connection()->prepare(
            "SELECT a.*, o.full_name AS owner_name,
                    (" . self::installedSql('a.id', 'a.id', 'a.id') . ") AS installed
             FROM assets a LEFT JOIN users o ON o.id = a.owner_user_id" . $w . ' ORDER BY a.name, a.id LIMIT 2000'
        );
        $stmt->execute($p);
        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime('+60 days'));
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $seats = $r['license_seats'] !== null ? (int) $r['license_seats'] : null;
            $inst = (int) $r['installed'];
            $active = $r['status'] !== 'written_off';
            $r['installed'] = $inst;
            $r['free'] = $seats !== null ? $seats - $inst : null;
            if ($active && $seats !== null && $inst > $seats) {
                $r['state'] = 'over';
            } elseif ($active && $r['warranty_until'] && $r['warranty_until'] < $today) {
                $r['state'] = 'expired';
            } elseif ($active && $r['warranty_until'] && $r['warranty_until'] <= $soon) {
                $r['state'] = 'expiring';
            } elseif ($active && $seats !== null && $seats - $inst > 0) {
                $r['state'] = 'free';
            } else {
                $r['state'] = 'ok';
            }
            $rows[] = $r;
        }
        return $rows;
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
