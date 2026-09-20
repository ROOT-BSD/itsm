<?php

namespace App\Models;

use App\Core\Database;

class User
{
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.*, r.code AS role_code, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.*, r.code AS role_code, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function all(): array
    {
        return Database::connection()
            ->query('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.full_name')
            ->fetchAll();
    }

    /**
     * Активні користувачі для випадаючих списків вибору виконавця/відповідального/оператора.
     * Фільтрація на рівні SQL (а не array_filter у PHP після вибірки всіх) — раніше
     * цей самий фільтр був продубльований окремим приватним методом у трьох різних
     * контролерах (Project/Task/TicketController).
     */
    public static function allActive(): array
    {
        return Database::connection()
            ->query('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 ORDER BY u.full_name')
            ->fetchAll();
    }

    public static function updatePassword(int $userId, string $newPassword): bool
    {
        $user = self::findById($userId);
        if (!$user || $user['auth_source'] !== 'local') {
            // Пароль можна змінити лише для локальних облікових записів.
            // Для AD-користувачів пароль керується самим Active Directory (Епік 13).
            return false;
        }

        $stmt = Database::connection()->prepare(
            'UPDATE users SET password_hash = :hash WHERE id = :id'
        );
        $stmt->execute([
            'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => $userId,
        ]);

        return true;
    }

    public static function setActive(int $userId, bool $isActive): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET is_active = :active WHERE id = :id');
        $stmt->execute(['active' => $isActive ? 1 : 0, 'id' => $userId]);
    }

    public static function roles(): array
    {
        return Database::connection()->query('SELECT * FROM roles ORDER BY id')->fetchAll();
    }

    public static function create(string $fullName, string $email, string $password, int $roleId): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (full_name, email, password_hash, auth_source, role_id, is_active)
             VALUES (:full_name, :email, :password_hash, "local", :role_id, 1)'
        );
        $stmt->execute([
            'full_name' => $fullName,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role_id' => $roleId,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function update(int $userId, string $fullName, string $email, int $roleId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET full_name = :full_name, email = :email, role_id = :role_id WHERE id = :id'
        );
        $stmt->execute([
            'full_name' => $fullName,
            'email' => $email,
            'role_id' => $roleId,
            'id' => $userId,
        ]);
    }

    public static function emailExists(string $email, ?int $excludeUserId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE email = :email';
        $params = ['email' => $email];
        if ($excludeUserId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeUserId;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Повне видалення користувача. На відміну від проєктів, тут НЕМАЄ каскадного
     * видалення пов'язаних даних — таблиці tasks/comments/time_logs/audit_log
     * посилаються на users без ON DELETE CASCADE (щоб не втрачати історію, хто
     * саме створив задачу чи залишив коментар). Тому якщо користувач має пов'язані
     * записи, MySQL поверне помилку цілісності (FK constraint) — це очікувано:
     * ловимо її і повертаємо контролеру ознаку, що видалення неможливе, замість
     * того щоб мовчки ламати історичні дані.
     */
    public static function delete(int $userId): bool
    {
        try {
            $stmt = Database::connection()->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute(['id' => $userId]);
            return true;
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                // Порушення зовнішнього ключа — у користувача є пов'язані дані
                // (створені проєкти, задачі, коментарі, облік часу тощо).
                return false;
            }
            throw $e;
        }
    }
}
