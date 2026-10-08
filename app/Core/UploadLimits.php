<?php

namespace App\Core;

/**
 * Ліміти завантаження файлів. Окрема від самого модуля вкладень, бо PHP має власні
 * обмеження (upload_max_filesize — на один файл, post_max_size — на весь запит), які
 * діють ДО нашого коду і поводяться неочевидно: коли запит перевищує post_max_size,
 * PHP мовчки викидає все тіло — $_POST і $_FILES порожні, і централізована CSRF-перевірка
 * в Router видала б оманливе «Сесія застаріла». Тому ці ситуації розпізнаються тут явно.
 */
class UploadLimits
{
    /** "2M" / "512K" / "1G" / "8388608" -> байти. 0 і -1 в php.ini означають «без обмеження». */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $number = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public static function uploadMax(): int
    {
        return self::iniBytes((string) ini_get('upload_max_filesize'));
    }

    public static function postMax(): int
    {
        return self::iniBytes((string) ini_get('post_max_size'));
    }

    /** Реальний максимум розміру ОДНОГО файлу: менше з ліміту застосунку та двох PHP-лімітів. */
    public static function effectiveFileMax(?int $appMax = null): int
    {
        return min($appMax ?? (int) Config::get('attachments.max_bytes', 10485760), self::uploadMax(), self::postMax());
    }

    /** Чи PHP відкинув тіло POST-запиту через перевищення post_max_size. */
    public static function postBodyTruncated(): bool
    {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        return $length > 0 && $length > self::postMax() && empty($_POST) && empty($_FILES);
    }

    /** 10485760 -> "10 МБ", 524288 -> "512 КБ". */
    public static function human(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' МБ';
        }
        return max(1, (int) round($bytes / 1024)) . ' КБ';
    }
}
