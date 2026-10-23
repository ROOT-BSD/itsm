<?php

namespace App\Services;

use App\Core\Unit;
use App\Models\Asset;

/**
 * Імпорт активів із CSV/XLSX і експорт у те саме представлення (колонки однакові, тож вивантажений файл можна відредагувати й завантажити назад).
 * Рядок з існуючим інвентарним номером ОНОВЛЮЄ картку (якщо вона вам доступна), інакше створює нову.
 */
class AssetImport
{
    public const MAX_ROWS = 2000;

    public const HEADERS = [
        'Тип', 'Назва', 'Інвентарний №', 'Серійний №', 'Виробник', 'Модель', 'Статус', 'Власник (email)', 'Місце',
        'ІП', 'Дата придбання', 'Гарантія до', 'Кількість місць', 'Примітки',
    ];
    private const KEYS = ['asset_type', 'name', 'inventory_number', 'serial_number', 'manufacturer', 'model', 'status', 'owner', 'location', 'network_id', 'purchase_date', 'warranty_until', 'license_seats', 'notes'];

    /** Колонки окремого імпорту ПЗ (тип завжди «ПЗ»). */
    public const HEADERS_SW = [
        'Назва', 'Версія', 'Виробник', 'Інвентарний №', 'Ліцензійний ключ / серійний №', 'Статус', 'Власник (email)',
        'Встановлено на (інв. № пристрою)', 'Дата придбання', 'Ліцензія до', 'Кількість місць', 'Примітки',
    ];
    private const KEYS_SW = ['name', 'model', 'manufacturer', 'inventory_number', 'serial_number', 'status', 'owner', 'host', 'purchase_date', 'warranty_until', 'license_seats', 'notes'];

    /** Рядки для експорту (без заголовків). */
    public static function exportRows(array $assets): array
    {
        $rows = [];
        foreach ($assets as $a) {
            $rows[] = [
                Asset::TYPES[$a['asset_type']] ?? $a['asset_type'], $a['name'], $a['inventory_number'] ?? '', $a['serial_number'] ?? '',
                $a['manufacturer'] ?? '', $a['model'] ?? '', Asset::STATUSES[$a['status']] ?? $a['status'], $a['owner_email'] ?? '',
                $a['location'] ?? '', $a['network_id'] ?? '', $a['purchase_date'] ?? '', $a['warranty_until'] ?? '', $a['license_seats'] ?? '', $a['notes'] ?? '',
            ];
        }
        return $rows;
    }

    /** @return string[][] таблиця з файлу (перший рядок — заголовки) */
    public static function readFile(string $path, string $origName): array
    {
        if (preg_match('/\.xlsx$/i', $origName)) {
            return XlsxReader::read($path);
        }
        if (!preg_match('/\.(csv|txt)$/i', $origName)) {
            throw new \RuntimeException('Потрібен файл .xlsx або .csv.');
        }
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        }
        $first = strtok($raw, "\n") ?: '';
        $delim = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            if ($r === [null] || trim(implode('', $r)) === '') {
                continue;
            }
            $rows[] = array_map(static fn($v) => (string) $v, $r);
        }
        fclose($fh);
        return $rows;
    }

    /**
     * @return array{created: int, updated: int, errors: array<int, array{0: int, 1: string}>}
     */
    public static function import(array $table, ?string $role, int $userId, bool $software = false): array
    {
        $headers = $software ? self::HEADERS_SW : self::HEADERS;
        $keys = $software ? self::KEYS_SW : self::KEYS;
        $res = ['created' => 0, 'updated' => 0, 'errors' => []];
        if (count($table) < 2) {
            throw new \RuntimeException('У файлі немає рядків з даними (потрібен рядок заголовків і хоча б один запис).');
        }
        if (count($table) - 1 > self::MAX_ROWS) {
            throw new \RuntimeException('Забагато рядків: не більше ' . self::MAX_ROWS . ' за один імпорт.');
        }
        $head = array_map(static fn($h) => mb_strtolower(trim((string) $h)), array_shift($table));
        $map = [];
        foreach ($headers as $i => $h) {
            $idx = array_search(mb_strtolower($h), $head, true);
            if ($idx === false && $h === 'ІП') {
                $idx = array_search('мережевий ідентифікатор', $head, true); // стара назва колонки
            }
            if ($idx !== false) {
                $map[$keys[$i]] = $idx;
            }
        }
        if (!isset($map['name'])) {
            throw new \RuntimeException('Не знайдено колонку «Назва». Використайте шаблон або експорт як зразок.');
        }
        $owners = [];
        foreach (Asset::owners($role, $userId) as $u) {
            $owners[mb_strtolower((string) $u['email'])] = (int) $u['id'];
        }

        foreach ($table as $n => $row) {
            $line = $n + 2;
            $get = static fn(string $k) => isset($map[$k]) ? trim((string) ($row[$map[$k]] ?? '')) : null;
            $name = (string) $get('name');
            if ($name === '') {
                $res['errors'][] = [$line, 'порожня назва'];
                continue;
            }
            $d = ['name' => mb_substr($name, 0, 200)];
            foreach (['inventory_number' => 80, 'serial_number' => 120, 'manufacturer' => 120, 'model' => 120, 'location' => 200, 'network_id' => 120] as $k => $len) {
                $v = $get($k);
                if ($v !== null) {
                    $d[$k] = mb_substr($v, 0, $len);
                }
            }
            if (($v = $get('notes')) !== null) {
                $d['notes'] = $v;
            }
            if (($v = $get('license_seats')) !== null) {
                if ($v === '') {
                    $d['license_seats'] = null;
                } elseif (ctype_digit($v) && (int) $v <= 1000000) {
                    $d['license_seats'] = (int) $v;
                } else {
                    $res['errors'][] = [$line, "кількість місць «{$v}» має бути цілим числом від 0 до 1 000 000"];
                    continue;
                }
            }
            if ($software) {
                $d['asset_type'] = 'software';
            }
            $host = null;
            if ($software && ($hostInv = (string) $get('host')) !== '') {
                $host = Asset::findByInventory($hostInv);
                if (!$host || !Asset::isVisible($host, $role, $userId)) {
                    $res['errors'][] = [$line, "пристрій з інв. № {$hostInv} не знайдено або він вам недоступний"];
                    continue;
                }
            }
            $type = $get('asset_type');
            if ($type !== null && $type !== '') {
                $code = Asset::resolveType($type);
                if ($code === null) {
                    $res['errors'][] = [$line, "невідомий тип «{$type}» (допустимі: " . implode(', ', Asset::TYPES) . '; також розпізнаються синоніми, напр. «Ноут», «Switch», «МФУ»)'];
                    continue;
                }
                $d['asset_type'] = $code;
            }
            $status = $get('status');
            if ($status !== null && $status !== '') {
                $code = Asset::resolveStatus($status);
                if ($code === null) {
                    $res['errors'][] = [$line, "невідомий статус «{$status}» (допустимі: " . implode(', ', Asset::STATUSES) . ')'];
                    continue;
                }
                $d['status'] = $code;
            }
            $bad = false;
            foreach (['purchase_date', 'warranty_until'] as $k) {
                $v = $get($k);
                if ($v === null) {
                    continue;
                }
                $iso = self::date($v);
                if ($iso === false) {
                    $res['errors'][] = [$line, "некоректна дата «{$v}» (потрібно РРРР-ММ-ДД або ДД.ММ.РРРР)"];
                    $bad = true;
                    break;
                }
                $d[$k] = $iso;
            }
            if ($bad) {
                continue;
            }
            $owner = $get('owner');
            if ($owner !== null) {
                if ($owner === '') {
                    $d['owner_user_id'] = null;
                } elseif (isset($owners[mb_strtolower($owner)])) {
                    $d['owner_user_id'] = $owners[mb_strtolower($owner)];
                } else {
                    $res['errors'][] = [$line, "власника з email «{$owner}» не знайдено (або він поза вашим підрозділом)"];
                    continue;
                }
            }

            try {
                $inv = $d['inventory_number'] ?? '';
                $existing = $inv !== '' ? Asset::findByInventory($inv) : null;
                if ($existing) {
                    if (!Asset::isVisible($existing, $role, $userId)) {
                        $res['errors'][] = [$line, "картка з інв. № {$inv} вам недоступна"];
                        continue;
                    }
                    // Не вказані в файлі колонки лишаються як були.
                    $merged = $d + array_intersect_key($existing, Asset::FIELD_LABELS);
                    if ($software && $existing['asset_type'] !== 'software') {
                        $res['errors'][] = [$line, "картка з інв. № {$inv} — не ПЗ"];
                        continue;
                    }
                    Asset::update((int) $existing['id'], $merged, $userId);
                    $res['updated']++;
                    $id = (int) $existing['id'];
                } else {
                    $id = Asset::create($d, $userId);
                    $res['created']++;
                }
                if ($host) {
                    Asset::addLink($id, (int) $host['id'], 'installed_on', $userId);
                }
            } catch (\Throwable $e) {
                $res['errors'][] = [$line, 'помилка збереження: ' . $e->getMessage()];
            }
        }
        return $res;
    }

    /** Рядки-приклад для шаблону ПЗ. */
    public static function softwareExample(): array
    {
        return [['Microsoft Office', '2021 LTSC', 'Microsoft', 'SW-0001', 'XXXXX-XXXXX-XXXXX', 'Використовується', 'user@example.com', 'INV-0001', '2025-03-01', '2028-03-01', '25', '']];
    }

    /** @return string|null|false ISO-дата, null для порожнього, false для некоректного */
    public static function date(string $v): string|null|false
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v) && ($t = strtotime(substr($v, 0, 10))) !== false && date('Y-m-d', $t) === substr($v, 0, 10)) {
            return substr($v, 0, 10);
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $v, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $v) && (float) $v > 20000 && (float) $v < 80000) {
            return gmdate('Y-m-d', (int) (((float) $v - 25569) * 86400));
        }
        return false;
    }
}
