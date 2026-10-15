<?php

namespace App\Models;

use App\Core\Database;
use App\Core\RateLimiter;

/** Журнал спроб входу (успішних і невдалих). Без міграції 040 мовчки нічого не робить. */
class LoginLog
{
    /** Скільки днів зберігати записи журналу. */
    public const KEEP_DAYS = 365;

    public const REASONS = [
        'bad_password' => 'Невірний пароль',
        'unknown_user' => 'Немає такого користувача',
        'inactive' => 'Обліковий запис вимкнено',
        'locked' => 'Обліковий запис заблоковано',
        'sso_denied' => 'SSO: немає доступу',
    ];

    public const METHODS = ['local' => 'Пароль', 'ad' => 'Пароль AD', 'sso' => 'SSO'];

    public static function record(?int $userId, string $login, string $method, bool $success, ?string $reason = null): void
    {
        if (!UserSession::ready()) {
            return;
        }
        try {
            Database::connection()->prepare(
                'INSERT INTO login_log (user_id, login, method, success, reason, ip, user_agent)
                 VALUES (:u, :l, :m, :s, :r, :ip, :ua)'
            )->execute([
                'u' => $userId,
                'l' => mb_substr($login, 0, 190),
                'm' => $method,
                's' => $success ? 1 : 0,
                'r' => $reason,
                'ip' => RateLimiter::clientIp(),
                'ua' => UserSession::userAgent(),
            ]);
            if (random_int(1, 100) === 1) {
                Database::connection()->exec('DELETE FROM login_log WHERE created_at < (NOW() - INTERVAL ' . self::KEEP_DAYS . ' DAY)');
            }
        } catch (\Throwable $e) {
            error_log('[itsm] login_log: ' . $e->getMessage());
        }
    }

    /** Останні спроби входу в обліковий запис користувача. */
    public static function recentFor(int $userId, int $limit = 30): array
    {
        if (!UserSession::ready()) {
            return [];
        }
        $stmt = Database::connection()->prepare('SELECT * FROM login_log WHERE user_id = :u ORDER BY id DESC LIMIT ' . (int) $limit);
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll();
    }

    /** @param array{q?: string, result?: string, date_from?: string, date_to?: string} $f */
    private static function where(array $f): array
    {
        $w = ['1 = 1'];
        $p = [];
        if (($f['q'] ?? '') !== '') {
            $w[] = '(l.login LIKE :q OR u.full_name LIKE :q2 OR l.ip LIKE :q3)';
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $p += ['q' => $like, 'q2' => $like, 'q3' => $like];
        }
        if (($f['result'] ?? '') === 'ok') {
            $w[] = 'l.success = 1';
        } elseif (($f['result'] ?? '') === 'fail') {
            $w[] = 'l.success = 0';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['date_from'] ?? '')) {
            $w[] = 'l.created_at >= :df';
            $p['df'] = $f['date_from'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['date_to'] ?? '')) {
            $w[] = 'l.created_at <= :dt';
            $p['dt'] = $f['date_to'] . ' 23:59:59';
        }
        return [implode(' AND ', $w), $p];
    }

    public static function count(array $f): int
    {
        if (!UserSession::ready()) {
            return 0;
        }
        [$w, $p] = self::where($f);
        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM login_log l LEFT JOIN users u ON u.id = l.user_id WHERE {$w}");
        $stmt->execute($p);
        return (int) $stmt->fetchColumn();
    }

    public static function filtered(array $f, int $limit, int $offset): array
    {
        if (!UserSession::ready()) {
            return [];
        }
        [$w, $p] = self::where($f);
        $stmt = Database::connection()->prepare(
            "SELECT l.*, u.full_name FROM login_log l LEFT JOIN users u ON u.id = l.user_id
             WHERE {$w} ORDER BY l.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($p);
        return $stmt->fetchAll();
    }
}
