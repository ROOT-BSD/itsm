<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Models\Setting;

/**
 * Діагностика для сторінки «Стан системи»: зібрати в одному місці те, що адміністратор інакше шукає вручну
 * (версії, розширення PHP, схема БД, права на каталоги, чи працює cron, черга вебхуків, увімкнені можливості).
 *
 * Лише читання, без побічних дій. Кожна перевірка не повинна впасти: збій окремої перевірки стає рядком
 * «не вдалося перевірити», а не помилкою всієї сторінки. Рівні: ok | warn | fail | info.
 */
class SystemStatus
{
    /** Версії схеми: № міграції => [назва, перевірка]. Таблиці БД немає — перевіряємо наявність наслідків міграції. */
    private static function schemaChecks(): array
    {
        $table = fn(string $t) => fn() => self::tableExists($t);
        $col = fn(string $t, string $c) => fn() => self::columnExists($t, $c);
        return [
            '011' => ['Пошта → тікети (журнал листів)', $table('email_ingest_log')],
            '018' => ['Вкладення', $table('attachments')],
            '020' => ['Вікі', $table('wiki_pages')],
            '021' => ['Учасники проєктів', $table('project_members')],
            '022' => ['REST API і вебхуки', $table('webhook_endpoints')],
            '023' => ['Обмеження частоти запитів', $table('rate_limits')],
            '024' => ['Бібліотека документів', $table('library_versions')],
            '025' => ['Форум', $table('forum_posts')],
            '026' => ['Ієрархія вікі й вкладення вікі', $table('wiki_attachments')],
            '027' => ['Ручні перевизначення AD', $col('users', 'ad_role_locked')],
            '028' => ['objectGUID користувачів AD', $col('users', 'ad_guid')],
            '029' => ['Роль «Адміністратор Підрозділу»', fn() => (int) self::scalar("SELECT COUNT(*) FROM roles WHERE code = 'unit_admin'") > 0],
            '030' => ['Підрозділ локального користувача', $col('users', 'unit_ou')],
            '031' => ['Підписки на розділи форуму', $table('forum_board_subscriptions')],
            '032' => ['Видалення поля «Видимість» проєкту', fn() => !self::columnExists('projects', 'visibility')],
            '033' => ['Непрочитане на форумі', $table('forum_read_marks')],
            '034' => ['Вкладення форуму', $table('forum_attachments')],
            '035' => ['Відписка від теми форуму', $table('forum_topic_mutes')],
            '036' => ['Власні документи бібліотеки', $col('library_documents', 'unit_ou')],
            '037' => ['Office-вкладення в задачах', fn() => AttachmentService::officeReady()],
            '038' => ['Підзадачі та чек-листи', fn() => \App\Models\TaskChecklist::ready()],
            '039' => ['Шаблони проєктів і задач', $table('template_items')],
            '040' => ['Активні сесії та журнал входів', fn() => \App\Models\UserSession::ready()],
            '041' => ["Пов'язані та дубльовані тікети", $table('ticket_links')],
            '042' => ['Звіти за розкладом', $table('report_schedules')],
            '043' => ['Початкові терміни задач і їх історія', $table('task_date_changes')],
            '044' => ['Облік часу по тікетах', $table('ticket_time_logs')],
            '045' => ['Точність обліку часу по тікетах (хвилини)', $table('ticket_time_logs')],
            '046' => ['Автоматичні правила черги тікетів', $table('ticket_rules')],
            '051' => ['Умови правил черги за заявником і джерелом', static fn() => (int) \App\Core\Database::connection()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_rules' AND COLUMN_NAME = 'source'")->fetchColumn() > 0],
            '052' => ['Журнал робіт задачі', static fn() => (int) \App\Core\Database::connection()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'time_logs' AND COLUMN_NAME = 'edited_at'")->fetchColumn() > 0],
            '053' => ['Облік ліцензій ПЗ (кількість місць)', static fn() => (int) \App\Core\Database::connection()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets' AND COLUMN_NAME = 'license_seats'")->fetchColumn() > 0],
            '047' => ['Реєстр обладнання й ПЗ', $table('assets')],
            '048' => ['Особисті налаштування меню', $table('user_menu_prefs')],
            '050' => ['Статус «Вирішена» — відкритий (видно в проєкті)', static fn() => (int) \App\Core\Database::connection()->query("SELECT COUNT(*) FROM task_statuses WHERE code = 'resolved' AND is_closed = 1")->fetchColumn() === 0],
            '049' => ['Статус «Вирішена» лишає задачу видимою', static fn() => (int) \App\Core\Database::connection()->query("SELECT COUNT(*) FROM task_statuses WHERE code = 'resolved' AND is_closed = 1")->fetchColumn() === 0],
        ];
    }

    /** @return array<int, array{title: string, rows: array<int, array{label: string, value: string, level: string, hint?: string}>}> */
    public static function collect(bool $withSizes = false): array
    {
        $sections = [];
        foreach ([
            'Загальне' => 'general',
            'База даних' => 'database',
            'Схема БД (міграції)' => 'schema',
            'Розширення PHP' => 'extensions',
            'Каталоги й диск' => 'storage',
            'Фонові завдання (cron)' => 'jobs',
            'Можливості' => 'features',
            'Безпека' => 'security',
            'Дані' => 'counts',
        ] as $title => $method) {
            try {
                $rows = self::$method($withSizes);
            } catch (\Throwable $e) {
                $rows = [self::row('Перевірка', 'не вдалося виконати: ' . $e->getMessage(), 'fail')];
            }
            // Довгі розділи, де зазвичай усе гаразд, показуємо стисло: окремо лише рядки з проблемами, решта — під спойлером.
            $sections[] = ['title' => $title, 'rows' => $rows, 'collapse_ok' => $method === 'schema'];
        }
        return $sections;
    }

    /** Найгірший рівень серед усіх рядків: для підсумкового банера. */
    public static function overall(array $sections): string
    {
        $worst = 'ok';
        foreach ($sections as $s) {
            foreach ($s['rows'] as $r) {
                if ($r['level'] === 'fail') {
                    return 'fail';
                }
                if ($r['level'] === 'warn') {
                    $worst = 'warn';
                }
            }
        }
        return $worst;
    }

    // ---------- розділи ----------

    private static function general(): array
    {
        $tz = date_default_timezone_get();
        $url = (string) Config::get('app.url', '');
        $php = PHP_VERSION;
        return [
            self::row('Версія ITSM System', (string) Config::get('app.version', '?') . ' (файл VERSION: ' . trim((string) @file_get_contents(self::root() . '/VERSION')) . ')', 'info'),
            self::row('Середовище (APP_ENV)', (string) Config::get('app.env', 'local'), Config::get('app.env') === 'production' ? 'ok' : 'warn', 'У бойовій системі APP_ENV=production.'),
            self::row('PHP', $php . ' (' . PHP_SAPI . ')', version_compare($php, '8.3.0', '>=') ? 'ok' : 'warn', 'Рекомендовано PHP 8.3 і новіший.'),
            self::row('Операційна система', php_uname('s') . ' ' . php_uname('r'), 'info'),
            self::row('Час PHP', date('d.m.Y H:i:s') . ' (' . $tz . ', UTC' . date('P') . ')', $tz === 'UTC' && (string) Config::get('app.timezone') !== 'UTC' ? 'warn' : 'info', 'Задайте APP_TIMEZONE у .env (наприклад, Europe/Kyiv).'),
            self::row('Адреса застосунку (APP_URL)', $url, str_starts_with($url, 'https://') ? 'ok' : 'warn', 'Для бойової системи потрібна адреса з https://; її використовують листи й вебхуки.'),
            self::httpsRow($url),
            self::row('Opcache', function_exists('opcache_get_status') && ini_get('opcache.enable') ? 'увімкнено' : 'вимкнено', 'info', 'Після оновлення файлів перезавантажте PHP-FPM, інакше opcache віддаватиме стару версію коду.'),
        ];
    }

    private static function database(): array
    {
        $pdo = Database::connection();
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $coll = (string) self::scalar('SELECT @@collation_database');
        $size = (float) self::scalar('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()');
        $tables = (int) self::scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
        return [
            self::row('Підключення', 'працює', 'ok'),
            self::row('Сервер БД', $version, 'info'),
            self::row('База', $db . ', таблиць: ' . $tables, 'info'),
            self::row('Розмір даних', self::bytes($size), 'info'),
            self::timeRow($pdo),
            self::row('Кодування бази', $coll, str_starts_with($coll, 'utf8mb4') ? 'ok' : 'warn', 'Потрібне utf8mb4 (кирилиця й емодзі).'),
        ];
    }

    /** Час у БД проти часу PHP: розбіжність означає різні часові пояси, і мітки часу в системі «з'їдуть». */
    private static function timeRow(\PDO $pdo): array
    {
        $r = $pdo->query('SELECT NOW() AS now_db, @@session.time_zone AS sess, @@system_time_zone AS sys')->fetch();
        $diff = abs(strtotime((string) $r['now_db']) - time());
        $text = $r['now_db'] . ' (сесія: ' . $r['sess'] . ', система сервера БД: ' . $r['sys'] . ')';
        if ($diff > 120) {
            return self::row('Час БД', $text . '; розбіжність з PHP ≈ ' . round($diff / 60) . ' хв', 'warn', 'Час у БД і в PHP різний: перевірте APP_TIMEZONE у .env та налаштування часу сервера БД.');
        }
        return self::row('Час БД', $text, 'ok');
    }

    private static function schema(): array
    {
        $rows = [];
        $missing = 0;
        foreach (self::schemaChecks() as $num => [$name, $probe]) {
            try {
                $ok = (bool) $probe();
            } catch (\Throwable) {
                $ok = false;
            }
            if (!$ok) {
                $missing++;
            }
            $rows[] = self::row("Міграція {$num} — {$name}", $ok ? 'застосовано' : 'НЕ застосовано', $ok ? 'ok' : 'fail', $ok ? '' : 'Запустіть sudo bash update.sh — він виконає відсутні міграції.');
        }
        // Облік міграцій (schema_migrations, bin/migrate.php): скільки файлів ще не застосовано.
        try {
            $migrator = new Migrator();
            $pending = $migrator->pending();
            $rows[] = !$migrator->tableExists('schema_migrations')
                ? self::row('Журнал міграцій (schema_migrations)', 'облік ще не ведеться', 'warn', 'Запустіть sudo bash update.sh — він створить облік і застосує відсутні міграції.')
                : ($pending === []
                ? self::row('Журнал міграцій (schema_migrations)', 'усі файли міграцій застосовано', 'ok')
                : self::row('Журнал міграцій (schema_migrations)', 'не застосовано: ' . implode(', ', array_column($pending, 'version')), 'fail', 'Запустіть sudo bash update.sh або php bin/migrate.php.'));
        } catch (\Throwable) {
            $rows[] = self::row('Журнал міграцій (schema_migrations)', 'не вдалося перевірити', 'warn');
        }
        if ($missing > 0) {
            array_unshift($rows, self::row('Підсумок', "не застосовано міграцій: {$missing}", 'fail', 'Частина можливостей не працюватиме, доки не виконано update.sh.'));
        } else {
            array_unshift($rows, self::row('Підсумок', 'усі перевірені міграції застосовано', 'ok'));
        }
        return $rows;
    }

    private static function extensions(): array
    {
        // Лише те, що застосунок справді використовує. Не потрібні: imap (є власний IMAP-клієнт), curl (вебхуки йдуть через
        // потоки PHP), fileinfo (тип вкладень визначається за сигнатурою), zip (є власний читач/писач ZIP).
        $required = ['pdo_mysql' => 'з\'єднання з БД', 'mbstring' => 'робота з кирилицею', 'json' => 'JSON', 'ctype' => 'перевірки рядків', 'zlib' => 'стиснення, конвертер Office, експорт в Excel'];
        $optional = [
            'gd' => 'мініатюри зображень',
            'ldap' => 'Active Directory',
            'openssl' => 'шифроване з\'єднання (TLS) з поштою, вебхуками та іншими серверами',
        ];
        $rows = [];
        foreach ($required as $ext => $why) {
            $has = extension_loaded($ext);
            $rows[] = self::row("{$ext} (обов'язкове)", $has ? 'є' : 'немає', $has ? 'ok' : 'fail', $has ? '' : "Потрібне для: {$why}.");
        }
        foreach ($optional as $ext => $why) {
            $has = extension_loaded($ext);
            $used = match ($ext) {
                'ldap' => Setting::get('ad_sync_enabled', '0') === '1',
                'gd', 'openssl' => true,
                default => false,
            };
            $rows[] = self::row("{$ext}", $has ? 'є' : 'немає', $has ? 'ok' : ($used ? 'warn' : 'info'), $has ? '' : ucfirst($why) . '.');
        }
        return $rows;
    }

    private static function storage(bool $withSizes): array
    {
        $root = self::root() . '/storage';
        $dirs = [
            'storage/uploads' => AttachmentService::storageDir(),
            'storage/wiki' => (string) Config::get('wiki.dir', $root . '/wiki'),
            'storage/logs' => $root . '/logs',
        ];
        $rows = [];
        foreach ($dirs as $label => $path) {
            if (!is_dir($path)) {
                $rows[] = self::row($label, 'каталогу немає (' . $path . ')', 'fail', 'Створіть каталог і дайте користувачу веб-сервера право запису (див. update.sh, крок «Права доступу»).');
                continue;
            }
            $w = is_writable($path);
            $value = $w ? 'доступний для запису' : 'НЕ доступний для запису';
            if ($withSizes) {
                [$bytes, $files] = self::dirSize($path);
                $value .= '; ' . self::bytes((float) $bytes) . ', файлів: ' . $files;
            }
            $rows[] = self::row($label, $value, $w ? 'ok' : 'fail', $w ? '' : 'Веб-сервер не зможе зберігати файли.');
        }
        $rows[] = self::uploadsAccessRow();
        $free = @disk_free_space($root);
        $total = @disk_total_space($root);
        if ($free !== false && $total) {
            $pct = $free / $total * 100;
            $rows[] = self::row('Вільне місце на диску', self::bytes($free) . ' із ' . self::bytes($total) . sprintf(' (%.0f%%)', $pct), $pct < 5 ? 'fail' : ($pct < 15 ? 'warn' : 'ok'), 'Менше 15% — час чистити або розширювати диск.');
        }
        $env = self::root() . '/.env';
        if (is_file($env)) {
            $perm = substr(sprintf('%o', fileperms($env)), -4);
            $open = (fileperms($env) & 0007) !== 0;
            $rows[] = self::row('Права на .env', $perm, $open ? 'fail' : 'ok', $open ? 'Файл містить паролі — закрийте його для інших: chmod 600 .env.' : '');
        }
        $today = $root . '/logs/app-' . date('Y-m-d') . '.log';
        $rows[] = self::row('Журнал помилок за сьогодні', is_file($today) ? self::bytes((float) filesize($today)) : 'порожньо', is_file($today) && filesize($today) > 1048576 ? 'warn' : 'info', 'Деталі помилок 500 — у storage/logs за кодом інциденту.');
        if (!$withSizes) {
            $rows[] = self::row('Розміри каталогів', 'не обчислювалися', 'info', 'Натисніть «Порахувати розміри» — на великих сховищах це займає час.');
        }
        return $rows;
    }

    /**
     * Чи може веб-сервер прочитати всі файли вкладень. Типова причина «Файл не знайдено на сервері» — каталог чи файл,
     * створений іншим користувачем (cron під root), з правами, що закривають доступ веб-серверу. Перевіряємо каталоги
     * (їх не більше 256) і до 20 000 файлів.
     */
    private static function uploadsAccessRow(): array
    {
        $root = AttachmentService::storageDir();
        if (!is_dir($root)) {
            return self::row('Доступ до вкладень', 'каталогу немає', 'info');
        }
        $badDirs = 0;
        $badFiles = 0;
        $checked = 0;
        foreach (@scandir($root) ?: [] as $d) {
            if ($d === '.' || $d === '..' || !is_dir("$root/$d")) {
                continue;
            }
            if (!is_readable("$root/$d") || !is_executable("$root/$d")) {
                $badDirs++;
                continue;
            }
            foreach (@scandir("$root/$d") ?: [] as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                if (++$checked > 20000) {
                    break 2;
                }
                if (!is_readable("$root/$d/$f")) {
                    $badFiles++;
                }
            }
        }
        if ($badDirs + $badFiles === 0) {
            return self::row('Доступ до вкладень', 'веб-сервер читає всі перевірені файли (' . $checked . ')', 'ok');
        }
        return self::row('Доступ до вкладень', "недоступно: каталогів — $badDirs, файлів — $badFiles", 'fail',
            'Файли створені іншим користувачем (наприклад, cron під root) і закриті для веб-сервера — на сторінці тікета вони дають «Файл не знайдено». Виправлення: sudo ./update.sh (він відновлює власника й права) або sudo chown -R <користувач веб-сервера>: storage/uploads && sudo chmod -R u+rwX,g+rwX storage/uploads. Cron для bin/fetch-mail.php запускайте від користувача веб-сервера.');
    }

    private static function jobs(): array
    {
        $rows = [];
        // [ключ heartbeat, назва, чи потрібне, макс. вік у хв., підказка]
        $adOn = Setting::get('ad_sync_enabled', '0') === '1';
        $mailOn = Setting::get('email_ticket_enabled', '0') === '1';
        $hooks = self::tableExists('webhook_endpoints') && (int) self::scalar('SELECT COUNT(*) FROM webhook_endpoints WHERE is_active = 1') > 0;
        $schedules = self::tableExists('report_schedules') && (int) self::scalar('SELECT COUNT(*) FROM report_schedules WHERE is_active = 1') > 0;
        $jobs = [
            ['email_last_run_at', 'Пошта → тікети (bin/fetch-mail.php)', $mailOn, 15, '* * * * *  php bin/fetch-mail.php'],
            ['webhooks_worker_last_run', 'Вебхуки (bin/deliver-webhooks.php)', $hooks, 10, '* * * * *  php bin/deliver-webhooks.php'],
            ['ad_last_sync_at', 'Синхронізація з AD (bin/sync-ad-users.php)', $adOn, 26 * 60, '0 * * * *  php bin/sync-ad-users.php'],
            ['reminders_last_run', 'Нагадування про терміни (bin/send-reminders.php)', null, 26 * 60, '0 8 * * *  php bin/send-reminders.php'],
            ['status_alert_last_run', 'Сповіщення про стан системи (bin/check-status.php)', Setting::get('email_notify_status_enabled', '0') === '1', 3 * 60, '15 * * * *  php bin/check-status.php'],
            ['reports_last_run', 'Звіти за розкладом (bin/send-reports.php)', $schedules ? true : null, 3 * 60, '5 * * * *  php bin/send-reports.php'],
        ];
        foreach ($jobs as [$key, $name, $needed, $maxMin, $cron]) {
            $last = Setting::get($key);
            $age = $last ? (time() - strtotime($last)) / 60 : null;
            if ($needed === false) {
                $rows[] = self::row($name, 'вимкнено в налаштуваннях' . ($last ? ' (останній запуск ' . self::ago($age) . ')' : ''), 'info');
            } elseif ($last === null) {
                $rows[] = self::row($name, 'запусків ще не було', $needed === true ? 'warn' : 'info', 'Додайте в cron: ' . $cron);
            } elseif ($age > $maxMin) {
                $rows[] = self::row($name, 'останній запуск ' . self::ago($age) . ' (' . $last . ')', 'warn', 'Схоже, cron не працює. Рядок для cron: ' . $cron);
            } else {
                $rows[] = self::row($name, 'останній запуск ' . self::ago($age), 'ok');
            }
        }
        $lastBackup = Setting::get('backup_last_run');
        $ageDays = $lastBackup ? (time() - strtotime($lastBackup)) / 86400 : null;
        $summary = Setting::get('backup_last_summary');
        if ($lastBackup === null) {
            $rows[] = self::row('Резервна копія (bin/backup.php)', 'жодної копії ще не створено', 'warn', 'Створіть копію в Адмін-панель → Налаштування → Резервні копії та додайте в cron: 30 2 * * *  php bin/backup.php');
        } elseif ($ageDays > 8) {
            $rows[] = self::row('Резервна копія (bin/backup.php)', 'остання — ' . $lastBackup . ' (' . (int) $ageDays . ' дн. тому)', 'warn', 'Давно немає свіжої копії. Додайте в cron: 30 2 * * *  php bin/backup.php');
        } else {
            $check = (string) Setting::get('backup_last_check');
            $bad = $check !== '' && $check !== 'ok';
            $rows[] = self::row('Резервна копія (bin/backup.php)', 'остання — ' . date('d.m.Y H:i', strtotime($lastBackup)) . ($summary ? '; ' . $summary : ''), $bad ? 'warn' : 'ok',
                $bad ? 'Остання копія не пройшла перевірку цілісності або не перенесена на інший носій: ' . $check : '');
            if (\App\Services\BackupService::password() === '') {
                $rows[] = self::row('Шифрування резервних копій', 'вимкнено', 'info', 'Копія містить хеші паролів і токени. Задайте BACKUP_PASSWORD у .env, щоб шифрувати нові копії.');
            }
            if (!\App\Services\BackupService::remoteConfigured()) {
                $rows[] = self::row('Перенесення копій на інший носій', 'не налаштовано', 'info', 'Копії лежать лише на цьому сервері. Задайте BACKUP_REMOTE_DIR або BACKUP_REMOTE_SCP у .env.');
            }
        }
        if (self::tableExists('webhook_deliveries')) {
            $pending = (int) self::scalar("SELECT COUNT(*) FROM webhook_deliveries WHERE status = 'pending'");
            $failed = (int) self::scalar("SELECT COUNT(*) FROM webhook_deliveries WHERE status = 'failed'");
            $stuck = (int) self::scalar("SELECT COUNT(*) FROM webhook_deliveries WHERE status = 'pending' AND created_at < (NOW() - INTERVAL 1 HOUR)");
            $rows[] = self::row('Черга вебхуків', "очікують: {$pending}, невдалих: {$failed}", $stuck > 0 ? 'warn' : 'ok', $stuck > 0 ? "Доставок, що чекають понад годину: {$stuck}. Перевірте кінцеву адресу й cron." : '');
        }
        return $rows;
    }

    private static function features(): array
    {
        $rows = [];
        $rows[] = self::row('Перегляд Office як PDF', LibraryPreview::enabled() ? 'увімкнено' : 'вимкнено', LibraryPreview::enabled() ? 'ok' : 'info', 'Налаштування LIBRARY_PREVIEW у .env.');
        $soffice = LibraryPreview::binary();
        $rows[] = self::row('LibreOffice (soffice)', $soffice ?? 'не знайдено', $soffice ? 'ok' : 'info', $soffice ? '' : 'Без нього DOCX і XLSX конвертує вбудований конвертер; PPTX/ODT/ODS/ODP перегляду не мають.');
        $rows[] = self::row('Мініатюри зображень (GD)', AttachmentService::thumbnailsAvailable() ? 'доступні' : 'недоступні', AttachmentService::thumbnailsAvailable() ? 'ok' : 'info');
        $rows[] = self::row('Office-вкладення в задачах', AttachmentService::officeReady() ? 'доступні' : 'потрібна міграція 037', AttachmentService::officeReady() ? 'ok' : 'warn');
        $rows[] = self::row('Active Directory', Setting::get('ad_sync_enabled', '0') === '1' ? 'синхронізація увімкнена' : 'синхронізація вимкнена', 'info');
        $rows[] = self::row('Пошта → тікети', Setting::get('email_ticket_enabled', '0') === '1' ? 'увімкнено' : 'вимкнено', 'info');
        if (Setting::get('email_ticket_enabled', '0') === '1') {
            $sum = Setting::get('email_last_run_summary');
            if ($sum) {
                $rows[] = self::row('Останній прогін пошти', $sum, str_starts_with($sum, 'Помилка') ? 'warn' : 'info');
            }
        }
        return $rows;
    }

    private static function security(): array
    {
        $rows = [];
        $default = self::scalar("SELECT password_hash FROM users WHERE email = 'admin@example.local' AND is_active = 1");
        if ($default !== null && $default !== false && password_verify('admin123', (string) $default)) {
            $rows[] = self::row('Типовий пароль адміністратора', 'admin@example.local / admin123 досі діє', 'fail', 'Негайно змініть пароль або вимкніть цей обліковий запис.');
        } else {
            $rows[] = self::row('Типовий пароль адміністратора', 'не використовується', 'ok');
        }
        $admins = (int) self::scalar("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin' AND u.is_active = 1");
        $rows[] = self::row('Активних адміністраторів системи', (string) $admins, $admins < 1 ? 'fail' : ($admins > 5 ? 'warn' : 'info'), 'Тримайте мінімум людей із цією роллю.');
        $rows[] = self::row('Відображення помилок PHP (display_errors)', ini_get('display_errors') ? 'увімкнено' : 'вимкнено', ini_get('display_errors') && !in_array(strtolower((string) ini_get('display_errors')), ['0', 'off', ''], true) ? 'warn' : 'ok', 'У бойовій системі помилки мають іти в журнал, а не на екран.');
        $rows[] = self::row('Блокування входу після невдалих спроб', self::columnExists('users', 'failed_login_attempts') ? 'є' : 'немає', self::columnExists('users', 'failed_login_attempts') ? 'ok' : 'warn');
        return $rows;
    }

    private static function counts(): array
    {
        $q = fn(string $sql) => (string) (int) self::scalar($sql);
        $unmoved = 0;
        foreach (Database::connection()->query("SELECT id FROM wiki_pages WHERE content <> ''")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            if (!WikiStore::hasCurrent((int) $id)) {
                $unmoved++;
            }
        }
        return [
            self::row('Активні користувачі', $q('SELECT COUNT(*) FROM users WHERE is_active = 1'), 'info'),
            self::row('Проєкти', $q('SELECT COUNT(*) FROM projects'), 'info'),
            self::row('Відкриті задачі', $q('SELECT COUNT(*) FROM tasks t JOIN task_statuses s ON s.id = t.status_id WHERE s.is_closed = 0'), 'info'),
            self::row('Відкриті тікети', $q("SELECT COUNT(*) FROM tickets WHERE status <> 'closed'"), 'info'),
            self::row('Сторінок вікі', $q('SELECT COUNT(*) FROM wiki_pages'), 'info'),
            self::row('Сторінок вікі, текст яких лишився лише в БД (не перенесено на диск)', (string) $unmoved, $unmoved > 0 ? 'warn' : 'ok', 'Перенесіть: php bin/migrate-wiki-to-files.php (це робить і update.sh).'),
            self::row('Документів бібліотеки', $q('SELECT COUNT(*) FROM library_documents'), 'info'),
            self::row('Вкладень (тікети й задачі)', $q('SELECT COUNT(*) FROM attachments'), 'info'),
        ];
    }

    // ---------- допоміжне ----------

    private static function row(string $label, string $value, string $level, string $hint = ''): array
    {
        return ['label' => $label, 'value' => $value, 'level' => $level, 'hint' => $hint];
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Рядок про HTTPS: розрізняє «справді HTTP» і «TLS завершується на проксі, що не передає заголовок». */
    private static function httpsRow(string $appUrl): array
    {
        if (\App\Core\Request::isHttps()) {
            return self::row('Запит надійшов через HTTPS', 'так', 'ok');
        }
        $evidence = \App\Core\Request::httpsEvidence();
        if (str_starts_with($appUrl, 'https://')) {
            return self::row('Запит надійшов через HTTPS', 'ні (сервер бачить HTTP, хоча APP_URL з https://)', 'warn',
                'Імовірно, TLS завершується на зворотному проксі/балансувальнику, який не передає заголовок X-Forwarded-Proto: https. Налаштуйте його (nginx: proxy_set_header X-Forwarded-Proto $scheme; Apache: RequestHeader set X-Forwarded-Proto "https"). Поки цього немає, cookie сесії не отримує прапорця Secure. Що бачить сервер: ' . $evidence);
        }
        return self::row('Запит надійшов через HTTPS', 'ні', 'warn',
            'Без HTTPS паролі та сесійні cookie йдуть відкритим текстом: увімкніть TLS на веб-сервері й вкажіть APP_URL з https://. Що бачить сервер: ' . $evidence);
    }

    private static function scalar(string $sql): mixed
    {
        $v = Database::connection()->query($sql)->fetchColumn();
        return $v === false ? null : $v;
    }

    private static function tableExists(string $table): bool
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
        $stmt->execute(['t' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function columnExists(string $table, string $column): bool
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c');
        $stmt->execute(['t' => $table, 'c' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function bytes(float $b): string
    {
        foreach (['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'] as $i => $u) {
            if ($b < 1024 || $i === 4) {
                return ($i === 0 ? (string) (int) $b : number_format($b, 1, ',', ' ')) . ' ' . $u;
            }
            $b /= 1024;
        }
        return '';
    }

    private static function ago(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        if ($minutes < 1) {
            return 'щойно';
        }
        if ($minutes < 90) {
            return (int) $minutes . ' хв тому';
        }
        if ($minutes < 48 * 60) {
            return (int) ($minutes / 60) . ' год тому';
        }
        return (int) ($minutes / 1440) . ' дн. тому';
    }

    /** Розмір каталогу й кількість файлів; обхід обмежено 300 000 файлів, щоб сторінка не зависала. */
    private static function dirSize(string $dir): array
    {
        $bytes = 0;
        $files = 0;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $bytes += $f->getSize();
                    if (++$files >= 300000) {
                        break;
                    }
                }
            }
        } catch (\Throwable) {
        }
        return [$bytes, $files];
    }
}
