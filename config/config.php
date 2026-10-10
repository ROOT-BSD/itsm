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
        // Active Directory: автентифікація користувачів із auth_source='ad' і фоновий
        // імпорт/синхронізація (bin/sync-ad-users.php). Значення атрибутів за замовчуванням —
        // як у реальному Active Directory (sAMAccountName, memberOf); для OpenLDAP чи іншого
        // сервера їх можна перевизначити через .env без зміни коду.
        'ad' => [
            'host'               => $env('AD_HOST'),
            'port'               => (int) $env('AD_PORT', '389'),
            'encryption'         => strtolower($env('AD_ENCRYPTION', 'none')), // none | starttls | ldaps
            'base_dn'            => $env('AD_BASE_DN'),
            // Службовий обліковий запис лише для ПОШУКУ користувачів (сам вхід — окремий bind як сам користувач).
            'bind_dn'            => $env('AD_BIND_DN'),
            'bind_password'      => $env('AD_BIND_PASSWORD'),
            'user_filter'        => $env('AD_USER_FILTER', '(&(objectClass=user)(sAMAccountName={username}))'),
            'sync_filter'        => $env('AD_SYNC_FILTER', '(&(objectClass=user)(mail=*))'),
            'username_attribute' => $env('AD_USERNAME_ATTR', 'sAMAccountName'),
            'email_attribute'    => $env('AD_EMAIL_ATTR', 'mail'),
            'name_attribute'     => $env('AD_NAME_ATTR', 'displayName'),
            'group_attribute'    => $env('AD_GROUP_ATTR', 'memberOf'),
            'verify_cert'        => filter_var($env('AD_VERIFY_CERT', 'true'), FILTER_VALIDATE_BOOLEAN),
            // Безпарольний вхід (SSO, Kerberos/SPNEGO). Саму автентифікацію виконує веб-сервер
            // (Apache mod_auth_gssapi на адресі /sso/login) і передає застосунку REMOTE_USER;
            // застосунок лише зіставляє його з AD-користувачем. Докладніше — docs/ADMIN_GUIDE.md.
            'sso_enabled'        => filter_var($env('AD_SSO_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
            // Очікуваний Kerberos-realm (напр. COMPANY.LOCAL). Якщо задано — принципал з іншим realm відхиляється.
            'sso_realm'          => strtoupper(trim((string) $env('AD_SSO_REALM', ''))),
        ],
        // Вкладення до тікетів і задач (App\Services\AttachmentService). Файли зберігаються
        // у storage/uploads — поза веб-коренем (public/), тож віддаються лише через контролер
        // з перевіркою прав. Реальний ліміт розміру — менше з цього значення та PHP-налаштувань
        // upload_max_filesize / post_max_size (див. App\Core\UploadLimits).
        'attachments' => [
            'max_bytes'       => max(1, (int) $env('ATTACHMENT_MAX_MB', '10')) * 1048576,
            'dir'             => __DIR__ . '/../storage/uploads',
            'max_per_entity'  => 30,  // не більше вкладень на один тікет/задачу
            'max_per_request' => 10,  // не більше файлів за одне натискання «Прикріпити»
            'thumb_px'        => 320, // найдовша сторона мініатюри зображення (створюється при завантаженні; потрібне розширення PHP GD)
            // Анонімний портал (/support): файли завантажує будь-хто без входу (частоту запитів обмежує rate_limits нижче),
            // тож ліміти суворіші, а вся можливість вимикається ATTACHMENT_PORTAL_ENABLED=false в .env.
            'portal_enabled'   => filter_var($env('ATTACHMENT_PORTAL_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
            'portal_max_files' => 3,
            'portal_max_bytes' => 5 * 1048576,
            // Зображення з вхідних листів (email-to-ticket).
            'email_max_images'       => 10,     // не більше картинок з одного листа
            'email_min_inline_bytes' => 10240,  // вбудовані (inline) зображення менші за це — зазвичай логотипи/іконки в підписах — не зберігаються
        ],
        // Бібліотека документів (розділ «Документи», App\Services\LibraryService): файли, не прив'язані до тікета чи задачі.
        // Реальний ліміт розміру — менше з цього значення та PHP-налаштувань upload_max_filesize / post_max_size.
        'library' => [
            'max_bytes'    => max(1, (int) $env('LIBRARY_MAX_MB', '25')) * 1048576,
            'max_versions' => 50,   // не більше версій одного документа (захист сховища від безкінечних завантажень)
            'per_page'     => 30,
        ],
        // Обмеження частоти запитів до публічного порталу /support (App\Core\RateLimiter), окремо для кожної IP-адреси.
        // Кожне правило — [скільки запитів, за скільки секунд]; дозволено, лише коли вкладаємось в усі правила дії.
        //   submit   — подання звернення (разом із завантаженням файлів);
        //   comment  — коментарі заявника на сторінці відстеження;
        //   rate     — оцінка якості (CSAT);
        //   badtoken — відкриття неіснуючого посилання відстеження (перебір токенів);
        //   forum_post — нові теми й відповіді на форумі (рахуються за користувачем, не за IP).
        // За балансувальником/зворотним проксі вкажіть його адресу в TRUSTED_PROXIES (через кому, IP або CIDR), інакше всі
        // відвідувачі матимуть адресу проксі й ділитимуть один ліміт. Вимкнути: PORTAL_RATE_LIMIT=false.
        'rate_limits' => [
            'enabled'         => filter_var($env('PORTAL_RATE_LIMIT', 'true'), FILTER_VALIDATE_BOOLEAN),
            'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) $env('TRUSTED_PROXIES', ''))))),
            'portal' => [
                'submit'   => [[10, 600], [30, 86400]],
                'comment'  => [[20, 600]],
                'rate'     => [[10, 600]],
                'badtoken' => [[30, 600]],
                'forum_post' => [[10, 300], [100, 86400]],
            ],
        ],
        // Вебхуки (див. App\Services\WebhookService). allow_private=false забороняє надсилати на внутрішні адреси
        // (10/8, 172.16/12, 192.168/16, 127/8) — захист від SSRF, якщо адмін-панель можуть налаштовувати не лише довірені люди.
        // Адреси link-local (169.254.0.0/16, у т.ч. метадані хмари) заборонені завжди.
        'webhooks' => [
            'allow_private' => filter_var($env('WEBHOOK_ALLOW_PRIVATE', 'true'), FILTER_VALIDATE_BOOLEAN),
        ],
        'app' => [
            'name'    => 'ITSM System',
            'version' => '0.2.3',
            'env'     => getenv('APP_ENV') ?: 'local', // local | production
            'url'     => getenv('APP_URL') ?: 'http://localhost:8000',
        ],
    ];
})();
