<?php

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

class Setting
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $stmt = Database::connection()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    }

    public static function set(string $key, string $value): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = :value2'
        );
        $stmt->execute(['key' => $key, 'value' => $value, 'value2' => $value]);
    }

    /**
     * Публічна адреса застосунку (для посилань у листах тощо) — з інтерфейсу
     * (Адмін-панель → Налаштування → Пошта → тікети → Домен застосунку), якщо адміністратор її вказав, інакше APP_URL з .env.
     * Без кінцевого "/".
     */
    public static function appUrl(): string
    {
        $fromDb = trim((string) self::get('app_url', ''));
        $url = $fromDb !== '' ? $fromDb : (string) Config::get('app.url', '');
        return rtrim($url, '/');
    }
}
