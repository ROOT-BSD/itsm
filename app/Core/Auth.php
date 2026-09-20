<?php

namespace App\Core;

use App\Models\User;

class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function attempt(string $email, string $password): bool
    {
        $user = User::findByEmail($email);

        if (!$user || $user['auth_source'] !== 'local') {
            // Обліковий запис з AD-автентифікацією не може заходити локальним паролем.
            // Логіка bind-запиту до AD реалізується окремим класом App\Core\AdAuth (Епік 13, R2).
            return false;
        }

        if (!$user['is_active']) {
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role_code'];
        $_SESSION['user_name'] = $user['full_name'];

        // Захист від session fixation: новий ID сесії після зміни рівня
        // привілеїв (анонім -> залогінений користувач). CSRF-токен свідомо
        // НЕ скидаємо тут — форма логіну вже надіслала токен старої сесії,
        // і його заміна одразу після цього не додає захисту, лише ускладнила б код.
        session_regenerate_id(true);

        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_destroy();
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function role(): ?string
    {
        return $_SESSION['user_role'] ?? null;
    }

    public static function name(): ?string
    {
        return $_SESSION['user_name'] ?? null;
    }

    /** Перевірка ролі. Приклад: Auth::hasRole(['admin', 'it_manager']) */
    public static function hasRole(array $allowedRoles): bool
    {
        return in_array(self::role(), $allowedRoles, true);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }
}
