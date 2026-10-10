<?php

namespace App\Services;

use App\Core\Config;
use App\Core\UploadLimits;

/**
 * Прийом, перевірка та зберігання файлів-вкладень (jpg, png, pdf) і доступ до них на диску.
 *
 * Принципи безпеки:
 *  - Тип файлу визначається за ВМІСТОМ (сигнатура, для зображень ще й getimagesize), а не за
 *    розширенням чи MIME, який надіслав браузер — обидва підробляються тривіально. Розширення
 *    при цьому мусить збігатися з вмістом: «shell.php», перейменований на .jpg, не пройде.
 *  - Ім'я на диску — випадкові 32 hex-символи без жодної частини від користувача, тож ні обхід
 *    директорій, ні підміна розширення неможливі; оригінальна назва лежить лише в БД.
 *  - Файли лежать у storage/uploads — поза веб-коренем; віддаються тільки через контролер з
 *    перевіркою прав і з фіксованим Content-Type (див. AttachmentController::download).
 *  - SVG і будь-які формати, що можуть нести скрипти, не дозволені взагалі.
 */
class AttachmentService
{
    /** Єдине джерело правди про дозволені типи: розширення => MIME. */
    public const ALLOWED = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];

    /** Лише зображення — для форм СТВОРЕННЯ тікета (PDF можна додати пізніше на сторінці тікета). */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png'];

    /** Скільки символів назви (без розширення) зберігаємо. */
    private const MAX_NAME_LENGTH = 150;

    public static function storageDir(): string
    {
        return rtrim((string) Config::get('attachments.dir'), '/');
    }

    /**
     * Приводить $_FILES['files'] (для <input multiple> PHP віддає «транспонований» масив)
     * до звичайного списку файлів.
     *
     * @return array<int, array{name: string, tmp_name: string, error: int, size: int}>
     */
    public static function normalizeFiles(array $files): array
    {
        if (!isset($files['name']) || !is_array($files['name'])) {
            // один файл без [] в імені поля — теж підтримуємо
            return isset($files['name']) && is_string($files['name']) ? [[
                'name' => $files['name'], 'tmp_name' => (string) $files['tmp_name'],
                'error' => (int) $files['error'], 'size' => (int) $files['size'],
            ]] : [];
        }
        $out = [];
        foreach ($files['name'] as $i => $name) {
            $out[] = [
                'name' => (string) $name,
                'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($files['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Перевіряє один завантажений файл і, якщо все гаразд, переносить його у сховище.
     *
     * @param array{name: string, tmp_name: string, error: int, size: int} $file
     * @param array{mimes?: string[], max_bytes?: int} $options mimes — дозволені MIME (за замовчуванням усі три типи); max_bytes — додатковий, жорсткіший за загальний, ліміт розміру
     * @return array{ok: true, name: string, stored_name: string, mime: string, size: int}|array{ok: false, error: string}
     */
    public static function store(array $file, array $options = []): array
    {
        $checked = self::validate($file, $options);
        if (!$checked['ok']) {
            return $checked;
        }

        $target = self::newStoragePath($storedName);
        if ($target === null || !@move_uploaded_file($file['tmp_name'], $target)) {
            return ['ok' => false, 'error' => self::WRITE_ERROR];
        }
        @chmod($target, 0660);

        return ['ok' => true, 'name' => $checked['name'], 'stored_name' => $storedName, 'mime' => $checked['mime'], 'size' => $checked['size']];
    }

    private const WRITE_ERROR = 'не вдалося зберегти файл на сервері — перевірте, що директорія storage/uploads доступна для запису веб-серверу';

    /** Випадкове ім'я для файлу у сховищі та повний шлях до нього (створює піддиректорію). Лише для нового файлу. Спільне для вкладень і бібліотеки документів. */
    public static function newStoragePath(?string &$storedName): ?string
    {
        $storedName = bin2hex(random_bytes(16));
        $dir = self::storageDir() . '/' . substr($storedName, 0, 2);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return null;
        }
        return $dir . '/' . $storedName;
    }

    /**
     * @param array{name: string, tmp_name: string, error: int, size: int} $file
     * @param array{mimes?: string[], max_bytes?: int} $options
     * @return array{ok: true, name: string, mime: string, size: int}|array{ok: false, error: string}
     */
    public static function validate(array $file, array $options = []): array
    {
        $allowedMimes = $options['mimes'] ?? array_values(array_unique(self::ALLOWED));

        $fail = static fn(string $message): array => ['ok' => false, 'error' => $message];

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return $fail('файл перевищує ліміт сервера (' . UploadLimits::human(UploadLimits::uploadMax()) . ' на файл, параметр PHP upload_max_filesize)');
            case UPLOAD_ERR_PARTIAL:
                return $fail('файл завантажився лише частково — спробуйте ще раз');
            default:
                return $fail('помилка сервера під час завантаження (код ' . $file['error'] . ') — зверніться до адміністратора');
        }

        // Лише файл, справді отриманий цим запитом через HTTP, а не довільний шлях на диску.
        if (!is_uploaded_file($file['tmp_name'])) {
            return $fail('файл не отримано сервером коректно');
        }

        $size = (int) filesize($file['tmp_name']);
        if ($size === 0) {
            return $fail('файл порожній');
        }
        $max = min(UploadLimits::effectiveFileMax(), (int) ($options['max_bytes'] ?? PHP_INT_MAX));
        if ($size > $max) {
            return $fail('файл завеликий (' . UploadLimits::human($size) . '), максимум — ' . UploadLimits::human($max));
        }

        $name = self::sanitizeName($file['name']);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$extension]) || !in_array(self::ALLOWED[$extension], $allowedMimes, true)) {
            return $fail('дозволені лише файли ' . self::allowedLabel($allowedMimes));
        }

        $mime = self::detectMime($file['tmp_name']);
        if ($mime === null) {
            return $fail('вміст не схожий на ' . self::allowedLabel($allowedMimes) . ' (файл пошкоджено або це інший формат під чужою назвою)');
        }
        if ($mime !== self::ALLOWED[$extension]) {
            return $fail('розширення «.' . $extension . '» не відповідає вмісту файлу');
        }

        return ['ok' => true, 'name' => $name, 'mime' => $mime, 'size' => $size];
    }

    /** «JPG, PNG та PDF» / «PNG та JPG» — для повідомлень про помилку. @param string[] $mimes */
    public static function allowedLabel(array $mimes): string
    {
        $labels = [];
        foreach (['image/png' => 'PNG', 'image/jpeg' => 'JPG', 'application/pdf' => 'PDF'] as $mime => $label) {
            if (in_array($mime, $mimes, true)) {
                $labels[] = $label;
            }
        }
        $last = array_pop($labels);
        return $labels ? implode(', ', $labels) . ' та ' . $last : (string) $last;
    }

    /**
     * Для файлів, що прийшли НЕ через HTTP-завантаження, а з листа (MIME-розбір): перевірка вмісту
     * без запису на диск. На відміну від веб-форми, розширення в назві з листа ВИПРАВЛЯЄТЬСЯ під
     * справжній тип (клієнти часто називають PNG як «photo.jpg») — повідомити відправника все одно нікому.
     *
     * @param array{mimes?: string[]} $options
     * @return array{ok: true, name: string, mime: string, size: int}|array{ok: false, error: string}
     */
    public static function inspectBytes(string $name, string $bytes, array $options = []): array
    {
        $allowedMimes = $options['mimes'] ?? array_values(array_unique(self::ALLOWED));
        $size = strlen($bytes);
        if ($size === 0) {
            return ['ok' => false, 'error' => 'файл порожній'];
        }
        $max = (int) Config::get('attachments.max_bytes', 10485760);
        if ($size > $max) {
            return ['ok' => false, 'error' => 'файл завеликий (' . UploadLimits::human($size) . '), максимум — ' . UploadLimits::human($max)];
        }
        $mime = self::detectMimeBytes($bytes);
        if ($mime === null || !in_array($mime, $allowedMimes, true)) {
            return ['ok' => false, 'error' => 'вміст не є допустимим зображенням ' . self::allowedLabel($allowedMimes)];
        }

        $clean = self::sanitizeName($name);
        $base = pathinfo($clean, PATHINFO_FILENAME);
        $extension = $mime === 'image/png' ? 'png' : ($mime === 'image/jpeg' ? 'jpg' : 'pdf');
        // Лишаємо оригінальну назву, якщо розширення вже правильне; інакше підміняємо на справжнє.
        $currentExt = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        $fixed = (self::ALLOWED[$currentExt] ?? null) === $mime ? $clean : ($base !== '' ? $base : 'image') . '.' . $extension;

        return ['ok' => true, 'name' => $fixed, 'mime' => $mime, 'size' => $size];
    }

    /**
     * Зберігає вже ПЕРЕВІРЕНИЙ inspectBytes() вміст у сховище.
     *
     * @return array{ok: true, stored_name: string}|array{ok: false, error: string}
     */
    public static function writeBytes(string $bytes): array
    {
        $target = self::newStoragePath($storedName);
        if ($target === null || @file_put_contents($target, $bytes, LOCK_EX) === false) {
            return ['ok' => false, 'error' => self::WRITE_ERROR];
        }
        @chmod($target, 0660);
        return ['ok' => true, 'stored_name' => $storedName];
    }

    /**
     * Зображення з форми СТВОРЕННЯ тікета (вибрані файли або вставлені зі скриншота): лише PNG/JPG, не більше
     * $maxFiles за раз. Тікет на цей момент уже створений — відхилений файл його не скасовує, користувач просто
     * бачить, що саме не прикріпилось.
     *
     * @param array $rawFiles $_FILES['files']
     * @return array{added: int, errors: string[]}
     */
    public static function attachCreationUploads(string $ownerType, int $ownerId, array $rawFiles, ?int $uploadedBy, string $source, int $maxFiles, ?int $maxBytes = null): array
    {
        $files = array_values(array_filter(
            self::normalizeFiles($rawFiles),
            fn(array $f): bool => $f['error'] !== UPLOAD_ERR_NO_FILE
        ));
        if (!$files) {
            return ['added' => 0, 'errors' => []];
        }

        $errors = [];
        if (count($files) > $maxFiles) {
            $errors[] = "Можна прикріпити не більше {$maxFiles} зображень за раз — прикріплено перші {$maxFiles}, решту відкинуто.";
            $files = array_slice($files, 0, $maxFiles);
        }

        $options = ['mimes' => self::IMAGE_MIMES];
        if ($maxBytes !== null) {
            $options['max_bytes'] = $maxBytes;
        }
        $result = self::attachUploads($ownerType, $ownerId, $files, $uploadedBy, $source, $options);

        return ['added' => $result['added'], 'errors' => array_merge($errors, $result['errors'])];
    }

    /**
     * Прикріплює пачку HTTP-завантажених файлів до тікета/задачі: перевірка → файл → рядок у БД → аудит.
     * Спільне для сторінки тікета/задачі, форми створення тікета та порталу. Часткова невдача не
     * скасовує успішних файлів: повертається і кількість доданих, і список помилок.
     *
     * @param array<int, array{name: string, tmp_name: string, error: int, size: int}> $files
     * @param array{mimes?: string[], max_bytes?: int} $options
     * @return array{added: int, errors: string[]}
     */
    public static function attachUploads(string $ownerType, int $ownerId, array $files, ?int $uploadedBy, string $source, array $options = []): array
    {
        $added = 0;
        $errors = [];
        foreach ($files as $file) {
            $result = self::store($file, $options);
            if (!$result['ok']) {
                $errors[] = '«' . self::sanitizeName($file['name']) . '»: ' . $result['error'] . '.';
                continue;
            }
            try {
                \App\Models\Attachment::create($ownerType, $ownerId, $result['name'], $result['stored_name'], $result['mime'], $result['size'], $uploadedBy, $source);
            } catch (\Throwable) {
                // Файл уже на диску, а запису в БД немає — прибираємо, щоб не лишати «осиротілий» файл.
                self::deleteFiles([$result['stored_name']]);
                $errors[] = '«' . $result['name'] . '»: не вдалося зберегти запис про файл.';
                continue;
            }
            self::createThumbnail($result['stored_name'], $result['mime']);
            \App\Models\Audit::log($ownerType, $ownerId, 'attachment_added', $uploadedBy, [
                'file' => $result['name'],
                'size_kb' => (int) ceil($result['size'] / 1024),
            ]);
            $added++;
        }
        return ['added' => $added, 'errors' => $errors];
    }

    /**
     * Тип за сигнатурою на початку файлу. Для зображень додатково getimagesize() — файл, що
     * лише починається з правильних байтів, але не є розбірним зображенням, відхиляється.
     */
    public static function detectMime(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 8);

        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            $mime = 'image/jpeg';
            $imageType = IMAGETYPE_JPEG;
        } elseif (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            $mime = 'image/png';
            $imageType = IMAGETYPE_PNG;
        } elseif (str_starts_with($head, '%PDF-')) {
            return 'application/pdf';
        } else {
            return null;
        }

        $info = @getimagesize($path);
        return ($info !== false && ($info[2] ?? null) === $imageType) ? $mime : null;
    }

    /** Те саме, що detectMime(), але для вмісту в пам'яті (вкладення з листа). */
    public static function detectMimeBytes(string $bytes): ?string
    {
        $head = substr($bytes, 0, 8);

        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            $mime = 'image/jpeg';
            $imageType = IMAGETYPE_JPEG;
        } elseif (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            $mime = 'image/png';
            $imageType = IMAGETYPE_PNG;
        } elseif (str_starts_with($head, '%PDF-')) {
            return 'application/pdf';
        } else {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        return ($info !== false && ($info[2] ?? null) === $imageType) ? $mime : null;
    }

    /**
     * Безпечна для зберігання й показу назва: без шляху, керувальних символів і символів
     * зміни напрямку тексту (на них тримається підробка на кшталт «photo\u202Egnp.exe»).
     */
    public static function sanitizeName(string $name): string
    {
        $name = mb_scrub($name, 'UTF-8');
        $name = preg_replace('~^.*[\\\\/]~su', '', $name) ?? '';                 // відкидаємо будь-який шлях
        $name = preg_replace('~[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]~u', '', $name) ?? '';
        $name = trim($name);

        // Розширення — те, що після ОСТАННЬОЇ крапки; відокремлюємо його ДО чистки назви, інакше
        // для імені на кшталт ".pdf" чи "  ...  .png" трим крапок з'їв би й саме розширення.
        $dot = strrpos($name, '.');
        if ($dot !== false && $dot < strlen($name) - 1) {
            $base = substr($name, 0, $dot);
            $extension = strtolower(substr($name, $dot + 1));
        } else {
            $base = rtrim($name, '.');
            $extension = '';
        }
        $base = trim(mb_substr($base, 0, self::MAX_NAME_LENGTH), " .\t");

        if ($base === '') {
            $base = 'file';
        }
        return $extension !== '' ? $base . '.' . $extension : $base;
    }

    /** Шлях до файлу на диску за іменем зі сховища; null, якщо ім'я не схоже на те, що ми генеруємо. */
    public static function path(string $storedName): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $storedName)) {
            return null; // захист від зіпсованого запису в БД: у шлях не потрапить нічого, крім 32 hex
        }
        return self::storageDir() . '/' . substr($storedName, 0, 2) . '/' . $storedName;
    }

    // ------------------------------------------------------------------ мініатюри

    /** Не створюємо мініатюру для зображень більших за цю кількість пікселів — захист пам'яті від «бомб розпакування». */
    private const THUMB_MAX_SOURCE_PIXELS = 40000000;

    /** Шлях до мініатюри поруч з оригіналом (`<ім'я>_t`); null, якщо ім'я не схоже на те, що ми генеруємо. */
    public static function thumbPath(string $storedName): ?string
    {
        $path = self::path($storedName);
        return $path === null ? null : $path . '_t';
    }

    /** Чи доступне створення мініатюр (потрібне розширення GD). */
    public static function thumbnailsAvailable(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng');
    }

    /**
     * Створює зменшену копію зображення під час завантаження (найдовша сторона — attachments.thumb_px, 320 за замовчуванням).
     * Best effort: без GD, для PDF, для малого зображення (мініатюра не потрібна — показується оригінал) чи при будь-якій
     * помилці повертає false, і вкладення просто показується оригіналом. Виняток вкладення не скасовує.
     */
    public static function createThumbnail(string $storedName, string $mime): bool
    {
        $src = self::path($storedName);
        $dest = self::thumbPath($storedName);
        if ($src === null || $dest === null || !is_file($src) || !in_array($mime, self::IMAGE_MIMES, true) || !self::thumbnailsAvailable()) {
            return false;
        }
        $max = max(32, (int) Config::get('attachments.thumb_px', 320));

        try {
            $info = @getimagesize($src);
            if ($info === false) {
                return false;
            }
            [$w, $h] = $info;
            if ($w < 1 || $h < 1 || $w * $h > self::THUMB_MAX_SOURCE_PIXELS || max($w, $h) <= $max) {
                return false;
            }

            $image = $mime === 'image/png' ? @imagecreatefrompng($src) : @imagecreatefromjpeg($src);
            if ($image === false) {
                return false;
            }
            // Фото з телефону зберігають поворот в EXIF — без цього мініатюра лягала б набік.
            if ($mime === 'image/jpeg' && function_exists('exif_read_data') && function_exists('imagerotate')) {
                $exif = @exif_read_data($src);
                $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
                if ($angle !== 0 && ($rotated = imagerotate($image, $angle, 0)) !== false) {
                    $image = $rotated;
                    [$w, $h] = [imagesx($image), imagesy($image)];
                }
            }

            $ratio = $max / max($w, $h);
            $tw = max(1, (int) round($w * $ratio));
            $th = max(1, (int) round($h * $ratio));
            $thumb = imagecreatetruecolor($tw, $th);
            if ($mime === 'image/png') {
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
                imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
            }
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, $tw, $th, $w, $h);

            $tmp = $dest . '.tmp';
            $ok = $mime === 'image/png' ? @imagepng($thumb, $tmp, 6) : @imagejpeg($thumb, $tmp, 82);
            if (!$ok || !@rename($tmp, $dest)) {
                @unlink($tmp);
                return false;
            }
            @chmod($dest, 0660);
            return true;
        } catch (\Throwable $e) {
            error_log('[itsm] thumbnail failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Шлях до мініатюри для показу: існуючий, а для вкладень, завантажених до появи функції, — створений «на льоту» при
     * першому запиті. null — мініатюри немає (мале зображення, не вдалося створити): віддаємо оригінал.
     */
    public static function thumbnailFor(string $storedName, string $mime): ?string
    {
        $thumb = self::thumbPath($storedName);
        if ($thumb === null) {
            return null;
        }
        if (is_file($thumb)) {
            return $thumb;
        }
        return self::createThumbnail($storedName, $mime) ? $thumb : null;
    }

    /** Видаляє файли за іменами зі сховища (best effort — відсутній файл не є помилкою). @param string[] $storedNames */
    public static function deleteFiles(array $storedNames): void
    {
        foreach ($storedNames as $storedName) {
            $path = self::path((string) $storedName);
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
            // Мініатюра (якщо була) видаляється разом з оригіналом.
            $thumb = self::thumbPath((string) $storedName);
            if ($thumb !== null && is_file($thumb)) {
                @unlink($thumb);
            }
        }
    }
}
