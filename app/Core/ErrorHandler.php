<?php

namespace App\Core;

/**
 * Глобальний обробник необроблених помилок.
 *
 * Без нього будь-яка несподівана помилка PHP (збій SQL, відсутня таблиця, помилка в шаблоні) давала голу сторінку
 * «HTTP 500» без жодних пояснень: PHP на робочому сервері не виводить помилок у відповідь, а лог веб-сервера
 * шукати довго. Тепер:
 *   - кожна необроблена помилка ЗАПИСУЄТЬСЯ в storage/logs/app-РРРР-ММ-ДД.log (один JSON-рядок) із кодом інциденту;
 *   - користувач бачить зрозумілу сторінку з цим кодом (не з технічними подробицями);
 *   - АДМІНІСТРАТОР на цій самій сторінці бачить і причину (клас, повідомлення, файл:рядок) — без пошуку по логах.
 *
 * Що НЕ логується: тіло запиту (паролі!), рядок запиту (?error=… бувають текстами повідомлень), аргументи функцій у стеку.
 * Обробник свідомо не залежить від БД, шаблонів і конфігурації — інакше помилка в них заглушила б і сам обробник.
 * Помилки, які застосунок обробляє сам (403, 404, «Доступ обмежено»), сюди не потрапляють.
 */
final class ErrorHandler
{
    private const KEEP_DAYS = 30;
    private const MAX_TRACE_FRAMES = 15;

    private static bool $handling = false;

    public static function register(): void
    {
        set_exception_handler([self::class, 'handleException']);
        // Фатальні помилки (вичерпання пам'яті тощо) винятками не стають — ловимо їх по завершенню скрипта.
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function logDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/logs';
    }

    public static function handleException(\Throwable $e): void
    {
        if (self::$handling) {
            return; // помилка всередині самого обробника — не зациклюватись
        }
        self::$handling = true;

        $id = strtoupper(bin2hex(random_bytes(4)));
        $logged = self::log($id, $e);
        self::render($id, $e, $logged);
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if ($error === null || !in_array($error['type'], $fatal, true) || self::$handling) {
            return;
        }
        self::$handling = true;

        $e = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
        $id = strtoupper(bin2hex(random_bytes(4)));
        $logged = self::log($id, $e);
        self::render($id, $e, $logged);
    }

    /** Записує помилку в журнал. @return bool true — записано у файл (інакше — у системний лог PHP) */
    private static function log(string $id, \Throwable $e): bool
    {
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, self::MAX_TRACE_FRAMES) as $frame) {
            $frames[] = ($frame['file'] ?? '[внутрішній виклик]') . ':' . ($frame['line'] ?? '?') . ' '
                . (isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') : '') . ($frame['function'] ?? '');
        }

        $userId = null;
        try {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $userId = Auth::id();
            }
        } catch (\Throwable) {
        }

        $line = json_encode([
            'time' => date('Y-m-d H:i:s'),
            'id' => $id,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'path' => parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '',
            'user_id' => $userId,
            'class' => get_class($e),
            'message' => mb_substr($e->getMessage(), 0, 2000),
            'where' => self::shortPath($e->getFile()) . ':' . $e->getLine(),
            'trace' => array_map([self::class, 'shortPath'], $frames),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";

        $dir = self::logDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            if (@file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX) !== false) {
                self::cleanOld($dir);
                return true;
            }
        }
        // Каталог недоступний для запису — хоча б у системний лог PHP, щоб помилка не зникла безслідно.
        error_log('[itsm] ' . trim($line));
        return false;
    }

    private static function cleanOld(string $dir): void
    {
        $limit = time() - self::KEEP_DAYS * 86400;
        foreach (glob($dir . '/app-*.log') ?: [] as $file) {
            if (@filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }

    private static function shortPath(string $text): string
    {
        return str_replace(dirname(__DIR__, 2) . '/', '', $text);
    }

    private static function viewerIsAdmin(): bool
    {
        try {
            return session_status() === PHP_SESSION_ACTIVE && Auth::hasRole(['admin']);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function render(string $id, \Throwable $e, bool $logged): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store');
        }
        // Усе, що вже встигли вивести до помилки, відкидаємо — інакше половина сторінки змішалась би з повідомленням.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $details = '';
        if (self::viewerIsAdmin()) {
            $where = self::shortPath($e->getFile()) . ':' . $e->getLine();
            $details = '<div class="box"><strong>Для адміністратора:</strong>'
                . '<pre>' . $h(get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 1500)) . "\n\n" . $h($where) . '</pre>'
                . '<p>' . ($logged
                    ? 'Повний запис зі стеком — у <code>storage/logs/app-' . $h(date('Y-m-d')) . '.log</code>, шукайте код <code>' . $h($id) . '</code>.'
                    : 'Каталог <code>storage/logs</code> недоступний для запису веб-серверу, тож запис потрапив у системний лог PHP '
                      . '(помилки PHP-FPM/Apache). Виправлення: <code>sudo chown -R www-data:www-data storage/logs</code> '
                      . '(або запустіть <code>sudo bash update.sh</code>).')
                . '</p></div>';
        }

        echo '<!DOCTYPE html><html lang="uk"><head><meta charset="utf-8"><title>Внутрішня помилка сервера</title>'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><style>'
            . 'body{font-family:system-ui,sans-serif;background:#f8f9fa;color:#212529;margin:0;padding:3rem 1rem}'
            . '.card{max-width:46rem;margin:0 auto;background:#fff;border:1px solid #dee2e6;border-radius:.5rem;padding:2rem}'
            . 'h1{font-size:1.5rem;margin:0 0 1rem}code{background:#f1f3f5;padding:.1rem .35rem;border-radius:4px}'
            . '.box{margin-top:1.5rem;padding:1rem;background:#fff3cd;border:1px solid #ffe69c;border-radius:.4rem}'
            . 'pre{white-space:pre-wrap;word-break:break-word;margin:.5rem 0}a{color:#0d6efd}'
            . '</style></head><body><div class="card"><h1>Сталася внутрішня помилка</h1>'
            . '<p>Сторінку не вдалося відкрити через несподівану помилку на сервері. Якщо помилка сталася під час збереження, спершу перевірте на відповідній сторінці, чи збереглися зміни, і лише потім повторюйте дію.</p>'
            . '<p>Повідомте адміністратора системи, назвавши код помилки: <strong><code>' . $h($id) . '</code></strong></p>'
            . '<p><a href="/">← На головну</a></p>' . $details . '</div></body></html>';
    }
}
