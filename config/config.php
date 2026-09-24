<?php
/**
 * Базова конфігурація застосунку.
 *
 * Значення читаються через getenv(), але саме середовище (.env-файл)
 * не завжди потрапляє в PHP автоматично — це залежить від SAPI:
 *  - вбудований сервер (`php -S`) бачить лише те, що передано через
 *    `export`/`env` у тому ж шелі, звідки його запущено;
 *  - Apache + mod_php / PHP-FPM НЕ читають .env самі — їм потрібен
 *    SetEnv/env[] у конфізі, інакше getenv() поверне false.
 *
 * Щоб застосунок працював однаково в обох випадках без додаткового
 * налаштування веб-сервера, тут є мінімальний завантажувач .env:
 * якщо файл є в корені проєкту, його значення підвантажуються в
 * оточення (лише якщо змінна ще не задана ззовні — явний env має
 * пріоритет над .env).
 *
 * УВАГА: весь код обгорнутий у негайно викликану функцію (IIFE). Це
 * критично важливо, бо `require`/`include`, викликаний з середини
 * методу чи функції, виконується в ЛОКАЛЬНОМУ СКОУПІ того, хто його
 * викликав — тобто змінні нижче ($envFile, $key, $value, $line) без
 * цієї обгортки "протікали" б і затирали однойменні локальні змінні
 * в коді, що зробив require (саме так одного разу зламався
 * App\Core\Config::get(string $key) — параметр $key був мовчки
 * перезаписаний внутрішнім $key цього файлу).
 */

return (function (): array {
    $envFile = __DIR__ . '/../.env';
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, "\"'");

            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }

    return [
        'db' => [
            'host'    => getenv('DB_HOST') ?: '127.0.0.1',
            'port'    => getenv('DB_PORT') ?: '3306',
            'database'=> getenv('DB_DATABASE') ?: 'itsm',
            'username'=> getenv('DB_USERNAME') ?: 'itsm_user',
            'password'=> getenv('DB_PASSWORD') ?: 'change_me',
            'charset' => 'utf8mb4',
        ],
        'app' => [
            'name'    => 'ITSM System',
            'version' => '0.1.1',
            'env'     => getenv('APP_ENV') ?: 'local', // local | production
            'url'     => getenv('APP_URL') ?: 'http://localhost:8000',
        ],
    ];
})();
