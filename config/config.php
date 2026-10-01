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
            // Відкидається лише ОДНА парна обгортка лапок ("...", '...'). Раніше trim() зрізав усі лапки з країв,
            // і пароль, що закінчується символом " чи ', мовчки псувався. Пробіли та # усередині значення допустимі.
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }

    // Порожнє значення в .env = "не задано" (щоб MAIL_IMAP_PORT= не дало порт 0).
    $env = static fn(string $key, string $default = ''): string =>
        (($v = getenv($key)) !== false && $v !== '') ? $v : $default;

    // ssl | tls (STARTTLS) | none. «starttls» — природна назва для того самого, що й «tls». Будь-яке ІНШЕ значення
    // лишається як є й відхиляється перевіркою EmailTicketService::configProblem() — воно НЕ повинно мовчки
    // перетворюватись на з'єднання без шифрування (пароль скриньки пішов би відкритим текстом).
    $mailEncryptionRaw = strtolower($env('MAIL_IMAP_ENCRYPTION', 'ssl'));
    $mailEncryption = ['starttls' => 'tls'][$mailEncryptionRaw] ?? $mailEncryptionRaw;

    // SMTP для вихідної пошти (автовідповідь заявнику з посиланням для відстеження).
    // Явно не заданий MAIL_SMTP_* успадковує відповідний MAIL_IMAP_* — на практиці це
    // та сама поштова скринька (той самий логін/пароль/сервер), і дублювати налаштування
    // в .env не потрібно. Порт SMTP типово ІНШИЙ за IMAP навіть на тому самому сервері,
    // тож MAIL_IMAP_PORT сюди НЕ успадковується.
    $smtpEncryptionRaw = strtolower($env('MAIL_SMTP_ENCRYPTION', 'tls'));
    $smtpEncryption = ['starttls' => 'tls'][$smtpEncryptionRaw] ?? $smtpEncryptionRaw;
    $smtpHost = $env('MAIL_SMTP_HOST', $env('MAIL_IMAP_HOST'));
    $smtpUsername = $env('MAIL_SMTP_USERNAME', $env('MAIL_IMAP_USERNAME'));
    $smtpPassword = $env('MAIL_SMTP_PASSWORD', $env('MAIL_IMAP_PASSWORD'));

    return [
        'db' => [
            'host'    => getenv('DB_HOST') ?: '127.0.0.1',
            'port'    => getenv('DB_PORT') ?: '3306',
            'database'=> getenv('DB_DATABASE') ?: 'itsm',
            'username'=> getenv('DB_USERNAME') ?: 'itsm_user',
            'password'=> getenv('DB_PASSWORD') ?: 'change_me',
            'charset' => 'utf8mb4',
        ],
        // Підключення до поштової скриньки для email-to-ticket (IMAP). Пароль — лише тут, у .env, не в БД.
        'mail' => [
            'host'        => $env('MAIL_IMAP_HOST'),
            // Рядок, а не (int): приведення "IMAP" до int дало б 0 і мовчазну спробу підключитись до порту 0.
            // Коректність (ціле 1–65535) перевіряє EmailTicketService::configProblem().
            'port'        => $env('MAIL_IMAP_PORT', $mailEncryption === 'ssl' ? '993' : '143'),
            'encryption'  => $mailEncryption,
            'username'    => $env('MAIL_IMAP_USERNAME'),
            'password'    => $env('MAIL_IMAP_PASSWORD'),
            'folder'      => $env('MAIL_IMAP_FOLDER', 'INBOX'),
            // false — лише для внутрішніх серверів із самопідписаним сертифікатом
            'verify_cert' => filter_var($env('MAIL_IMAP_VERIFY_CERT', 'true'), FILTER_VALIDATE_BOOLEAN),
        ],
        // Вихідна пошта (SMTP) — наразі лише автовідповідь заявнику email-to-ticket.
        'smtp' => [
            'host'        => $smtpHost,
            'port'        => $env('MAIL_SMTP_PORT', $smtpEncryption === 'ssl' ? '465' : '587'),
            'encryption'  => $smtpEncryption,           // ssl | tls (STARTTLS) | none
            'username'    => $smtpUsername,
            'password'    => $smtpPassword,
            // Адреса та ім'я в заголовку From автовідповіді. За замовчуванням — сама скринька підтримки.
            'from_email'  => $env('MAIL_SMTP_FROM_EMAIL', $smtpUsername),
            'from_name'   => $env('MAIL_SMTP_FROM_NAME', 'ITSM Підтримка'),
            'verify_cert' => filter_var($env('MAIL_SMTP_VERIFY_CERT', 'true'), FILTER_VALIDATE_BOOLEAN),
        ],
        'app' => [
            'name'    => 'ITSM System',
            'version' => '0.2.0',
            'env'     => getenv('APP_ENV') ?: 'local', // local | production
            'url'     => getenv('APP_URL') ?: 'http://localhost:8000',
        ],
    ];
})();
