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
}
