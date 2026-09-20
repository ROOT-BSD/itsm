<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

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
