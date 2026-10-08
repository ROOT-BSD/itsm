<?php

namespace App\Models;

use App\Core\Database;

/**
 * Персональні токени доступу до REST API.
 *
 * Безпека:
 *  - токен — 192 біти випадковості (itsm_ + 48 hex); у БД лише SHA-256 від нього, тож витік БД не дає працюючих токенів;
 *    швидкий хеш тут доречний: перебір 2^192 неможливий, а повільний (bcrypt) лише гальмував би кожен запит API;
 *  - повний токен показується ОДИН раз, при створенні; далі видно лише префікс (для впізнавання у списку);
 *  - токен діє від імені власника: якщо його деактивовано, токен перестає працювати одразу, а роль береться «на льоту»
 *    з поточних даних (зміна ролі діє негайно, без перевипуску токена);
 *  - причини відмови (невірний, відкликаний, прострочений, власника деактивовано) для клієнта НЕ розрізняються — інакше
 *    можна було б перевіряти, чи існує токен.
 */
class ApiToken
{
    public const SCOPES = ['read' => 'Лише читання', 'write' => 'Читання й запис'];
    public const MAX_PER_USER = 20;

    public static function available(): bool
    {
        return Database::tableExists('api_tokens') && Database::tableExists('api_rate_limits');
    }

    /** Чи увімкнено API адміністратором (за замовчуванням — ні). */
    public static function enabled(): bool
    {
        return Setting::get('api_enabled', '0') === '1';
    }

    public static function ratePerMinute(): int
    {
        return max(1, min(100000, (int) Setting::get('api_rate_limit', '120')));
    }

    /**
     * Створює токен. Повертає ПОВНИЙ токен (його більше не відновити) та id запису.
     *
     * @return array{token: string, id: int}
     */
    public static function create(int $userId, string $name, string $scope, ?int $expiresInDays, int $actingUserId): array
    {
        $scope = array_key_exists($scope, self::SCOPES) ? $scope : 'read';
        $token = 'itsm_' . bin2hex(random_bytes(24));
        $expires = $expiresInDays ? date('Y-m-d H:i:s', time() + $expiresInDays * 86400) : null;

        $stmt = Database::connection()->prepare(
            'INSERT INTO api_tokens (user_id, name, token_hash, token_prefix, scope, expires_at)
             VALUES (:user, :name, :hash, :prefix, :scope, :expires)'
        );
        $stmt->execute([
            'user' => $userId,
            'name' => mb_substr($name, 0, 100),
            'hash' => self::hash($token),
            'prefix' => substr($token, 0, 12),
            'scope' => $scope,
            'expires' => $expires,
        ]);
        $id = (int) Database::connection()->lastInsertId();

        Audit::log('user', $userId, 'api_token_created', $actingUserId, ['name' => mb_substr($name, 0, 100), 'scope' => $scope]);
        return ['token' => $token, 'id' => $id];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Знаходить діючий токен за повним значенням і повертає його разом з даними власника; null — токена немає, він
     * відкликаний чи прострочений або власника деактивовано.
     *
     * @return array<string, mixed>|null
     */
    public static function findActive(string $plain): ?array
    {
        if (!preg_match('/^itsm_[0-9a-f]{48}$/', $plain)) {
            return null; // дешева відсічка сміття без звернення до БД
        }
        $stmt = Database::connection()->prepare(
            'SELECT t.*, u.full_name, u.email, u.is_active, r.code AS role_code
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             JOIN roles r ON r.id = u.role_id
             WHERE t.token_hash = :hash
               AND t.revoked_at IS NULL
               AND (t.expires_at IS NULL OR t.expires_at > NOW())
               AND u.is_active = 1'
        );
        $stmt->execute(['hash' => self::hash($plain)]);
        return $stmt->fetch() ?: null;
    }

    /** Фіксує час і адресу останнього використання — не частіше разу на хвилину, щоб не писати в БД на кожен запит. */
    public static function touch(array $token, string $ip): void
    {
        if (!empty($token['last_used_at']) && strtotime($token['last_used_at']) > time() - 60) {
            return;
        }
        Database::connection()->prepare('UPDATE api_tokens SET last_used_at = NOW(), last_used_ip = :ip WHERE id = :id')
            ->execute(['ip' => mb_substr($ip, 0, 45), 'id' => $token['id']]);
    }

    /**
     * Враховує запит у лічильнику поточної хвилини. Один атомарний INSERT … ON DUPLICATE KEY UPDATE, тож паралельні
     * запити не «губляться».
     *
     * @return array{allowed: bool, limit: int, remaining: int, reset: int} reset — через скільки секунд почнеться нове вікно
     */
    public static function hit(int $tokenId): array
    {
        $limit = self::ratePerMinute();
        $window = intdiv(time(), 60) * 60;
        $pdo = Database::connection();

        $pdo->prepare(
            'INSERT INTO api_rate_limits (token_id, window_start, hits) VALUES (:id, :w, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1'
        )->execute(['id' => $tokenId, 'w' => $window]);

        $stmt = $pdo->prepare('SELECT hits FROM api_rate_limits WHERE token_id = :id AND window_start = :w');
        $stmt->execute(['id' => $tokenId, 'w' => $window]);
        $hits = (int) $stmt->fetchColumn();

        // Старі вікна нікому не потрібні — прибираємо зрідка (приблизно кожен сотий запит), не навантажуючи кожен.
        if (random_int(1, 100) === 1) {
            $pdo->prepare('DELETE FROM api_rate_limits WHERE window_start < :old')->execute(['old' => $window - 600]);
        }

        return ['allowed' => $hits <= $limit, 'limit' => $limit, 'remaining' => max(0, $limit - $hits), 'reset' => $window + 60 - time()];
    }

    /** @return array<int, array<string, mixed>> токени користувача (без хешів), найновіші першими */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, name, token_prefix, scope, expires_at, last_used_at, last_used_ip, revoked_at, created_at
             FROM api_tokens WHERE user_id = :user ORDER BY id DESC'
        );
        $stmt->execute(['user' => $userId]);
        return $stmt->fetchAll();
    }

    public static function countActiveForUser(int $userId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM api_tokens WHERE user_id = :user AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())'
        );
        $stmt->execute(['user' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** Усі токени системи з даними власників — для адміністратора. @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT t.id, t.name, t.token_prefix, t.scope, t.expires_at, t.last_used_at, t.last_used_ip, t.revoked_at, t.created_at,
                    u.id AS user_id, u.full_name, u.email, u.is_active
             FROM api_tokens t JOIN users u ON u.id = t.user_id
             ORDER BY t.revoked_at IS NOT NULL, t.id DESC'
        )->fetchAll();
    }

    /**
     * Відкликає токен. $onlyUserId — власник: звичайний користувач може відкликати лише СВОЇ токени (адміністратор
     * передає null і відкликає будь-який).
     *
     * @return bool true — відкликано зараз (вже відкликаний чи чужий → false)
     */
    public static function revoke(int $tokenId, ?int $onlyUserId, int $actingUserId): bool
    {
        $sql = 'UPDATE api_tokens SET revoked_at = NOW() WHERE id = :id AND revoked_at IS NULL' . ($onlyUserId !== null ? ' AND user_id = :user' : '');
        $params = ['id' => $tokenId] + ($onlyUserId !== null ? ['user' => $onlyUserId] : []);
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $owner = Database::connection()->prepare('SELECT user_id, name FROM api_tokens WHERE id = :id');
        $owner->execute(['id' => $tokenId]);
        $row = $owner->fetch();
        Audit::log('user', (int) $row['user_id'], 'api_token_revoked', $actingUserId, ['name' => $row['name']]);
        return true;
    }
}
