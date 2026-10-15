<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Файлове сховище тексту сторінок вікі та їхніх версій (storage/wiki/<id сторінки>/).
 *
 *   current.md   — поточний текст сторінки;
 *   rev-N.md     — текст версії N з історії.
 *
 * Метадані (назва, видимість, номер версії, автор) лишаються в БД. Для сторінок, ще не перенесених на диск
 * (bin/migrate-wiki-to-files.php), читання тихо повертається до тексту в БД, тож система працює до й після міграції.
 * Файли пишуться атомарно (тимчасовий файл + rename), права 660 / каталоги 770.
 */
final class WikiStore
{
    public static function baseDir(): string
    {
        return rtrim((string) Config::get('wiki.dir'), '/');
    }

    private static function pageDir(int $pageId): string
    {
        return self::baseDir() . '/' . $pageId;
    }

    public static function currentPath(int $pageId): string
    {
        return self::pageDir($pageId) . '/current.md';
    }

    public static function revisionPath(int $pageId, int $version): string
    {
        return self::pageDir($pageId) . '/rev-' . $version . '.md';
    }

    public static function hasCurrent(int $pageId): bool
    {
        return is_file(self::currentPath($pageId));
    }

    /** Поточний текст з диска або null, якщо файлу немає. */
    public static function readCurrent(int $pageId): ?string
    {
        return self::read(self::currentPath($pageId));
    }

    public static function readRevision(int $pageId, int $version): ?string
    {
        return self::read(self::revisionPath($pageId, $version));
    }

    private static function read(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $data = @file_get_contents($path);
        return $data === false ? null : $data;
    }

    /** Записує поточний текст і (за $version > 0) файл версії. @throws \RuntimeException якщо запис не вдався */
    public static function write(int $pageId, int $version, string $content, bool $current = true): void
    {
        $dir = self::pageDir($pageId);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не вдалося створити каталог вікі ' . $dir . ' — перевірте права на storage/wiki.');
        }
        if ($version > 0) {
            self::atomic(self::revisionPath($pageId, $version), $content);
        }
        if ($current) {
            self::atomic(self::currentPath($pageId), $content);
        }
    }

    private static function atomic(string $path, string $content): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            throw new \RuntimeException('Не вдалося записати файл вікі ' . basename($path) . ' — перевірте права на storage/wiki.');
        }
        @chmod($tmp, 0660);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Не вдалося зберегти файл вікі ' . basename($path) . '.');
        }
    }

    /** Видаляє файл однієї версії (best effort). */
    public static function deleteRevision(int $pageId, int $version): void
    {
        $path = self::revisionPath($pageId, $version);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Видаляє всі файли сторінки (best effort). */
    public static function deletePage(int $pageId): void
    {
        $dir = self::pageDir($pageId);
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
