<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    /** @var array<string, bool> */
    private static array $tableCache = [];
    /** @var array<string, bool> */
    private static array $columnCache = [];

    /**
     * Чи існує таблиця (одна перевірка на запит). Потрібне функціям, що з'явились міграцією: якщо адміністратор
     * оновив файли, але не запустив update.sh, вони мають вимкнутись із поясненням, а не валити весь сайт помилкою 500.
     */
    public static function tableExists(string $table): bool
    {
        if (!isset(self::$tableCache[$table])) {
            $stmt = self::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
            );
            $stmt->execute(['t' => $table]);
            self::$tableCache[$table] = (int) $stmt->fetchColumn() > 0;
        }
        return self::$tableCache[$table];
    }

    /** Чи існує колонка в таблиці (одна перевірка на запит) — для функцій, що з'явились міграцією. */
    public static function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!isset(self::$columnCache[$key])) {
            $stmt = self::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
            );
            $stmt->execute(['t' => $table, 'c' => $column]);
            self::$columnCache[$key] = (int) $stmt->fetchColumn() > 0;
        }
        return self::$columnCache[$key];
    }

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $db = Config::get('db');

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $db['host'],
                $db['port'],
                $db['database'],
                $db['charset']
            );

            try {
                self::$instance = new PDO($dsn, $db['username'], $db['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                // У production-режимі деталі помилки БД користувачу не показуємо
                if (Config::get('app.env', 'local') === 'production') {
                    error_log('DB connection error: ' . $e->getMessage());
                    http_response_code(500);
                    exit('Помилка з\'єднання з базою даних. Зверніться до адміністратора.');
                }
                throw $e;
            }
        }

        return self::$instance;
    }
}
