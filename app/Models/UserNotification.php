<?php

namespace App\Models;

use App\Core\Database;

/**
 * Центр сповіщень (міграція 054): сповіщення «в системі» без пошти. Створюються автоматичними правилами черги
 * (та можуть створюватися будь-яким кодом через notify()). Кожне — рядок user_notifications; read_at = NULL — не прочитане.
 */
class UserNotification
{
    /** Скільки сповіщень зберігаємо на користувача (старіші прочитані видаляються). */
    private const KEEP = 200;

    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                Database::connection()->query('SELECT 1 FROM user_notifications LIMIT 1');
                $ready = true;
            } catch (\Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    /** Додає сповіщення активному користувачу. $url — лише внутрішня адреса (починається з «/»). */
    public static function notify(int $userId, string $title, ?string $body = null, ?string $url = null): void
    {
        if (!self::ready()) {
            return;
        }
        if ($url !== null && !preg_match('#^/[A-Za-z0-9/_\-?=&%.\#]*$#', $url)) {
            $url = null;
        }
        $db = Database::connection();
        $db->prepare('INSERT INTO user_notifications (user_id, title, body, url) SELECT id, :t, :b, :u FROM users WHERE id = :id AND is_active = 1')
           ->execute(['t' => mb_substr($title, 0, 200), 'b' => $body !== null ? mb_substr($body, 0, 500) : null, 'u' => $url, 'id' => $userId]);
        // Обмежуємо розмір: найстаріші прочитані понад KEEP — геть.
        $db->prepare('DELETE FROM user_notifications WHERE user_id = :u AND read_at IS NOT NULL AND id < (SELECT m FROM (SELECT COALESCE(MIN(id), 0) m FROM (SELECT id FROM user_notifications WHERE user_id = :u2 ORDER BY id DESC LIMIT ' . self::KEEP . ') x) y)')
           ->execute(['u' => $userId, 'u2' => $userId]);
    }

    public static function unreadCount(int $userId): int
    {
        if (!self::ready()) {
            return 0;
        }
        try {
            $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id = :u AND read_at IS NULL');
            $stmt->execute(['u' => $userId]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function forUser(int $userId, int $limit = 100): array
    {
        if (!self::ready()) {
            return [];
        }
        $stmt = Database::connection()->prepare('SELECT * FROM user_notifications WHERE user_id = :u ORDER BY id DESC LIMIT ' . (int) $limit);
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id, int $userId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM user_notifications WHERE id = :id AND user_id = :u');
        $stmt->execute(['id' => $id, 'u' => $userId]);
        return $stmt->fetch() ?: null;
    }

    public static function markRead(int $id, int $userId): void
    {
        Database::connection()->prepare('UPDATE user_notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = :id AND user_id = :u')->execute(['id' => $id, 'u' => $userId]);
    }

    public static function markAllRead(int $userId): void
    {
        Database::connection()->prepare('UPDATE user_notifications SET read_at = NOW() WHERE user_id = :u AND read_at IS NULL')->execute(['u' => $userId]);
    }

    public static function deleteRead(int $userId): void
    {
        Database::connection()->prepare('DELETE FROM user_notifications WHERE user_id = :u AND read_at IS NOT NULL')->execute(['u' => $userId]);
    }
}
