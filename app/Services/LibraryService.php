<?php

namespace App\Services;

use App\Core\Config;
use App\Core\UploadLimits;

/**
 * Прийом і перевірка файлів бібліотеки документів. Принципи ті самі, що й у AttachmentService (на якому це
 * й побудовано: спільне сховище storage/uploads, випадкові імена, очищення назви): тип визначається за ВМІСТОМ, а
 * не за розширенням чи MIME від браузера, і мусить збігатися з розширенням.
 *
 * Дозволено лише формати, яким не потрібно довіряти як активному вмісту: PDF, JPG/PNG, документи Office
 * (docx/xlsx/pptx — БЕЗ макросів), OpenDocument (odt/ods/odp), звичайний текст і CSV. Архіви, виконувані файли,
 * HTML/SVG, файли з макросами (docm/xlsm) — заборонені. Віддаються тільки через контролер із фіксованим Content-Type.
 */
class LibraryService
{
    private const OOXML = 'application/vnd.openxmlformats-officedocument.';
    private const ODF = 'application/vnd.oasis.opendocument.';

    /** Єдине джерело правди про дозволені типи: розширення => MIME. */
    public const TYPES = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'docx' => self::OOXML . 'wordprocessingml.document',
        'xlsx' => self::OOXML . 'spreadsheetml.sheet',
        'pptx' => self::OOXML . 'presentationml.presentation',
        'odt'  => self::ODF . 'text',
        'ods'  => self::ODF . 'spreadsheet',
        'odp'  => self::ODF . 'presentation',
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
    ];

    /** Що браузер може показати сам (решта лише завантажується). */
    public const INLINE_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    /** Для підказки в формі: «.pdf,.jpg,…» */
    public static function acceptAttribute(): string
    {
        return '.' . implode(',.', array_keys(self::TYPES));
    }

    /** Для повідомлень: «PDF, DOCX, XLSX…» (без дублювання jpeg). */
    public static function allowedLabel(): string
    {
        return strtoupper(implode(', ', array_diff(array_keys(self::TYPES), ['jpeg'])));
    }

    public static function maxBytes(): int
    {
        return UploadLimits::effectiveFileMax((int) Config::get('library.max_bytes', 25 * 1048576));
    }

    /**
     * Перевіряє завантажений файл і переносить його у сховище.
     *
     * @param array{name: string, tmp_name: string, error: int, size: int} $file
     * @return array{ok: true, name: string, stored_name: string, mime: string, size: int}|array{ok: false, error: string}
     */
    public static function store(array $file): array
    {
        $checked = self::validate($file);
        if (!$checked['ok']) {
            return $checked;
        }
        $target = AttachmentService::newStoragePath($storedName);
        if ($target === null || !@move_uploaded_file($file['tmp_name'], $target)) {
            return ['ok' => false, 'error' => 'не вдалося зберегти файл на сервері — перевірте, що директорія storage/uploads доступна для запису веб-серверу'];
        }
        @chmod($target, 0660);
        return ['ok' => true, 'name' => $checked['name'], 'stored_name' => $storedName, 'mime' => $checked['mime'], 'size' => $checked['size']];
    }

    /**
     * @param array{name: string, tmp_name: string, error: int, size: int} $file
     * @return array{ok: true, name: string, mime: string, size: int}|array{ok: false, error: string}
     */
    public static function validate(array $file): array
    {
        $fail = static fn(string $message): array => ['ok' => false, 'error' => $message];

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return $fail('оберіть файл');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return $fail('файл перевищує ліміт сервера (' . UploadLimits::human(UploadLimits::uploadMax()) . ' на файл, параметр PHP upload_max_filesize)');
            case UPLOAD_ERR_PARTIAL:
                return $fail('файл завантажився лише частково — спробуйте ще раз');
            default:
                return $fail('помилка сервера під час завантаження (код ' . $file['error'] . ') — зверніться до адміністратора');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return $fail('файл не отримано сервером коректно');
        }

        $size = (int) filesize($file['tmp_name']);
        if ($size === 0) {
            return $fail('файл порожній');
        }
        $max = self::maxBytes();
        if ($size > $max) {
            return $fail('файл завеликий (' . UploadLimits::human($size) . '), максимум — ' . UploadLimits::human($max));
        }

        $name = AttachmentService::sanitizeName($file['name']);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$extension])) {
            return $fail('дозволені лише файли: ' . self::allowedLabel());
        }

        $problem = self::contentProblem($file['tmp_name'], $extension);
        if ($problem !== null) {
            return $fail($problem);
        }
        return ['ok' => true, 'name' => $name, 'mime' => self::TYPES[$extension], 'size' => $size];
    }

    /** Чому вміст файлу не відповідає розширенню; null — усе гаразд. */
    public static function contentProblem(string $path, string $extension): ?string
    {
        $mismatch = 'вміст файлу не відповідає розширенню «.' . $extension . '» (файл пошкоджено або це інший формат під чужою назвою)';

        switch ($extension) {
            case 'pdf':
            case 'jpg':
            case 'jpeg':
            case 'png':
                return AttachmentService::detectMime($path) === self::TYPES[$extension] ? null : $mismatch;

            case 'docx':
                return self::zipProblem($path, '[Content_Types].xml', 'word/document.xml', 'word/vbaProject.bin');
            case 'xlsx':
                return self::zipProblem($path, '[Content_Types].xml', 'xl/workbook.xml', 'xl/vbaProject.bin');
            case 'pptx':
                return self::zipProblem($path, '[Content_Types].xml', 'ppt/presentation.xml', 'ppt/vbaProject.bin');

            case 'odt':
            case 'ods':
            case 'odp':
                return self::odfProblem($path, self::TYPES[$extension]);

            case 'txt':
            case 'csv':
                return self::textProblem($path);
        }
        return $mismatch;
    }

    /** Файл Office Open XML: коректний ZIP з потрібними частинами й без макросів. */
    private static function zipProblem(string $path, string $contentTypes, string $mainPart, string $macroPart): ?string
    {
        $entries = self::zipEntries($path);
        if ($entries === null) {
            return 'файл пошкоджено або це не документ Office';
        }
        if (!isset($entries[$contentTypes]) || !isset($entries[$mainPart])) {
            return 'вміст файлу не відповідає його розширенню (файл пошкоджено або це інший формат під чужою назвою)';
        }
        if (isset($entries[$macroPart])) {
            return 'файли з макросами не приймаються';
        }
        return null;
    }

    /** Файл OpenDocument: перший запис ZIP — «mimetype» (без стиснення) із очікуваним типом. */
    private static function odfProblem(string $path, string $expectedMime): ?string
    {
        $entries = self::zipEntries($path);
        if ($entries === null) {
            return 'файл пошкоджено або це не документ OpenDocument';
        }
        $first = array_key_first($entries);
        $ok = false;
        if ($first === 'mimetype' && $entries['mimetype']['method'] === 0 && $entries['mimetype']['size'] <= 200) {
            $ok = trim((string) self::zipStoredContent($path, $entries['mimetype'])) === $expectedMime;
        }
        return $ok ? null : 'вміст файлу не відповідає його розширенню (файл пошкоджено або це інший формат під чужою назвою)';
    }

    /**
     * Читає перелік записів ZIP із центрального каталогу самого файлу — НІЧОГО не розпаковує й не потребує розширення
     * PHP «zip» (воно є не на кожному сервері). Відхиляє підозріло «роздуті» архіви й некоректні структури; ZIP64 не
     * підтримується (документам Office він не потрібен).
     *
     * @return array<string, array{method: int, size: int, csize: int, offset: int}>|null ім'я запису => опис, у порядку каталогу; null — не ZIP чи пошкоджено
     */
    private static function zipEntries(string $path): ?array
    {
        $size = (int) @filesize($path);
        $fh = @fopen($path, 'rb');
        if ($fh === false || $size < 22) {
            return null;
        }
        try {
            $tailLength = min($size, 65557);
            fseek($fh, $size - $tailLength);
            $tail = (string) fread($fh, $tailLength);
            $pos = strrpos($tail, "PK\x05\x06");
            if ($pos === false || strlen($tail) - $pos < 22) {
                return null;
            }
            $eocd = unpack('vdisk/vcdDisk/vcdHere/vcount/VcdSize/VcdOffset/vcommentLength', substr($tail, $pos + 4, 18));
            if ($eocd === false || $eocd['count'] < 1 || $eocd['count'] > 10000 || $eocd['count'] === 0xFFFF
                || $eocd['cdOffset'] === 0xFFFFFFFF || $eocd['cdSize'] > 8 * 1048576
                || $eocd['cdOffset'] + $eocd['cdSize'] > $size) {
                return null;
            }
            fseek($fh, $eocd['cdOffset']);
            $cd = (string) fread($fh, $eocd['cdSize']);
            if (strlen($cd) !== $eocd['cdSize']) {
                return null;
            }

            $entries = [];
            $offset = 0;
            $total = 0;
            for ($i = 0; $i < $eocd['count']; $i++) {
                if (substr($cd, $offset, 4) !== "PK\x01\x02" || $offset + 46 > strlen($cd)) {
                    return null;
                }
                $h = unpack('vmethod/x4/Vcrc/Vcsize/Vsize/vnameLen/vextraLen/vcommentLen/x4/x4/Vlocal', substr($cd, $offset + 10, 36));
                if ($h === false) {
                    return null;
                }
                $name = substr($cd, $offset + 46, $h['nameLen']);
                if (strlen($name) !== $h['nameLen'] || $h['local'] >= $size) {
                    return null;
                }
                $total += $h['size'];
                if ($total > 2 * 1073741824) { // 2 ГБ після розпакування — це вже не документ
                    return null;
                }
                $entries[$name] = ['method' => $h['method'], 'size' => $h['size'], 'csize' => $h['csize'], 'offset' => $h['local']];
                $offset += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
            }
            return $entries;
        } finally {
            fclose($fh);
        }
    }

    /** Вміст НЕстисненого запису ZIP (метод 0) — лише для крихітного «mimetype» в OpenDocument. */
    private static function zipStoredContent(string $path, array $entry): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            fseek($fh, $entry['offset']);
            $header = (string) fread($fh, 30);
            if (strlen($header) < 30 || substr($header, 0, 4) !== "PK\x03\x04") {
                return null;
            }
            $lengths = unpack('vnameLen/vextraLen', substr($header, 26, 4));
            fseek($fh, $entry['offset'] + 30 + $lengths['nameLen'] + $lengths['extraLen']);
            $data = (string) fread($fh, $entry['csize']);
            return strlen($data) === $entry['csize'] ? $data : null;
        } finally {
            fclose($fh);
        }
    }

    /** Звичайний текст: жодних керувальних символів (NUL і подібних), що вказують на двійковий вміст. */
    private static function textProblem(string $path): ?string
    {
        $bytes = (string) @file_get_contents($path);
        if ($bytes === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $bytes) === 1) {
            return 'це не текстовий файл (знайдено двійкові дані)';
        }
        return null;
    }
}
