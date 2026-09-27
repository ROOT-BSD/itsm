<?php

namespace App\Models;

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
}
