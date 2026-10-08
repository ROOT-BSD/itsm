<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\View;

class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            header('Location: /');
            exit;
        }
        $error = $_GET['error'] ?? null;
        if ($error === null && ($_GET['sso'] ?? '') === 'failed') {
            // Веб-сервер перенаправляє сюди, якщо браузер не пройшов Kerberos-автентифікацію (ErrorDocument 401).
            $error = 'Автоматичний вхід через Windows не вдався (браузер не передав квиток Kerberos). Увійдіть паролем.';
        }
        View::render('auth/login', [
            'error' => $error,
            'ssoEnabled' => !empty(Config::get('ad.sso_enabled')),
        ]);
    }

    /**
     * Безпарольний вхід. Адреса /sso/login має бути захищена Kerberos-модулем веб-сервера, який і
     * виставляє REMOTE_USER. Беремо лише змінні сервера — ніколи не заголовки запиту (їх підробляє клієнт).
     */
    public function sso(): void
    {
        if (Auth::check()) {
            header('Location: /');
            exit;
        }
        $principal = (string) ($_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? '');

        if (Auth::attemptSso($principal)) {
            header('Location: /');
            exit;
        }
        header('Location: /login?error=' . urlencode(Auth::lastError() ?? 'Вхід через Windows не вдався'));
        exit;
    }

    public function login(): void
    {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            header('Location: /login?error=' . urlencode('Заповніть усі поля'));
            exit;
        }

        if (Auth::attempt($email, $password)) {
            header('Location: /');
            exit;
        }

        header('Location: /login?error=' . urlencode(Auth::lastError() ?? 'Невірний email або пароль'));
        exit;
    }

    public function logout(): void
    {
        Auth::logout();
        header('Location: /login');
        exit;
    }
}
