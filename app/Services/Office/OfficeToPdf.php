<?php

declare(strict_types=1);

namespace App\Services\Office;

/** Фасад вбудованого конвертера Office → PDF (без LibreOffice й інших зовнішніх програм). Формати: DOCX, XLSX. */
final class OfficeToPdf
{
    public const EXTENSIONS = ['docx', 'xlsx'];

    public static function supports(string $extension): bool
    {
        return in_array(strtolower($extension), self::EXTENSIONS, true);
    }

    /**
     * Конвертує файл у PDF. Тип визначається за $extension (файли бібліотеки зберігаються без розширення).
     *
     * @throws OfficeException якщо файл не вдалося прочитати чи перетворити
     */
    public static function convert(string $src, string $dest, string $extension): void
    {
        $extension = strtolower($extension);
        if (!self::supports($extension)) {
            throw new OfficeException('Формат «.' . $extension . '» вбудований конвертер не підтримує.');
        }
        if (!is_file($src)) {
            throw new OfficeException('Файл не знайдено.');
        }
        $tmp = $dest . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $prevLimit = ini_get('memory_limit');
        if ($prevLimit !== '-1' && self::bytes((string) $prevLimit) < 384 * 1048576) {
            @ini_set('memory_limit', '384M');
        }
        @set_time_limit(90);
        try {
            if ($extension === 'docx') {
                DocxToPdf::convert($src, $tmp);
            } else {
                XlsxToPdf::convert($src, $tmp);
            }
            if (!@rename($tmp, $dest)) {
                throw new OfficeException('Не вдалося зберегти PDF.');
            }
            @chmod($dest, 0660);
        } catch (OfficeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new OfficeException('Не вдалося перетворити файл у PDF (' . $e->getMessage() . ').', 0, $e);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private static function bytes(string $v): int
    {
        $n = (int) $v;
        return match (strtoupper(substr(trim($v), -1))) {
            'G' => $n * 1073741824,
            'M' => $n * 1048576,
            'K' => $n * 1024,
            default => $n,
        };
    }
}
