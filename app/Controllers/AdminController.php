<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Project;
use App\Models\User;

class AdminController
{
    public function __construct()
    {
        Auth::requireLogin();
        if (!Auth::hasRole(['admin'])) {
            http_response_code(403);
            echo 'Доступ до адміністративної панелі дозволено лише ролі "Адміністратор системи".';
            exit;
        }
    }

    public function index(): void
    {
        View::render('admin/index', []);
    }

    // ---------- Користувачі ----------

    public function users(): void
    {
        View::render('admin/users', [
            'users' => User::all(),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function updatePassword(array $params): void
    {
        $userId = (int) $params['id'];
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($newPassword) < 8) {
            $this->redirectUsers('error', 'Пароль має містити щонайменше 8 символів');
            return;
        }

        if ($newPassword !== $confirmPassword) {
            $this->redirectUsers('error', 'Паролі не збігаються');
            return;
        }

        $ok = User::updatePassword($userId, $newPassword);

        if (!$ok) {
            $this->redirectUsers('error', 'Не вдалося змінити пароль (можливо, обліковий запис автентифікується через AD)');
            return;
        }

        \App\Models\Audit::log('user', $userId, 'password_changed_by_admin', Auth::id());
        $this->redirectUsers('success', 'Пароль користувача оновлено');
    }

    public function toggleActive(array $params): void
    {
        $userId = (int) $params['id'];
        $active = ($_POST['active'] ?? '1') === '1';

        if ($userId === Auth::id()) {
            $this->redirectUsers('error', 'Не можна деактивувати власний обліковий запис');
            return;
        }

        User::setActive($userId, $active);
        \App\Models\Audit::log('user', $userId, $active ? 'activated_by_admin' : 'deactivated_by_admin', Auth::id());
        $this->redirectUsers('success', $active ? 'Обліковий запис активовано' : 'Обліковий запис деактивовано');
    }

    private function redirectUsers(string $key, string $message): void
    {
        header('Location: /admin/users?' . $key . '=' . urlencode($message));
        exit;
    }

    // ---------- Проєкти ----------

    public function projects(): void
    {
        View::render('admin/projects', [
            'projects' => Project::all(),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function deleteProject(array $params): void
    {
        $projectId = (int) $params['id'];
        $confirmName = trim($_POST['confirm_name'] ?? '');

        $project = Project::find($projectId);
        if (!$project) {
            header('Location: /admin/projects?error=' . urlencode('Проєкт не знайдено'));
            exit;
        }

        // Захист від випадкового видалення: адміністратор має ввести точну назву проєкту.
        if ($confirmName !== $project['name']) {
            header('Location: /admin/projects?error=' . urlencode('Назву проєкту введено невірно — видалення скасовано'));
            exit;
        }

        Project::delete($projectId, Auth::id());
        header('Location: /admin/projects?success=' . urlencode('Проєкт "' . $project['name'] . '" та всі повʼязані дані видалено'));
        exit;
    }
}
