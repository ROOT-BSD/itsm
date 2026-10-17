<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Models\Setting;

/**
 * Резервна копія: один архів `backup-РРРРММДД-ГГММСС.tar.gz` у storage/backups з
 *   - database.sql — повний дамп БД (структура й дані; формується самим застосунком, mysqldump не потрібен);
 *   - storage/uploads — вкладення тікетів і задач, документи бібліотеки, вкладення форуму й вікі (без мініатюр і кешу PDF — вони відтворюються);
 *   - storage/wiki — текст сторінок вікі та історія версій;
 *   - MANIFEST.json і RESTORE.txt — відомості про копію та інструкція відновлення.
 * Файл `.env` (паролі до БД, пошти, AD) до архіву СВІДОМО не входить — його зберігають окремо.
 *
 * Архів пишеться потоково (пам'ять не росте з розміром сховища), формат tar.gz не має обмеження 4 ГБ, як zip.
 */
class BackupService
{
    public const NAME_PATTERN = '/^backup-\d{8}-\d{6}\.tar\.gz(\.enc)?$/';
    public const OPENSSL_ARGS = ['-aes-256-cbc', '-pbkdf2', '-iter', '200000', '-md', 'sha256', '-salt'];
    public const DEFAULT_KEEP = 7;

    public static function dir(): string
    {
        return rtrim((string) Config::get('backup.dir', dirname(__DIR__, 2) . '/storage/backups'), '/');
    }

    public static function keep(): int
    {
        return max(1, min(365, (int) (Setting::get('backup_keep') ?? self::DEFAULT_KEEP)));
    }

    /** Мітка часу копії («20261014-081253») — з неї будуються адреси без крапок: backup-<мітка>.tar.gz. */
    public static function nameForStamp(string $stamp): ?string
    {
        if (!preg_match('/^\d{8}-\d{6}$/', $stamp)) {
            return null;
        }
        foreach (['.tar.gz.enc', '.tar.gz'] as $ext) {
            if (is_file(self::dir() . '/backup-' . $stamp . $ext)) {
                return 'backup-' . $stamp . $ext;
            }
        }
        return 'backup-' . $stamp . '.tar.gz';
    }

    public static function isEncrypted(string $name): bool
    {
        return str_ends_with($name, '.enc');
    }

    public static function password(): string
    {
        return (string) Config::get('backup.password', '');
    }

    public static function stampOf(string $name): string
    {
        return substr($name, 7, 15);
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match(self::NAME_PATTERN, $name);
    }

    /** Повний шлях до існуючої копії за іменем; null, якщо ім'я некоректне чи файлу немає. */
    public static function path(string $name): ?string
    {
        if (!self::validName($name)) {
            return null;
        }
        $path = self::dir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /** @return array<int, array{name: string, size: int, mtime: int, encrypted: bool}> нові першими */
    public static function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/backup-*.tar.gz*') ?: [] as $f) {
            $name = basename($f);
            if (self::validName($name) && is_file($f)) {
                $out[] = ['name' => $name, 'size' => (int) filesize($f), 'mtime' => (int) filemtime($f), 'encrypted' => self::isEncrypted($name)];
            }
        }
        usort($out, fn($a, $b) => strcmp(self::stampOf($b['name']), self::stampOf($a['name'])));
        return $out;
    }

    /** Видаляє найстаріші копії, залишаючи $keep найновіших. Повертає імена видалених. */
    public static function prune(?int $keep = null): array
    {
        $keep ??= self::keep();
        $removed = [];
        foreach (array_slice(self::all(), $keep) as $b) {
            if (@unlink(self::dir() . '/' . $b['name'])) {
                $removed[] = $b['name'];
            }
        }
        return $removed;
    }

    public static function delete(string $name): bool
    {
        $path = self::path($name);
        return $path !== null && @unlink($path);
    }

    /**
     * Створює копію. Виняток RuntimeException з читабельним повідомленням — якщо не вийшло (диск, права, БД).
     *
     * @return array{file: string, size: int, seconds: int, tables: int, rows: int, files: int, skipped: int, encrypted: bool}
     */
    public static function create(): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $started = time();
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не вдалося створити каталог ' . $dir . ' — перевірте права на storage.');
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException('Каталог ' . $dir . ' недоступний для запису веб-серверу.');
        }
        $lock = fopen($dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Інша резервна копія вже створюється — зачекайте її завершення.');
        }

        $sql = $dir . '/.dump-' . bin2hex(random_bytes(6)) . '.sql';
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(6)) . '.tar.gz';
        try {
            [$tables, $rows] = self::dumpDatabase($sql);

            $files = [];
            $bytes = (int) filesize($sql);
            $skipped = 0;
            foreach (['uploads' => AttachmentService::storageDir(), 'wiki' => (string) Config::get('wiki.dir', dirname(__DIR__, 2) . '/storage/wiki')] as $label => $root) {
                foreach (self::walk($root, 'storage/' . $label) as [$abs, $rel, $size]) {
                    $files[] = [$abs, $rel, $size];
                    $bytes += $size;
                }
            }
            $free = @disk_free_space($dir);
            $encrypt = self::password() !== '';
            if ($free !== false && $free < $bytes * ($encrypt ? 2.05 : 1.02) + 10485760) {
                throw new \RuntimeException('Замало вільного місця для копії: потрібно орієнтовно до ' . self::human($bytes) . ', вільно ' . self::human((float) $free) . '.');
            }

            $gz = gzopen($tmp, 'wb6');
            if ($gz === false) {
                throw new \RuntimeException('Не вдалося створити файл архіву.');
            }
            $name = 'backup-' . date('Ymd-His') . '.tar.gz';
            $manifest = json_encode([
                'created_at' => date('c'),
                'app_version' => (string) Config::get('app.version', ''),
                'database' => Config::get('db.database'),
                'tables' => $tables,
                'rows' => $rows,
                'files' => count($files),
                'excluded' => ['.env', 'storage/logs', 'thumbnails and PDF cache'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            self::tarString($gz, 'MANIFEST.json', (string) $manifest);
            self::tarString($gz, 'RESTORE.txt', self::restoreText());
            if (!self::tarFile($gz, $sql, 'database.sql', (int) filesize($sql))) {
                throw new \RuntimeException('Не вдалося записати дамп БД в архів.');
            }
            foreach ($files as [$abs, $rel, $size]) {
                if (!self::tarFile($gz, $abs, $rel, $size)) {
                    $skipped++;
                }
            }
            gzwrite($gz, str_repeat("\0", 1024)); // кінець tar
            gzclose($gz);

            if (!@rename($tmp, $dir . '/' . $name)) {
                throw new \RuntimeException('Не вдалося зберегти готовий архів.');
            }
            if ($encrypt) {
                $enc = $dir . '/' . $name . '.enc';
                $err = self::openssl(['-in', $dir . '/' . $name, '-out', $enc], self::password(), false);
                if ($err !== null || !is_file($enc) || filesize($enc) < 16) {
                    @unlink($enc);
                    @unlink($dir . '/' . $name);
                    throw new \RuntimeException('Не вдалося зашифрувати копію: ' . ($err ?? 'порожній результат') . '. Копію не збережено.');
                }
                @unlink($dir . '/' . $name); // незашифрований архів не лишаємо
                $name .= '.enc';
            }
            @chmod($dir . '/' . $name, 0660);
            return [
                'file' => $name, 'size' => (int) filesize($dir . '/' . $name), 'seconds' => time() - $started,
                'tables' => $tables, 'rows' => $rows, 'files' => count($files) - $skipped, 'skipped' => $skipped,
                'encrypted' => $encrypt,
            ];
        } finally {
            @unlink($sql);
            @unlink($tmp);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }


    // ---------- шифрування ----------

    /**
     * openssl enc (-d — розшифрувати). Пароль передається змінною середовища, а не аргументом (не видно в `ps`).
     * Повертає текст помилки або null.
     */
    private static function openssl(array $io, string $password, bool $decrypt): ?string
    {
        $cmd = array_merge(['openssl', 'enc'], $decrypt ? ['-d'] : [], self::OPENSSL_ARGS, ['-pass', 'env:ITSM_BACKUP_PASS'], $io);
        $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['ITSM_BACKUP_PASS' => $password, 'PATH' => (string) getenv('PATH')]);
        if (!is_resource($p)) {
            return 'не вдалося запустити openssl (встановіть пакет openssl)';
        }
        stream_get_contents($pipes[1]);
        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        return $code === 0 ? null : ($decrypt && str_contains($stderr, 'bad decrypt') ? 'невірний пароль або пошкоджений файл' : mb_substr($stderr !== '' ? $stderr : 'код ' . $code, 0, 200));
    }

    /** Розшифровує .enc у $dest. Повертає текст помилки або null. */
    public static function decryptTo(string $src, string $dest, string $password): ?string
    {
        if ($password === '') {
            return 'потрібен пароль шифрування (BACKUP_PASSWORD у .env)';
        }
        $err = self::openssl(['-in', $src, '-out', $dest], $password, true);
        if ($err !== null) {
            @unlink($dest);
        }
        return $err;
    }

    // ---------- відновлення ----------

    /** Виконує зовнішню команду; повертає [код, stderr]. */
    private static function run(array $cmd, ?array $env = null, $stdin = null): array
    {
        $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if ($stdin !== null) {
            $spec[0] = $stdin;
        }
        $p = @proc_open($cmd, $spec, $pipes, null, $env);
        if (!is_resource($p)) {
            return [127, 'не вдалося запустити ' . $cmd[0]];
        }
        stream_get_contents($pipes[1]);
        $err = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $err];
    }

    /** Файл зашифрований openssl enc (за сигнатурою «Salted__», а не за назвою)? */
    public static function looksEncrypted(string $file): bool
    {
        $fh = @fopen($file, 'rb');
        $head = $fh ? (string) fread($fh, 8) : '';
        if ($fh) {
            fclose($fh);
        }
        return $head === 'Salted__';
    }

    /**
     * Розшифровує (за потреби), перевіряє, розпаковує й — з apply — ВІДНОВЛЮЄ БД та storage/uploads, storage/wiki цього застосунку.
     * Спільна логіка bin/restore.php і веб-кнопки «Відновити». Перед відновленням (safety) створює страхувальну копію поточного стану.
     *
     * @param array{extract?: ?string, apply?: bool, safety?: bool, password?: ?string} $o
     * @return array{extract: string, safety: ?string, message: string}
     * @throws \RuntimeException з українським поясненням
     */
    public static function restore(string $file, array $o = [], ?callable $log = null): array
    {
        $log ??= static function (string $m): void {};
        $apply = !empty($o['apply']);
        $safety = $o['safety'] ?? true;
        if (!is_file($file)) {
            throw new \RuntimeException('файл не знайдено');
        }
        $own = empty($o['extract']);
        $extract = $own ? self::dir() . '/.restore-' . bin2hex(random_bytes(6)) : (string) $o['extract'];
        if (!is_dir($extract) && !@mkdir($extract, 0700, true)) {
            throw new \RuntimeException("не вдалося створити теку $extract");
        }
        $extract = rtrim(realpath($extract) ?: $extract, '/');
        try {
            if (glob($extract . '/*')) {
                throw new \RuntimeException("тека $extract не порожня");
            }
            $archive = $file;
            if (self::looksEncrypted($file)) {
                $archive = $extract . '/.decrypted.tar.gz';
                $err = self::decryptTo($file, $archive, (string) ($o['password'] ?? '') !== '' ? (string) $o['password'] : self::password());
                if ($err !== null) {
                    throw new \RuntimeException('розшифровка не вдалася: ' . $err);
                }
                $log('Розшифровано.');
            }
            $v = self::verifyArchive($archive);
            if (!$v['ok']) {
                throw new \RuntimeException('копія не пройшла перевірку: ' . $v['message']);
            }
            $log($v['message']);
            [$code, $err] = self::run(['tar', '-xzf', $archive, '-C', $extract]);
            if ($code !== 0) {
                throw new \RuntimeException('tar: ' . $err);
            }
            @unlink($extract . '/.decrypted.tar.gz');
            $log("Розпаковано в $extract (database.sql, storage/uploads, storage/wiki, MANIFEST.json, RESTORE.txt).");
            if (!$apply) {
                return ['extract' => $extract, 'safety' => null, 'message' => 'Нічого не змінено в системі.'];
            }

            $safetyFile = null;
            if ($safety) {
                try {
                    $safetyFile = self::create()['file'];
                } catch (\Throwable $e) {
                    throw new \RuntimeException('не вдалося створити страхувальну копію (' . $e->getMessage() . ')');
                }
                $log('Страхувальну копію поточного стану створено: ' . $safetyFile);
            }

            $db = \App\Core\Config::get('db');
            $sql = @fopen($extract . '/database.sql', 'rb');
            if ($sql === false) {
                throw new \RuntimeException('у копії немає database.sql');
            }
            [$code, $err] = self::run(
                ['mysql', '-h', (string) $db['host'], '-P', (string) $db['port'], '-u', (string) $db['username'], '--default-character-set=utf8mb4', (string) $db['database']],
                ['MYSQL_PWD' => (string) $db['password'], 'PATH' => (string) getenv('PATH')],
                $sql
            );
            fclose($sql);
            if ($code !== 0) {
                throw new \RuntimeException('імпорт бази не вдався: ' . $err . ($safetyFile ? " (страхувальна копія: $safetyFile)" : ''));
            }
            $log('База даних відновлена.');

            $root = dirname(__DIR__, 2);
            foreach (['uploads', 'wiki'] as $part) {
                $src = "$extract/storage/$part";
                if (!is_dir($src)) {
                    continue;
                }
                $dst = "$root/storage/$part";
                @mkdir($dst, 0770, true);
                [$code, $err] = self::run(['cp', '-a', $src . '/.', $dst . '/']);
                if ($code !== 0) {
                    throw new \RuntimeException("копіювання storage/$part не вдалося: $err");
                }
                $log("Файли storage/$part відновлено.");
            }
            return ['extract' => $extract, 'safety' => $safetyFile, 'message' => 'Базу даних і файли відновлено.'];
        } finally {
            if ($own && is_dir($extract)) {
                self::run(['rm', '-rf', $extract]);
            }
        }
    }

    // ---------- перевірка копії ----------

    /**
     * Перевіряє копію без розпакування на диск: розшифровка (за потреби) у тимчасовий файл, прохід по tar із перевіркою
     * контрольних сум заголовків і CRC gzip, наявність MANIFEST.json і database.sql, відповідність кількості файлів.
     *
     * @return array{ok: bool, message: string, entries?: int, tables?: int, rows?: int, created_at?: string}
     */
    public static function verify(string $name, ?string $password = null): array
    {
        $path = self::path($name);
        if ($path === null) {
            return ['ok' => false, 'message' => 'Копію не знайдено.'];
        }
        $tmp = null;
        try {
            $read = $path;
            if (self::isEncrypted($name)) {
                $tmp = self::dir() . '/.verify-' . bin2hex(random_bytes(6)) . '.tar.gz';
                $err = self::decryptTo($path, $tmp, $password ?? self::password());
                if ($err !== null) {
                    return ['ok' => false, 'message' => 'Не вдалося розшифрувати: ' . $err];
                }
                $read = $tmp;
            }
            return self::verifyArchive($read);
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
    }

    public static function verifyArchive(string $file): array
    {
        $gz = @gzopen($file, 'rb');
        if ($gz === false) {
            return ['ok' => false, 'message' => 'Не вдалося відкрити архів.'];
        }
        $fail = static function (string $m) use ($gz): array {
            gzclose($gz);
            return ['ok' => false, 'message' => $m];
        };
        $names = [];
        $manifest = null;
        $dbHead = '';
        $ended = false;
        while (true) {
            $h = (string) @gzread($gz, 512);
            if (strlen($h) < 512) {
                return $fail('Архів обірваний або пошкоджений (немає кінцевої мітки tar).');
            }
            if (trim($h, "\0") === '') {
                $ended = true;
                break;
            }
            $sum = 0;
            for ($i = 0; $i < 512; $i++) {
                $sum += ($i >= 148 && $i < 156) ? 32 : ord($h[$i]);
            }
            if ((int) octdec(trim(substr($h, 148, 8), "\0 ")) !== $sum) {
                return $fail('Пошкоджений заголовок запису в архіві (не збігається контрольна сума).');
            }
            $entry = rtrim(substr($h, 0, 100), "\0");
            $prefix = rtrim(substr($h, 345, 155), "\0");
            $entry = $prefix !== '' ? $prefix . '/' . $entry : $entry;
            $size = (int) octdec(trim(substr($h, 124, 12), "\0 "));
            $names[] = $entry;
            $left = $size + (512 - $size % 512) % 512;
            $captured = '';
            $capture = $entry === 'MANIFEST.json' ? 65536 : ($entry === 'database.sql' ? 2048 : 0);
            $got = 0;
            while ($left > 0) {
                $chunk = @gzread($gz, min(1048576, $left));
                if ($chunk === false || $chunk === '') {
                    return $fail('Архів обірваний усередині запису «' . $entry . '».');
                }
                if ($capture > 0 && $got < $capture) {
                    $captured .= substr($chunk, 0, $capture - $got);
                }
                $got += strlen($chunk);
                $left -= strlen($chunk);
            }
            if ($entry === 'MANIFEST.json') {
                $manifest = json_decode(substr($captured, 0, $size), true);
            } elseif ($entry === 'database.sql') {
                $dbHead = $captured;
            }
        }
        // дочитуємо до кінця потоку: так zlib перевіряє CRC усього архіву
        while (!gzeof($gz)) {
            if (@gzread($gz, 65536) === false) {
                return $fail('Не збігається контрольна сума gzip — архів пошкоджено.');
            }
        }
        gzclose($gz);
        if (!$ended || !is_array($manifest)) {
            return ['ok' => false, 'message' => 'У копії немає коректного MANIFEST.json.'];
        }
        if (!in_array('database.sql', $names, true) || !str_contains($dbHead, 'ITSM System')) {
            return ['ok' => false, 'message' => 'У копії немає дампа бази даних.'];
        }
        $files = count($names) - 3;
        if ($files > (int) ($manifest['files'] ?? 0)) {
            return ['ok' => false, 'message' => 'Кількість файлів в архіві перевищує вказану в MANIFEST.'];
        }
        return [
            'ok' => true,
            'message' => 'Копію перевірено: цілісність gzip і tar, дамп БД (таблиць: ' . (int) ($manifest['tables'] ?? 0) . '), файлів: ' . $files . '.',
            'entries' => count($names), 'tables' => (int) ($manifest['tables'] ?? 0), 'rows' => (int) ($manifest['rows'] ?? 0),
            'created_at' => (string) ($manifest['created_at'] ?? ''),
        ];
    }

    // ---------- перенесення на інший носій / сервер ----------

    /** Чи налаштоване перенесення (каталог і/або scp). */
    public static function remoteConfigured(): bool
    {
        return (string) Config::get('backup.remote_dir', '') !== '' || (string) Config::get('backup.remote_scp', '') !== '';
    }

    /**
     * Копіює готовий архів на налаштовані носії. Помилка перенесення не скасовує локальну копію — повертається в результаті.
     *
     * @return array<int, array{target: string, ok: bool, message: string}>
     */
    public static function transfer(string $name): array
    {
        $path = self::path($name);
        $results = [];
        if ($path === null) {
            return [['target' => '—', 'ok' => false, 'message' => 'Копію не знайдено.']];
        }
        $dirTarget = rtrim((string) Config::get('backup.remote_dir', ''), '/');
        if ($dirTarget !== '') {
            $results[] = ['target' => $dirTarget] + self::copyToDir($path, $name, $dirTarget);
        }
        $scp = (string) Config::get('backup.remote_scp', '');
        if ($scp !== '') {
            $results[] = ['target' => $scp] + self::copyViaScp($path, $scp);
        }
        return $results;
    }

    private static function copyToDir(string $path, string $name, string $target): array
    {
        if (!is_dir($target) || !is_writable($target)) {
            return ['ok' => false, 'message' => 'Каталог недоступний для запису (змонтований диск відсутній?).'];
        }
        $part = $target . '/.' . $name . '.part';
        if (!@copy($path, $part) || filesize($part) !== filesize($path)) {
            @unlink($part);
            return ['ok' => false, 'message' => 'Не вдалося скопіювати файл (місце, права чи обрив зв\'язку).'];
        }
        if (!@rename($part, $target . '/' . $name)) {
            @unlink($part);
            return ['ok' => false, 'message' => 'Не вдалося завершити запис файлу.'];
        }
        // на віддаленому носії тримаємо стільки ж копій, скільки й локально
        $remote = glob($target . '/backup-*.tar.gz*') ?: [];
        $remote = array_filter($remote, fn($f) => self::validName(basename($f)));
        usort($remote, fn($a, $b) => strcmp(self::stampOf(basename($b)), self::stampOf(basename($a))));
        $removed = 0;
        foreach (array_slice($remote, self::keep()) as $old) {
            $removed += @unlink($old) ? 1 : 0;
        }
        return ['ok' => true, 'message' => 'Скопійовано' . ($removed ? '; видалено старих на носії: ' . $removed : '')];
    }

    private static function copyViaScp(string $path, string $target): array
    {
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*@[A-Za-z0-9][A-Za-z0-9.-]*:[A-Za-z0-9_.\/~-]*$/', $target)) {
            return ['ok' => false, 'message' => 'BACKUP_REMOTE_SCP має вигляд користувач@хост:/каталог/'];
        }
        $cmd = ['scp', '-q', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20', '-o', 'StrictHostKeyChecking=yes'];
        $key = (string) Config::get('backup.scp_key', '');
        if ($key !== '') {
            array_push($cmd, '-i', $key, '-o', 'IdentitiesOnly=yes');
        }
        $port = (string) Config::get('backup.scp_port', '');
        if ($port !== '' && ctype_digit($port)) {
            array_push($cmd, '-P', $port);
        }
        array_push($cmd, '--', $path, $target);
        $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => (string) getenv('PATH'), 'HOME' => (string) (getenv('HOME') ?: '')]);
        if (!is_resource($p)) {
            return ['ok' => false, 'message' => 'Не вдалося запустити scp (встановіть openssh-client).'];
        }
        stream_get_contents($pipes[1]);
        $err = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        return $code === 0
            ? ['ok' => true, 'message' => 'Надіслано через scp (старі копії на сервері не видаляються — очищайте самостійно)']
            : ['ok' => false, 'message' => mb_substr($err !== '' ? $err : 'scp завершився з кодом ' . $code, 0, 250)];
    }

    /**
     * Дії після створення: перевірка цілісності й перенесення. Результат записується у налаштування для сторінки копій
     * і «Стану системи».
     *
     * @return array{verify: array, transfer: array}
     */
    public static function afterCreate(array $created): array
    {
        $verify = self::verify($created['file']);
        $transfer = self::remoteConfigured() && $verify['ok'] ? self::transfer($created['file']) : [];
        $problems = [];
        if (!$verify['ok']) {
            $problems[] = 'перевірка: ' . $verify['message'];
        }
        foreach ($transfer as $t) {
            if (!$t['ok']) {
                $problems[] = $t['target'] . ': ' . $t['message'];
            }
        }
        Setting::set('backup_last_check', $problems ? 'ПРОБЛЕМА — ' . mb_substr(implode('; ', $problems), 0, 400) : 'ok');
        return ['verify' => $verify, 'transfer' => $transfer];
    }

    public static function summary(array $r, array $post): string
    {
        $s = sprintf('%s, %s%s; таблиць: %d, рядків: %d, файлів: %d%s; %d с', $r['file'], self::human((float) $r['size']), !empty($r['encrypted']) ? ', зашифровано' : '', $r['tables'], $r['rows'], $r['files'], $r['skipped'] ? ", пропущено: {$r['skipped']}" : '', $r['seconds']);
        $s .= $post['verify']['ok'] ? '; перевірено' : '; ПЕРЕВІРКА НЕ ПРОЙДЕНА';
        foreach ($post['transfer'] as $t) {
            $s .= '; ' . ($t['ok'] ? 'перенесено' : 'НЕ перенесено') . ' → ' . $t['target'];
        }
        return $s;
    }

    // ---------- дамп БД ----------

    /** @return array{0: int, 1: int} [таблиць, рядків] */
    private static function dumpDatabase(string $file): array
    {
        $pdo = Database::connection();
        $out = fopen($file, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Не вдалося створити тимчасовий файл дампа.');
        }
        $tables = 0;
        $rows = 0;
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT'); // усі таблиці — на один момент часу
        $buffered = $pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        try {
            fwrite($out, "-- ITSM System: дамп бази " . Config::get('db.database') . ' від ' . date('c') . "\n"
                . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
            $names = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($names as $table) {
                $q = '`' . str_replace('`', '``', (string) $table) . '`';
                $create = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(\PDO::FETCH_NUM);
                fwrite($out, "DROP TABLE IF EXISTS {$q};\n" . $create[1] . ";\n\n");
                $tables++;

                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $stmt = $pdo->query('SELECT * FROM ' . $q);
                $batch = [];
                while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
                    $vals = [];
                    foreach ($row as $v) {
                        $vals[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
                    }
                    $batch[] = '(' . implode(',', $vals) . ')';
                    $rows++;
                    if (count($batch) >= 300) {
                        fwrite($out, "INSERT INTO {$q} VALUES\n" . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                $stmt->closeCursor();
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
                if ($batch) {
                    fwrite($out, "INSERT INTO {$q} VALUES\n" . implode(",\n", $batch) . ";\n");
                }
                fwrite($out, "\n");
            }
            fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");
        } finally {
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            try {
                $pdo->exec('COMMIT');
            } catch (\Throwable) {
            }
            fclose($out);
        }
        return [$tables, $rows];
    }

    // ---------- файли ----------

    /** @return \Generator<array{0: string, 1: string, 2: int}> [повний шлях, шлях в архіві, розмір] */
    private static function walk(string $root, string $prefix): \Generator
    {
        if (!is_dir($root)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($it as $f) {
            if (!$f->isFile() || $f->isLink()) {
                continue;
            }
            $base = $f->getFilename();
            if ($prefix === 'storage/uploads' && preg_match('/_(t|pdf)$/', $base)) {
                continue; // мініатюри та кеш PDF відтворюються самі
            }
            $rel = $prefix . '/' . ltrim(substr($f->getPathname(), strlen($root)), '/');
            yield [$f->getPathname(), $rel, (int) $f->getSize()];
        }
    }

    // ---------- tar ----------

    private static function header(string $name, int $size, int $mtime, int $mode = 0640): ?string
    {
        $prefix = '';
        if (strlen($name) > 100) {
            $cut = strrpos(substr($name, 0, 156), '/');
            if ($cut === false || strlen($name) - $cut - 1 > 100) {
                return null;
            }
            $prefix = substr($name, 0, $cut);
            $name = substr($name, $cut + 1);
        }
        if ($size > 077777777777) { // 8 ГБ — межа класичного tar
            return null;
        }
        $h = str_pad($name, 100, "\0")
            . sprintf('%07o', $mode) . "\0" . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
            . sprintf('%011o', $size) . "\0" . sprintf('%011o', $mtime) . "\0"
            . '        ' . '0' . str_pad('', 100, "\0") . "ustar\0" . '00'
            . str_pad('', 32, "\0") . str_pad('', 32, "\0") . str_pad('', 8, "\0") . str_pad('', 8, "\0")
            . str_pad($prefix, 155, "\0");
        $h = str_pad($h, 512, "\0");
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($h[$i]);
        }
        return substr($h, 0, 148) . sprintf('%06o', $sum) . "\0 " . substr($h, 156);
    }

    private static function tarString($gz, string $name, string $content): void
    {
        gzwrite($gz, self::header($name, strlen($content), time()) . $content . str_repeat("\0", (512 - strlen($content) % 512) % 512));
    }

    /** Додає файл у tar. false — якщо файл пропущено (нечитний, змінився, надто довге ім'я чи завеликий). */
    private static function tarFile($gz, string $abs, string $name, int $size): bool
    {
        $in = @fopen($abs, 'rb');
        if ($in === false) {
            return false;
        }
        $header = self::header($name, $size, (int) @filemtime($abs));
        if ($header === null) {
            fclose($in);
            return false;
        }
        gzwrite($gz, $header);
        $left = $size;
        while ($left > 0) {
            $chunk = fread($in, min(1048576, $left));
            if ($chunk === false || $chunk === '') {
                $chunk = str_repeat("\0", min(1048576, $left)); // файл скоротився під час копіювання — доповнюємо, щоб не зламати архів
            }
            gzwrite($gz, $chunk);
            $left -= strlen($chunk);
        }
        fclose($in);
        gzwrite($gz, str_repeat("\0", (512 - $size % 512) % 512));
        return true;
    }

    private static function restoreText(): string
    {
        return <<<TXT
Відновлення з цієї резервної копії (ITSM System)
================================================

Найпростіше: php bin/restore.php <файл> --extract=<тека>   (розшифрує, перевірить і розпакує; --apply --yes відновить БД і файли).
Вручну (якщо файл зашифрований, .tar.gz.enc, спершу розшифруйте):
     openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -md sha256 -in backup-….tar.gz.enc -out backup-….tar.gz

1. Розпакуйте архів у тимчасову теку:
     mkdir restore && tar -xzf backup-РРРРММДД-ГГММСС.tar.gz -C restore

2. Відновіть базу (спершу створіть порожню базу й користувача, як у install.sh; дамп сам видаляє й створює таблиці):
     mysql -u<користувач> -p <база> < restore/database.sql

3. Поверніть файли у каталог застосунку й дайте веб-серверу право запису:
     cp -a restore/storage/uploads/. /var/www/itsm-system/storage/uploads/
     cp -a restore/storage/wiki/.    /var/www/itsm-system/storage/wiki/
     chown -R www-data:www-data /var/www/itsm-system/storage

4. Файл .env у копію НЕ входить (у ньому паролі) — відновіть його з власного захищеного сховища.

5. Виконайте sudo bash update.sh, щоб застосунок перевірив схему та права, і зайдіть на сторінку «Стан системи».

Тут MANIFEST.json — відомості про копію (версія застосунку, час, кількість таблиць і файлів).
TXT;
    }

    // ---------- допоміжне ----------

    public static function human(float $b): string
    {
        foreach (['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'] as $i => $u) {
            if ($b < 1024 || $i === 4) {
                return ($i === 0 ? (string) (int) $b : number_format($b, 1, ',', ' ')) . ' ' . $u;
            }
            $b /= 1024;
        }
        return '';
    }
}
