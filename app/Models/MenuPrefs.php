<?php

namespace App\Models;

use App\Core\Access;
use App\Core\Database;

/** Особисті налаштування верхнього меню (міграція 048): зберігаються лише приховані пункти. */
class MenuPrefs
{
    /** ключ => [адреса, назва]; порядок — як у меню */
    public const ITEMS = [
        'assets' => ['/it-assets', 'Активи'],
        'archive' => ['/archive', 'Архів'],
        'wiki' => ['/wiki', 'Вікі'],
        'library' => ['/library', 'Документи'],
        'forum' => ['/forum', 'Форум'],
    ];

    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                Database::connection()->query('SELECT 1 FROM user_menu_prefs LIMIT 1');
                $ready = true;
            } catch (\Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    /** Пункти, доступні ролі (Активи — лише тим, хто має доступ до реєстру): ключ => назва. */
    public static function selectable(?string $role): array
    {
        $out = [];
        foreach (self::ITEMS as $key => [, $label]) {
            if ($key === 'assets' && !Access::canUseAssets($role)) {
                continue;
            }
            $out[$key] = $label;
        }
        return $out;
    }

    /** @return string[] ключі прихованих пунктів */
    public static function hidden(int $userId): array
    {
        static $cache = [];
        if (!self::ready() || $userId <= 0) {
            return [];
        }
        if (!isset($cache[$userId])) {
            $stmt = Database::connection()->prepare('SELECT item FROM user_menu_prefs WHERE user_id = :u');
            $stmt->execute(['u' => $userId]);
            $cache[$userId] = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }
        return $cache[$userId];
    }

    /** @return array<string, string> адреса => назва для відображення в меню (екрановані) */
    public static function visibleItems(?string $role, int $userId): array
    {
        $hidden = self::hidden($userId);
        $out = [];
        foreach (self::selectable($role) as $key => $label) {
            if (!in_array($key, $hidden, true)) {
                $out[self::ITEMS[$key][0]] = $label;
            }
        }
        return $out;
    }

    /** @param string[] $hide ключі, які треба приховати (решта показується) */
    public static function save(int $userId, array $hide): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM user_menu_prefs WHERE user_id = :u')->execute(['u' => $userId]);
        $ins = $db->prepare('INSERT IGNORE INTO user_menu_prefs (user_id, item) VALUES (:u, :i)');
        foreach ($hide as $key) {
            if (isset(self::ITEMS[$key])) {
                $ins->execute(['u' => $userId, 'i' => $key]);
            }
        }
    }
}
