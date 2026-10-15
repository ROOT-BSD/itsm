<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Services\Office\OfficeException;
use App\Services\Office\OfficeToPdf;

/**
 * Перегляд документів Word/Excel/PowerPoint/OpenDocument у веб-інтерфейсі: файл конвертується в PDF і віддається браузеру.
 * Рушії: LibreOffice (`soffice --headless --convert-to pdf`, усі формати, найкраща точність) і вбудований автономний конвертер
 * App\Services\Office (чистий PHP, лише DOCX і XLSX) — він вмикається, коли LibreOffice не встановлено або не впорався. Готовий PDF кешується поряд з оригіналом
 * (`<stored_name>_pdf`) і видаляється разом з ним (AttachmentService::deleteFiles).
 *
 * Безпека: конвертація йде у вигляді масиву аргументів (без оболонки), з окремим тимчасовим профілем і каталогом на кожен запуск,
 * з обмеженням часу, і лише по одній конвертації одночасно (flock). Макроси LibreOffice у headless-режимі не виконуються,
 * а завантаження файлів із макросами бібліотека й так відхиляє.
 */
final class LibraryPreview
{
    /** Розширення, які можна конвертувати → потрібне, щоб LibreOffice визначив формат за ім'ям файлу. */
    public const EXTENSIONS = ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'];

    /** Рушій: auto (LibreOffice, а без нього — вбудований для DOCX/XLSX), libreoffice, builtin. */
    private static function engine(): string
    {
        $e = (string) Config::get('library.preview.engine', 'auto');
        return in_array($e, ['auto', 'libreoffice', 'builtin'], true) ? $e : 'auto';
    }

    private static function libreOfficeReady(): bool
    {
        return self::engine() !== 'builtin' && function_exists('proc_open') && self::binary() !== null;
    }

    private static function builtinFor(string $ext): bool
    {
        return self::engine() !== 'libreoffice' && OfficeToPdf::supports($ext) && extension_loaded('dom') && extension_loaded('mbstring');
    }

    public static function supportsName(string $originalName): bool
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return (bool) Config::get('library.preview.enabled', true)
            && in_array($ext, self::EXTENSIONS, true)
            && (self::libreOfficeReady() || self::builtinFor($ext));
    }

    /** Чи доступний хоч якийсь рушій перегляду (для показу кнопок). */
    public static function enabled(): bool
    {
        return (bool) Config::get('library.preview.enabled', true)
            && (self::libreOfficeReady() || self::builtinFor('docx'));
    }

    /** Повний шлях до `soffice` або null, якщо не знайдено. */
    public static function binary(): ?string
    {
        static $cached = false;
        if ($cached !== false) {
            return $cached;
        }
        $configured = (string) Config::get('library.preview.soffice', 'soffice');
        $found = null;
        if (str_contains($configured, '/')) {
            $found = is_file($configured) && is_executable($configured) ? $configured : null;
        } else {
            foreach (explode(PATH_SEPARATOR, (string) getenv('PATH') . PATH_SEPARATOR . '/usr/bin:/usr/local/bin:/opt/libreoffice/program') as $dir) {
                if ($dir !== '' && is_file($dir . '/' . $configured) && is_executable($dir . '/' . $configured)) {
                    $found = $dir . '/' . $configured;
                    break;
                }
            }
        }
        return $cached = $found;
    }

    public static function pdfPath(string $storedName): ?string
    {
        $path = AttachmentService::path($storedName);
        return $path === null ? null : $path . '_pdf';
    }

    /**
     * Шлях до PDF-копії файлу (створює її за потреби).
     *
     * @return array{ok: true, path: string}|array{ok: false, error: string}
     */
    public static function ensure(string $storedName, string $originalName): array
    {
        $src = AttachmentService::path($storedName);
        $dest = self::pdfPath($storedName);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($src === null || $dest === null || !is_file($src) || !in_array($ext, self::EXTENSIONS, true)) {
            return ['ok' => false, 'error' => 'Цей файл не можна переглянути як PDF.'];
        }
        if (is_file($dest)) {
            return ['ok' => true, 'path' => $dest];
        }
        if (!self::supportsName($originalName)) {
            return ['ok' => false, 'error' => 'Перегляд у браузері не налаштовано на сервері для цього формату. Завантажте файл.'];
        }

        $lock = @fopen(sys_get_temp_dir() . '/itsm-preview.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            return ['ok' => false, 'error' => 'Не вдалося підготувати перегляд. Спробуйте пізніше.'];
        }
        try {
            if (is_file($dest)) { // поки чекали на чергу, інший запит міг уже створити PDF
                return ['ok' => true, 'path' => $dest];
            }
            if (self::libreOfficeReady()) {
                $result = self::convert($src, $dest, $ext);
                if ($result['ok'] || self::engine() === 'libreoffice' || !self::builtinFor($ext)) {
                    return $result;
                }
            }
            return self::convertBuiltin($src, $dest, $ext);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Вбудований конвертер (без LibreOffice). @return array{ok: true, path: string}|array{ok: false, error: string} */
    private static function convertBuiltin(string $src, string $dest, string $ext): array
    {
        try {
            OfficeToPdf::convert($src, $dest, $ext);
            return ['ok' => true, 'path' => $dest];
        } catch (OfficeException $e) {
            return ['ok' => false, 'error' => $e->getMessage() . ' Завантажте файл.'];
        }
    }

    /** @return array{ok: true, path: string}|array{ok: false, error: string} */
    private static function convert(string $src, string $dest, string $ext): array
    {
        $fail = static fn(string $m): array => ['ok' => false, 'error' => $m];
        $work = sys_get_temp_dir() . '/itsm-lo-' . bin2hex(random_bytes(6));
        if (!@mkdir($work . '/out', 0700, true)) {
            return $fail('Не вдалося створити тимчасовий каталог для конвертації.');
        }
        try {
            $input = $work . '/document.' . $ext;
            if (!@copy($src, $input)) {
                return $fail('Не вдалося прочитати файл.');
            }
            $timeout = max(10, (int) Config::get('library.preview.timeout', 90));
            $cmd = [
                (string) self::binary(), '--headless', '--norestore', '--nolockcheck', '--nodefault', '--nologo',
                '-env:UserInstallation=file://' . $work . '/profile',
                '--convert-to', 'pdf', '--outdir', $work . '/out', $input,
            ];
            $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $work, ['HOME' => $work, 'PATH' => '/usr/bin:/bin:/usr/local/bin']);
            if (!is_resource($proc)) {
                return $fail('Не вдалося запустити LibreOffice.');
            }
            $deadline = time() + $timeout;
            while (true) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    break;
                }
                if (time() >= $deadline) {
                    // Завершуємо весь процес-дерево: soffice запускає дочірній soffice.bin.
                    $pid = (int) $status['pid'];
                    if ($pid > 0 && function_exists('posix_kill')) {
                        @posix_kill($pid, 15);
                    }
                    proc_terminate($proc, 9);
                    proc_close($proc);
                    return $fail('Конвертація зайняла надто багато часу. Завантажте файл.');
                }
                usleep(100000);
            }
            proc_close($proc);

            $out = $work . '/out/document.pdf';
            if (!is_file($out) || filesize($out) < 100) {
                return $fail('Не вдалося перетворити файл у PDF (можливо, він пошкоджений). Завантажте файл.');
            }
            $head = (string) @file_get_contents($out, false, null, 0, 5);
            if ($head !== '%PDF-') {
                return $fail('Не вдалося перетворити файл у PDF.');
            }
            $tmp = $dest . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (!@copy($out, $tmp) || !@rename($tmp, $dest)) {
                @unlink($tmp);
                return $fail('Не вдалося зберегти PDF на сервері — перевірте права на storage/uploads.');
            }
            @chmod($dest, 0660);
            return ['ok' => true, 'path' => $dest];
        } finally {
            self::removeDir($work);
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
