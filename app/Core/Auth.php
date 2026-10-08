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

        if (!$user) {
            self::$lastError = 'Невірний email або пароль';
            return false;
        }

        if (!$user['is_active']) {
            self::$lastError = 'Невірний email або пароль';
            return false;
        }

        // Блокування прив'язане до КОНКРЕТНОГО користувача (не IP і не сесії) —
        // саме так, як просили: одна людина, яка забула пароль, не впливає на інших.
        // Застосовується однаково для local і ad — захист від підбору пароля на AD-акаунт
        // через цей застосунок має сенс незалежно від того, чи є в самого AD власний lockout.
        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $minutesLeft = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
            self::$lastError = "Обліковий запис тимчасово заблоковано через забагато невдалих спроб входу. Спробуйте ще раз через {$minutesLeft} хв.";
            return false;
        }

        $credentialsValid = $user['auth_source'] === 'ad'
            ? AdAuth::authenticate($user['ad_username'] ?? '', $password) !== null
            : password_verify($password, $user['password_hash'] ?? '');

        if (!$credentialsValid) {
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
        self::openSession($user);

        return true;
    }

    /** Записує користувача в сесію (спільне для входу за паролем і SSO). */
    private static function openSession(array $user): void
    {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role_code'];
        $_SESSION['user_name'] = $user['full_name'];

        // Захист від session fixation: новий ID сесії після зміни рівня
        // привілеїв (анонім -> залогінений користувач). CSRF-токен свідомо
        // НЕ скидаємо тут — форма логіну вже надіслала токен старої сесії,
        // і його заміна одразу після цього не додає захисту, лише ускладнила б код.
        session_regenerate_id(true);
    }

    /**
     * Безпарольний вхід: веб-сервер уже автентифікував користувача за Kerberos і передав принципал
     * (REMOTE_USER). Приймає лише AD-користувачів — локальні акаунти (зокрема адміністратор) через SSO
     * не входять ніколи. Деталізовану причину відмови повертає lastError().
     *
     * @param string $principal «login@REALM», «REALM\login» або просто «login»
     */
    public static function attemptSso(string $principal): bool
    {
        self::$lastError = null;
        $ad = Config::get('ad', []);

        if (empty($ad['sso_enabled'])) {
            self::$lastError = 'Вхід через Windows (SSO) не ввімкнено.';
            return false;
        }

        $parsed = self::parsePrincipal($principal);
        if ($parsed === null) {
            self::$lastError = 'Сервер не передав ідентифікацію Windows-користувача. Перевірте налаштування SSO на веб-сервері або увійдіть паролем.';
            return false;
        }
        [$login, $realm] = $parsed;

        $expected = (string) ($ad['sso_realm'] ?? '');
        if ($expected !== '' && ($realm === null || strtoupper($realm) !== $expected)) {
            self::$lastError = 'Обліковий запис не з очікуваного домену. Увійдіть паролем.';
            return false;
        }

        $user = User::findAdByUsername($login);
        // Спільне повідомлення для «немає такого / неактивний / заблокований» — як і для пароля,
        // щоб форма не підказувала, які AD-логіни існують.
        if (!$user || !$user['is_active']
            || (!empty($user['locked_until']) && strtotime($user['locked_until']) > time())) {
            self::$lastError = 'Для вашого облікового запису Windows немає доступу до системи. Зверніться до адміністратора.';
            return false;
        }

        User::resetFailedLogins((int) $user['id']);
        self::openSession($user);
        Audit::log('user', (int) $user['id'], 'ad_sso_login', (int) $user['id'], ['principal' => $login . ($realm !== null ? '@' . strtoupper($realm) : '')]);

        return true;
    }

    /** «login@REALM» / «REALM\login» / «login» -> [login, realm|null]; null, якщо порожньо чи небезпечні символи. */
    public static function parsePrincipal(string $principal): ?array
    {
        $principal = trim($principal);
        if ($principal === '' || strlen($principal) > 256 || preg_match('/[\x00-\x1f\x7f]/', $principal)) {
            return null;
        }
        $realm = null;
        if (str_contains($principal, '@')) {
            $at = strrpos($principal, '@');
            $login = substr($principal, 0, $at);
            $realm = substr($principal, $at + 1);
        } elseif (str_contains($principal, '\\')) {
            $bs = strpos($principal, '\\');
            $realm = substr($principal, 0, $bs);
            $login = substr($principal, $bs + 1);
        } else {
            $login = $principal;
        }
        if ($login === '' || ($realm !== null && $realm === '')) {
            return null;
        }
        return [$login, $realm];
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
