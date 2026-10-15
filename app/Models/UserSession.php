<?php

namespace App\Models;

use App\Core\Database;
use App\Core\RateLimiter;

/**
 * Облік веб-сесій: «де я ввійшов» і примусове завершення сесії.
 *
 * Сама сесія PHP живе у файлах; тут — лише реєстр. Ідентифікатор сесії зберігається як SHA-256 (витік бази
 * не дає викрасти сесію). Кожен веб-запит звіряється з реєстром не частіше за CHECK_INTERVAL секунд: якщо сесію
 * завершено (користувачем, адміністратором, зміною пароля чи деактивацією) — вхід анулюється. Без міграції 040
 * усе це тихо вимкнене (ready() = false).
 */
class UserSession
{
    /** Не частіше цього інтервалу (с) звіряємо сесію з БД та оновлюємо «востаннє». */
    public const CHECK_INTERVAL = 20;

    /** Скільки днів зберігати завершені/застарілі сесії. */
    private const KEEP_DAYS = 7;

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                $n = Database::connection()->query(
                    "SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('user_sessions', 'login_log')"
                )->fetchColumn();
                self::$ready = (int) $n === 2;
            } catch (\Throwable) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function hash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    /** Вікно «активності» (с): сесія без запитів довше за цей час уже загинула на боці PHP. */
    public static function idleWindow(): int
    {
        return max(300, (int) ini_get('session.gc_maxlifetime'));
    }

    /** Реєструє щойно відкриту сесію (після session_regenerate_id). */
    public static function open(int $userId, string $sessionId): void
    {
        if (!self::ready()) {
            return;
        }
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO user_sessions (user_id, session_hash, ip, user_agent)
                 VALUES (:u, :h, :ip, :ua)
                 ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), ended_at = NULL, ended_reason = NULL, ended_by = NULL, last_seen_at = NOW()'
            );
            $stmt->execute(['u' => $userId, 'h' => self::hash($sessionId), 'ip' => RateLimiter::clientIp(), 'ua' => self::userAgent()]);
            if (random_int(1, 50) === 1) {
                self::purge();
            }
        } catch (\Throwable $e) {
            error_log('[itsm] user_sessions open: ' . $e->getMessage());
        }
    }

    /**
     * Перевіряє поточну сесію. true — працювати далі; false — сесію завершено, вхід треба анулювати.
     * Невідому сесію (відкриту до оновлення) тихо реєструє.
     */
    public static function validate(int $userId, string $sessionId): bool
    {
        if (!self::ready()) {
            return true;
        }
        try {
            $db = Database::connection();
            $h = self::hash($sessionId);
            $stmt = $db->prepare('SELECT user_id, ended_at FROM user_sessions WHERE session_hash = :h');
            $stmt->execute(['h' => $h]);
            $row = $stmt->fetch();
            if (!$row) {
                self::open($userId, $sessionId);
                return true;
            }
            if ($row['ended_at'] !== null || (int) $row['user_id'] !== $userId) {
                return false;
            }
            $db->prepare('UPDATE user_sessions SET last_seen_at = NOW() WHERE session_hash = :h')->execute(['h' => $h]);
        } catch (\Throwable $e) {
            error_log('[itsm] user_sessions validate: ' . $e->getMessage());
        }
        return true;
    }

    /** Позначає сесію завершеною (вихід користувача — reason «logout»). */
    public static function end(string $sessionId, string $reason, ?int $by = null): void
    {
        if (!self::ready()) {
            return;
        }
        try {
            Database::connection()->prepare(
                'UPDATE user_sessions SET ended_at = NOW(), ended_reason = :r, ended_by = :by WHERE session_hash = :h AND ended_at IS NULL'
            )->execute(['r' => $reason, 'by' => $by, 'h' => self::hash($sessionId)]);
        } catch (\Throwable $e) {
            error_log('[itsm] user_sessions end: ' . $e->getMessage());
        }
    }

    /** Активні сесії користувача: не завершені й з недавньою активністю. */
    public static function activeFor(int $userId): array
    {
        return self::active('s.user_id = :uid', ['uid' => $userId]);
    }

    /** Усі активні сесії системи (для адміністратора). */
    public static function allActive(): array
    {
        return self::active('1 = 1', []);
    }

    private static function active(string $where, array $params): array
    {
        if (!self::ready()) {
            return [];
        }
        $params['win'] = self::idleWindow();
        $stmt = Database::connection()->prepare(
            "SELECT s.*, u.full_name, u.email
             FROM user_sessions s JOIN users u ON u.id = s.user_id
             WHERE s.ended_at IS NULL AND s.last_seen_at >= (NOW() - INTERVAL :win SECOND) AND {$where}
             ORDER BY s.last_seen_at DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        if (!self::ready()) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT * FROM user_sessions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Завершує одну сесію за id запису. Повертає true, якщо щось змінилось. */
    public static function revoke(int $id, string $reason, int $by): bool
    {
        if (!self::ready()) {
            return false;
        }
        $stmt = Database::connection()->prepare(
            'UPDATE user_sessions SET ended_at = NOW(), ended_reason = :r, ended_by = :by WHERE id = :id AND ended_at IS NULL'
        );
        $stmt->execute(['r' => $reason, 'by' => $by, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /** Завершує всі сесії користувача, крім однієї (поточної). Повертає кількість завершених. */
    public static function revokeAllFor(int $userId, string $reason, ?int $by, ?string $exceptSessionId = null): int
    {
        if (!self::ready()) {
            return 0;
        }
        $sql = 'UPDATE user_sessions SET ended_at = NOW(), ended_reason = :r, ended_by = :by WHERE user_id = :u AND ended_at IS NULL';
        $params = ['r' => $reason, 'by' => $by, 'u' => $userId];
        if ($exceptSessionId !== null) {
            $sql .= ' AND session_hash <> :h';
            $params['h'] = self::hash($exceptSessionId);
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** Видаляє старі записи: завершені або неактивні понад KEEP_DAYS діб. */
    public static function purge(): void
    {
        Database::connection()->exec(
            'DELETE FROM user_sessions WHERE COALESCE(ended_at, last_seen_at) < (NOW() - INTERVAL ' . self::KEEP_DAYS . ' DAY)'
        );
    }

    public static function userAgent(): ?string
    {
        $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return $ua === '' ? null : mb_substr($ua, 0, 255);
    }

    /** «Chrome · Windows» з рядка User-Agent — коротко й без претензій на точність. */
    public static function describe(?string $ua): string
    {
        if ($ua === null || $ua === '') {
            return 'невідомий пристрій';
        }
        $browser = 'Браузер';
        foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari', 'curl/' => 'curl'] as $needle => $name) {
            if (str_contains($ua, $needle)) {
                $browser = $name;
                break;
            }
        }
        $os = 'ОС невідома';
        foreach (['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iOS', 'Mac OS X' => 'macOS', 'Linux' => 'Linux'] as $needle => $name) {
            if (str_contains($ua, $needle)) {
                $os = $name;
                break;
            }
        }
        return $browser . ' · ' . $os;
    }
}
