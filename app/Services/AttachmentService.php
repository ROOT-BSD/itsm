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
    public const ALLOWED = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];

    /** Документи Office, які можна прикріплювати до задач (розширення => MIME; перевірка вмісту й макросів — LibraryService). */
    public const OFFICE_EXTENSIONS = ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'];

    /** Чи розширена колонка attachments.mime_type (міграція 037) — без неї Office-файли не приймаємо. */
    public static function officeReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $stmt = \App\Core\Database::connection()->query(
                "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attachments' AND COLUMN_NAME = 'mime_type'"
            );
            $ready = (int) $stmt->fetchColumn() >= 100;
        }
        return $ready;
    }

    /** Чи MIME належить до документів Office (їх не показуємо в браузері, а завантажуємо або переглядаємо як PDF). */
    public static function isOfficeMime(string $mime): bool
    {
        foreach (self::OFFICE_EXTENSIONS as $ext) {
            if (LibraryService::TYPES[$ext] === $mime) {
                return true;
            }
        }
        return false;
    }

    /** Лише зображення — для форм СТВОРЕННЯ тікета (PDF можна додати пізніше на сторінці тікета). */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

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
        self::shareWithWebServer($target, false);

        return ['ok' => true, 'name' => $checked['name'], 'stored_name' => $storedName, 'mime' => $checked['mime'], 'size' => $checked['size']];
    }

    private const WRITE_ERROR = 'не вдалося зберегти файл на сервері — перевірте, що директорія storage/uploads доступна для запису веб-серверу';

    /** Випадкове ім'я для файлу у сховищі та повний шлях до нього (створює піддиректорію). Лише для нового файлу. Спільне для вкладень і бібліотеки документів. */
    public static function newStoragePath(?string &$storedName): ?string
    {
        $storedName = bin2hex(random_bytes(16));
        $dir = self::storageDir() . '/' . substr($storedName, 0, 2);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0770, true) && !is_dir($dir)) {
                return null;
            }
            self::shareWithWebServer($dir, true);
        }
        return $dir . '/' . $storedName;
    }

    /**
     * Робить щойно створений каталог/файл доступним тій самій групі, що й корінь сховища, — як у веб-сервера.
     * Без цього файл, який записав інший користувач (cron `bin/fetch-mail.php` під root чи власним обліковим записом),
     * лишається з чужою групою й правами 770/660: веб-сервер його не бачить, і сторінка відповідає «Файл не знайдено
     * на сервері». Best effort: змінити групу вдається власнику файлу (член групи) або root; інакше нічого не змінюємо.
     */
    public static function shareWithWebServer(string $path, bool $isDir): void
    {
        $root = self::storageDir();
        $gid = @filegroup($root);
        $uid = @fileowner($root);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && $uid !== false) {
            @chown($path, $uid);
        }
        if ($gid !== false && @filegroup($path) !== $gid) {
            @chgrp($path, $gid);
        }
        @chmod($path, $isDir ? 02770 : 0660);
    }

    /** Чому файл не віддається: 'permissions' — він є, але веб-сервер не має доступу; 'missing' — його справді немає на диску. */
    public static function diagnose(string $storedName): string
    {
        $path = self::path($storedName);
        if ($path === null) {
            return 'missing';
        }
        $dir = dirname($path);
        if (is_file($path)) {
            return is_readable($path) ? 'ok' : 'permissions';
        }
        if (is_dir($dir) && (!is_readable($dir) || !is_executable($dir))) {
            return 'permissions';
        }
        return 'missing';
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

        // Документи Office (лише там, де дозволено опцією office): вміст і відсутність макросів перевіряє LibraryService.
        if (!empty($options['office']) && in_array($extension, self::OFFICE_EXTENSIONS, true) && self::officeReady()) {
            $problem = LibraryService::contentProblem($file['tmp_name'], $extension);
            return $problem === null
                ? ['ok' => true, 'name' => $name, 'mime' => LibraryService::TYPES[$extension], 'size' => $size]
                : $fail($problem);
        }
        if (!isset(self::ALLOWED[$extension]) || !in_array(self::ALLOWED[$extension], $allowedMimes, true)) {
            return $fail('дозволені лише файли ' . (!empty($options['office']) && self::officeReady() ? 'JPG, PNG, GIF, WebP, PDF, ' . strtoupper(implode(', ', self::OFFICE_EXTENSIONS)) : self::allowedLabel($allowedMimes)));
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
        foreach (['image/png' => 'PNG', 'image/jpeg' => 'JPG', 'image/gif' => 'GIF', 'image/webp' => 'WebP', 'application/pdf' => 'PDF'] as $mime => $label) {
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
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? 'pdf';
        // Лишаємо оригінальну назву, якщо розширення вже правильне; інакше підміняємо на справжнє.
        $currentExt = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        $fixed = (self::ALLOWED[$currentExt] ?? null) === $mime ? $clean : ($base !== '' ? $base : 'image') . '.' . $extension;

        return ['ok' => true, 'name' => $fixed, 'mime' => $mime, 'size' => $size];
    }

    /**
     * Перевірка вмісту документа (PDF або Office) з листа без запису на диск. Розширення береться з назви й має
     * відповідати вмісту; для Office перевіряються структура й відсутність макросів (LibraryService::contentProblem).
     *
     * @return array{ok: true, name: string, mime: string, size: int}|array{ok: false, error: string}
     */
    public static function inspectDocumentBytes(string $name, string $bytes): array
    {
        $size = strlen($bytes);
        if ($size === 0) {
            return ['ok' => false, 'error' => 'файл порожній'];
        }
        $max = (int) Config::get('attachments.max_bytes', 10485760);
        if ($size > $max) {
            return ['ok' => false, 'error' => 'файл завеликий (' . UploadLimits::human($size) . '), максимум — ' . UploadLimits::human($max)];
        }
        $clean = self::sanitizeName($name);
        $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        $isOffice = in_array($ext, self::OFFICE_EXTENSIONS, true);
        if ($ext !== 'pdf' && !$isOffice) {
            return ['ok' => false, 'error' => 'непідтримуваний тип файлу'];
        }
        if ($isOffice && !self::officeReady()) {
            return ['ok' => false, 'error' => 'документи Office поки не підтримуються (не застосовано міграцію 037)'];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'eml');
        if ($tmp === false || @file_put_contents($tmp, $bytes) === false) {
            return ['ok' => false, 'error' => 'не вдалося перевірити файл'];
        }
        try {
            $problem = LibraryService::contentProblem($tmp, $ext);
        } finally {
            @unlink($tmp);
        }
        return $problem === null
            ? ['ok' => true, 'name' => $clean, 'mime' => LibraryService::TYPES[$ext], 'size' => $size]
            : ['ok' => false, 'error' => $problem];
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
        self::shareWithWebServer($target, false);
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
     * @return array{added: int, errors: string[], ids: array<int, int>} ids: індекс файлу => id вкладення (лише успішні)
     */
    public static function attachUploads(string $ownerType, int $ownerId, array $files, ?int $uploadedBy, string $source, array $options = []): array
    {
        $added = 0;
        $errors = [];
        $ids = []; // індекс файлу у вхідному списку => id створеного вкладення
        foreach (array_values($files) as $index => $file) {
            $result = self::store($file, $options);
            if (!$result['ok']) {
                $errors[] = '«' . self::sanitizeName($file['name']) . '»: ' . $result['error'] . '.';
                continue;
            }
            try {
                $ids[$index] = \App\Models\Attachment::create($ownerType, $ownerId, $result['name'], $result['stored_name'], $result['mime'], $result['size'], $uploadedBy, $source);
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
        return ['added' => $added, 'errors' => $errors, 'ids' => $ids];
    }

    /**
     * Тип за сигнатурою на початку файлу. Для зображень додатково getimagesize() — файл, що
     * лише починається з правильних байтів, але не є розбірним зображенням, відхиляється.
     */
    public static function detectMime(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 16);
        $kind = self::sniff($head);
        if ($kind === null) {
            return null;
        }
        if ($kind[1] === null) {
            return $kind[0]; // PDF
        }
        $info = @getimagesize($path);
        if ($info === false || ($info[2] ?? null) !== $kind[1]) {
            return null;
        }
        return self::plausible($kind[0], $info, fn() => (string) @file_get_contents($path)) ? $kind[0] : null;
    }

    /** Найбільша площа GIF/WebP у пікселях, яку приймаємо (захист від «бомб розпакування» й підроблених заголовків). */
    private const MAX_ANIMATED_PIXELS = 40000000;

    /**
     * Додаткова перевірка GIF і WebP: їхній заголовок читається дуже поблажливо (достатньо «GIF89a» + будь-які байти),
     * тож сигнатури й getimagesize() замало. GIF має декодуватись (GD, перший кадр), а розміри — бути правдоподібними;
     * WebP — мати узгоджений із довжиною файлу розмір RIFF-контейнера. JPEG/PNG тут не перевіряються.
     *
     * @param array<int, mixed> $info результат getimagesize
     * @param callable(): string $bytes вміст файлу (читається лише за потреби)
     */
    private static function plausible(string $mime, array $info, callable $bytes): bool
    {
        if ($mime !== 'image/gif' && $mime !== 'image/webp') {
            return true;
        }
        $w = (int) ($info[0] ?? 0);
        $h = (int) ($info[1] ?? 0);
        if ($w < 1 || $h < 1 || $w * $h > self::MAX_ANIMATED_PIXELS) {
            return false;
        }
        $data = $bytes();
        if ($mime === 'image/webp') {
            // RIFF: байти 4–7 — довжина решти файлу (little-endian); допускаємо розбіжність у 1 байт (вирівнювання).
            $declared = unpack('V', substr($data, 4, 4))[1] ?? -1;
            return abs(($declared + 8) - strlen($data)) <= 1;
        }
        if (function_exists('imagecreatefromstring')) {
            return @imagecreatefromstring($data) !== false;
        }
        return strlen($data) > 6 && str_ends_with($data, ';');
    }

    /**
     * Тип за першими байтами: [MIME, IMAGETYPE_* або null для PDF] або null, якщо сигнатура не з дозволених.
     * Сигнатура лише попередній відбір; зображення потім підтверджує getimagesize().
     *
     * @return array{0: string, 1: ?int}|null
     */
    private static function sniff(string $head): ?array
    {
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return ['image/jpeg', IMAGETYPE_JPEG];
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return ['image/png', IMAGETYPE_PNG];
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return ['image/gif', IMAGETYPE_GIF];
        }
        if (strlen($head) >= 12 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return ['image/webp', IMAGETYPE_WEBP];
        }
        if (str_starts_with($head, '%PDF-')) {
            return ['application/pdf', null];
        }
        return null;
    }

    /** Те саме, що detectMime(), але для вмісту в пам'яті (вкладення з листа). */
    public static function detectMimeBytes(string $bytes): ?string
    {
        $kind = self::sniff(substr($bytes, 0, 16));
        if ($kind === null) {
            return null;
        }
        if ($kind[1] === null) {
            return $kind[0];
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ($info[2] ?? null) !== $kind[1]) {
            return null;
        }
        return self::plausible($kind[0], $info, fn() => $bytes) ? $kind[0] : null;
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

    /** Чи вміє GD прочитати зображення цього типу (GIF і WebP залежать від збірки GD). */
    public static function canDecode(string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg'),
            'image/png' => function_exists('imagecreatefrompng'),
            'image/gif' => function_exists('imagecreatefromgif'),
            'image/webp' => function_exists('imagecreatefromwebp'),
            default => false,
        };
    }

    /** Тип самої мініатюри: JPEG лишається JPEG, а PNG, GIF і WebP зберігаються як PNG (прозорість зберігається). */
    public static function thumbMime(string $mime): string
    {
        return $mime === 'image/jpeg' ? 'image/jpeg' : 'image/png';
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
        if ($src === null || $dest === null || !is_file($src) || !in_array($mime, self::IMAGE_MIMES, true) || !self::thumbnailsAvailable() || !self::canDecode($mime)) {
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

            $image = match ($mime) {
                'image/png' => @imagecreatefrompng($src),
                'image/gif' => @imagecreatefromgif($src),   // анімований GIF: мініатюра — перший кадр
                'image/webp' => @imagecreatefromwebp($src), // анімований WebP GD не читає — тоді мініатюри не буде, показується оригінал
                default => @imagecreatefromjpeg($src),
            };
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
            if ($mime !== 'image/jpeg') {
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
                imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
            }
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, $tw, $th, $w, $h);

            $tmp = $dest . '.tmp';
            $ok = self::thumbMime($mime) === 'image/png' ? @imagepng($thumb, $tmp, 6) : @imagejpeg($thumb, $tmp, 82);
            if (!$ok || !@rename($tmp, $dest)) {
                @unlink($tmp);
                return false;
            }
            self::shareWithWebServer($dest, false);
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
            // PDF-копія для перегляду документів бібліотеки (LibraryPreview).
            $pdf = $path === null ? null : $path . '_pdf';
            if ($pdf !== null && is_file($pdf)) {
                @unlink($pdf);
            }
        }
    }
}
