<?php

namespace App\Core;

/**
 * Обгортка над config/config.php з кешуванням у межах одного запиту.
 * Без цього кожне місце, якому потрібен конфіг (БД, версія застосунку тощо),
 * повторно виконувало б require і заново парсило .env-файл.
 */
class Config
{
    private static ?array $data = null;

    /**
     * @param string $key Ключ верхнього рівня ('db', 'app') або через крапку ('app.version')
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$data === null) {
            self::$data = require __DIR__ . '/../../config/config.php';
        }

        $value = self::$data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
