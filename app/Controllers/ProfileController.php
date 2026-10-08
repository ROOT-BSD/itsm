<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Audit;
use App\Models\User;

class ProfileController
{
    public function index(): void
    {
        Auth::requireLogin();

        $newToken = $_SESSION['new_api_token'] ?? null;
        unset($_SESSION['new_api_token']); // токен показується один раз
        $apiReady = \App\Models\ApiToken::available();
        View::render('profile/index', [
            'apiEnabled' => $apiReady && \App\Models\ApiToken::enabled(),
            'apiTokens' => $apiReady ? \App\Models\ApiToken::forUser((int) Auth::id()) : [],
            'newToken' => $newToken,
            'appUrl' => \App\Models\Setting::appUrl(),
            'user' => User::findById(Auth::id()),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    /** Самостійна зміна власного пароля — на відміну від адмін-панелі, тут спершу перевіряється поточний пароль. */
    public function updatePassword(): void
    {
        Auth::requireLogin();
        $userId = Auth::id();
        $user = User::findById($userId);

        if (!$user || $user['auth_source'] !== 'local') {
            $this->redirect('error', 'Пароль для цього облікового запису керується через Active Directory, а не тут');
            return;
        }

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!password_verify($currentPassword, $user['password_hash'])) {
            $this->redirect('error', 'Поточний пароль введено неправильно');
            return;
        }

        if (strlen($newPassword) < 8) {
            $this->redirect('error', 'Новий пароль має містити щонайменше 8 символів');
            return;
        }

        if ($newPassword !== $confirmPassword) {
            $this->redirect('error', 'Новий пароль і підтвердження не збігаються');
            return;
        }

        User::updatePassword($userId, $newPassword);
        Audit::log('user', $userId, 'password_changed_self', $userId);

        $this->redirect('success', 'Пароль успішно змінено');
    }

    /** Користувач створює токен собі. API має бути ввімкнене адміністратором. */
    public function createApiToken(): void
    {
        Auth::requireLogin();
        if (!\App\Models\ApiToken::available() || !\App\Models\ApiToken::enabled()) {
            $this->redirect('error', 'REST API вимкнено адміністратором системи.');
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $days = (int) ($_POST['expires_days'] ?? 0);
        if ($name === '' || mb_strlen($name) > 100) {
            $this->redirect('error', 'Вкажіть назву токена (до 100 символів), щоб потім його впізнати.');
        }
        if (\App\Models\ApiToken::countActiveForUser((int) Auth::id()) >= \App\Models\ApiToken::MAX_PER_USER) {
            $this->redirect('error', 'У вас уже ' . \App\Models\ApiToken::MAX_PER_USER . ' діючих токенів — відкличте непотрібні.');
        }
        $created = \App\Models\ApiToken::create((int) Auth::id(), $name, (string) ($_POST['scope'] ?? 'read'), $days > 0 ? min($days, 3650) : null, (int) Auth::id());
        $_SESSION['new_api_token'] = ['token' => $created['token'], 'name' => $name];
        $this->redirect('success', 'Токен створено — скопіюйте його зараз: більше його показати неможливо.');
    }

    /** Користувач відкликає лише СВОЇ токени. */
    public function revokeApiToken(array $params): void
    {
        Auth::requireLogin();
        $ok = \App\Models\ApiToken::available() && \App\Models\ApiToken::revoke((int) $params['id'], (int) Auth::id(), (int) Auth::id());
        $this->redirect($ok ? 'success' : 'error', $ok ? 'Токен відкликано.' : 'Токен не знайдено або вже відкликаний.');
    }

    private function redirect(string $key, string $message): void
    {
        header('Location: /profile?' . $key . '=' . urlencode($message));
        exit;
    }
}
