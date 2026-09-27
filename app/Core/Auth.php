<?php

namespace App\Core;

use App\Models\Audit;
use App\Models\Setting;
use App\Models\User;

class Auth
{
    private static ?string $lastError = null;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function attempt(string $email, string $password): bool
    {
        self::$lastError = null;
        $user = User::findByEmail($email);

        if (!$user || $user['auth_source'] !== 'local') {
            // Обліковий запис з AD-автентифікацією не може заходити локальним паролем.
            // Логіка bind-запиту до AD реалізується окремим класом App\Core\AdAuth (Епік 13, R2).
            // Навмисно те саме повідомлення, що й для невірного пароля нижче —
            // щоб не підказувати стороннім, які email узагалі існують у системі.
            self::$lastError = 'Невірний email або пароль';
            return false;
        }

        if (!$user['is_active']) {
            self::$lastError = 'Невірний email або пароль';
            return false;
        }

        // Блокування прив'язане до КОНКРЕТНОГО користувача (не IP і не сесії) —
        // саме так, як просили: одна людина, яка забула пароль, не впливає на інших.
        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $minutesLeft = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
            self::$lastError = "Обліковий запис тимчасово заблоковано через забагато невдалих спроб входу. Спробуйте ще раз через {$minutesLeft} хв.";
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            $maxAttempts = (int) Setting::get('max_login_attempts', '5');
            $lockoutMinutes = (int) Setting::get('lockout_minutes', '15');

            $attempts = User::registerFailedLogin($user['id']);

            if ($attempts >= $maxAttempts) {
                $lockedUntil = date('Y-m-d H:i:s', time() + $lockoutMinutes * 60);
                User::lockUntil($user['id'], $lockedUntil);
                Audit::log('user', $user['id'], 'account_locked_after_failed_logins', null, ['attempts' => $attempts]);
                self::$lastError = "Забагато невдалих спроб входу. Обліковий запис заблоковано на {$lockoutMinutes} хв.";
            } else {
                $remaining = $maxAttempts - $attempts;
                self::$lastError = "Невірний email або пароль. Залишилось спроб до тимчасового блокування: {$remaining}.";
            }
            return false;
        }

        // Успішний вхід — лічильник невдалих спроб скидається.
        User::resetFailedLogins($user['id']);

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

    /** Деталізоване повідомлення про причину останньої невдалої спроби Auth::attempt() — для сторінки логіну. */
    public static function lastError(): ?string
    {
        return self::$lastError;
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
