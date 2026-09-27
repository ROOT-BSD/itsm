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

        View::render('profile/index', [
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

    private function redirect(string $key, string $message): void
    {
        header('Location: /profile?' . $key . '=' . urlencode($message));
        exit;
    }
}
