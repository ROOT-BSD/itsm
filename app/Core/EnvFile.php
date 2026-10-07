<?php

namespace App\Core;

/**
 * Безпечне читання/запис окремих значень у файл .env із веб-інтерфейсу —
 * для AD-підключення (Адмін-панель → Налаштування → Active Directory), щоб не вимагати
 * ручного редагування файлу через SSH.
 *
 * Зміни діють одразу, без перезапуску PHP-FPM/Apache — перевірено на
 * справжньому FPM-воркері (не лише `php -S`): хоча Config::get() читає .env
 * через getenv()/putenv(), а FPM-воркер живе довше за один запит, сам PHP
 * скидає зміни, зроблені через putenv(), по завершенні КОЖНОГО запиту —
 * це вбудована ізоляція оточення між запитами, а не щось, що треба
 * забезпечувати тут. Наступний запит завжди бачить .env таким, яким він є
 * у файлі просто зараз.
 */
class EnvFile
{
    private static function path(): string
    {
        return __DIR__ . '/../../.env';
    }

    /** Поточне значення БЕЗПОСЕРЕДНЬО з файлу (не з getenv(), який у довгоживучому процесі міг застаріти). */
    public static function get(string $key): ?string
    {
        $path = self::path();
        if (!is_readable($path)) {
            return null;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            $isActive = str_starts_with($line, "{$key}=");
            if (!$isActive || !str_contains($line, '=')) {
                continue;
            }
            [, $value] = explode('=', $line, 2);
            return self::unquote(trim($value));
        }
        return null;
    }

    /**
     * Оновлює (чи додає) кілька ключів одразу — усе інше в файлі лишається без змін,
     * включно з порядком рядків і коментарями. Закоментований рядок `#KEY=...` для
     * потрібного ключа розкоментовується й отримує нове значення, а не дублюється
     * новим активним рядком поруч зі старим закоментованим.
     *
     * @param array<string, string> $values
     * @throws \RuntimeException якщо файл недоступний для запису
     */
    public static function set(array $values): void
    {
        $path = self::path();
        if (!is_file($path)) {
            // Немає .env — малоймовірно (install.sh завжди його створює), але на випадок
            // ручного розгортання без install.sh створюємо порожній, а не падаємо з помилкою.
            @touch($path);
        }
        if (!is_writable($path)) {
            $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($path))['name'] ?? '?') : '?';
            throw new \RuntimeException(
                "Файл .env недоступний для запису процесом веб-сервера (зараз належить користувачу «{$owner}»). "
                . "Надайте право запису, наприклад: sudo chmod 660 .env && sudo chown {$owner}:www-data .env "
                . "(замініть www-data на фактичного користувача, під яким працює PHP), або відредагуйте .env вручну."
            );
        }

        // На відміну від get() — тут БЕЗ FILE_SKIP_EMPTY_LINES: порожні рядки між секціями
        // в .env лишаються на місці, файл не "стискається" після кожного збереження.
        $lines = is_readable($path)
            ? file($path, FILE_IGNORE_NEW_LINES)
            : [];
        $remaining = $values; // ключі, яких ще не знайдено серед рядків файлу

        foreach ($lines as $i => $line) {
            $trimmed = ltrim($line);
            $isCommented = str_starts_with($trimmed, '#');
            $bare = $isCommented ? ltrim(substr($trimmed, 1)) : $trimmed;

            foreach ($remaining as $key => $value) {
                if (str_starts_with($bare, "{$key}=")) {
                    $lines[$i] = "{$key}=" . self::quoteIfNeeded($value);
                    unset($remaining[$key]);
                    break;
                }
            }
        }

        // Ключі, яких у файлі не було взагалі (ні активних, ні закоментованих) — додаються в кінець.
        foreach ($remaining as $key => $value) {
            $lines[] = "{$key}=" . self::quoteIfNeeded($value);
        }

        $tmpPath = $path . '.tmp-' . bin2hex(random_bytes(4));
        $written = @file_put_contents($tmpPath, implode("\n", $lines) . "\n");
        if ($written === false) {
            throw new \RuntimeException('Не вдалося записати тимчасовий файл — перевірте місце на диску та права на директорію.');
        }
        @chmod($tmpPath, 0600);
        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException('Не вдалося замінити .env (rename не вдався) — перевірте права на директорію проєкту.');
        }
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            return substr($value, 1, -1);
        }
        return $value;
    }

    /** Лапки лише там, де без них значення прочиталось би неправильно (парсер .env розбиває по ПЕРШОМУ "="). */
    private static function quoteIfNeeded(string $value): string
    {
        if ($value === '' || str_contains($value, '#') || trim($value) !== $value) {
            return '"' . str_replace('"', '', $value) . '"';
        }
        return $value;
    }
}
