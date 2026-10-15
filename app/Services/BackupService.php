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
    public const NAME_PATTERN = '/^backup-\d{8}-\d{6}\.tar\.gz$/';
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
        return preg_match('/^\d{8}-\d{6}$/', $stamp) ? 'backup-' . $stamp . '.tar.gz' : null;
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

    /** @return array<int, array{name: string, size: int, mtime: int}> нові першими */
    public static function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/backup-*.tar.gz') ?: [] as $f) {
            $name = basename($f);
            if (self::validName($name) && is_file($f)) {
                $out[] = ['name' => $name, 'size' => (int) filesize($f), 'mtime' => (int) filemtime($f)];
            }
        }
        usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
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
     * @return array{file: string, size: int, seconds: int, tables: int, rows: int, files: int, skipped: int}
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
            if ($free !== false && $free < $bytes * 1.02 + 10485760) {
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
            @chmod($dir . '/' . $name, 0660);
            return [
                'file' => $name, 'size' => (int) filesize($dir . '/' . $name), 'seconds' => time() - $started,
                'tables' => $tables, 'rows' => $rows, 'files' => count($files) - $skipped, 'skipped' => $skipped,
            ];
        } finally {
            @unlink($sql);
            @unlink($tmp);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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
