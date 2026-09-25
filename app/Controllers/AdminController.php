<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Audit;
use App\Models\Setting;

class AdminController
{
    /** Допустимі значення видимості проєкту (ENUM у БД) — той самий список, що й у ProjectController. */
    private const VISIBILITIES = ['public', 'private', 'restricted'];

    /** Допустимі значення статусу проєкту (ENUM у БД). */
    private const STATUSES = ['active', 'archived', 'closed'];

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

    // ---------- Загальний огляд по всій системі (лише адміністратор) ----------

    /** Загальна канбан-дошка: задачі з усіх проєктів одразу, згруповані по статусах. */
    public function board(): void
    {
        $tasks = Task::allWithProject();
        $statuses = Task::statuses();

        $tasksByStatus = [];
        foreach ($statuses as $status) {
            $tasksByStatus[$status['id']] = [];
        }
        foreach ($tasks as $task) {
            $tasksByStatus[$task['status_id']][] = $task;
        }

        View::render('admin/board', [
            'statuses' => $statuses,
            'tasksByStatus' => $tasksByStatus,
        ]);
    }

    /** Загальна діаграма Ганта: задачі з усіх проєктів на одній часовій шкалі. */
    public function gantt(): void
    {
        View::render('admin/gantt', [
            'tasks' => Task::allWithProject(),
            'relations' => Task::allRelations(),
        ]);
    }

    /** Загальний облік часу по всій системі — усі проєкти одразу. */
    public function timeReport(): void
    {
        $timeLogs = Task::timeLogsAll();
        $totalHours = array_sum(array_column($timeLogs, 'hours'));

        View::render('admin/time', [
            'timeLogs' => $timeLogs,
            'hoursByUser' => Task::hoursByUserAll(),
            'hoursByProject' => Task::hoursByProjectAll(),
            'hoursByDay' => Task::hoursByPeriodAll('day'),
            'hoursByWeek' => Task::hoursByPeriodAll('week'),
            'hoursByMonth' => Task::hoursByPeriodAll('month'),
            'hoursByDayUser' => Task::hoursByPeriodAndUserAll('day'),
            'hoursByWeekUser' => Task::hoursByPeriodAndUserAll('week'),
            'hoursByMonthUser' => Task::hoursByPeriodAndUserAll('month'),
            'hoursByDayProject' => Task::hoursByPeriodAndProjectAll('day'),
            'hoursByWeekProject' => Task::hoursByPeriodAndProjectAll('week'),
            'hoursByMonthProject' => Task::hoursByPeriodAndProjectAll('month'),
            'totalHours' => $totalHours,
        ]);
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

    public function showCreateUserForm(): void
    {
        View::render('admin/users_create', [
            'roles' => User::roles(),
            'error' => $_GET['error'] ?? null,
        ]);
    }

    public function storeUser(): void
    {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $roleId = (int) ($_POST['role_id'] ?? 0);

        if ($fullName === '' || $email === '' || $roleId === 0) {
            $this->redirectCreateUser('Заповніть усі обов\'язкові поля');
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->redirectCreateUser('Некоректний email');
            return;
        }
        if (strlen($password) < 8) {
            $this->redirectCreateUser('Пароль має містити щонайменше 8 символів');
            return;
        }
        if (User::emailExists($email)) {
            $this->redirectCreateUser('Користувач з таким email уже існує');
            return;
        }

        $newId = User::create($fullName, $email, $password, $roleId);
        Audit::log('user', $newId, 'created_by_admin', Auth::id());
        $this->redirectUsers('success', 'Користувача "' . $fullName . '" створено');
    }

    private function redirectCreateUser(string $message): void
    {
        header('Location: /admin/users/create?error=' . urlencode($message));
        exit;
    }

    public function showEditUserForm(array $params): void
    {
        $user = User::findById((int) $params['id']);
        if (!$user) {
            header('Location: /admin/users?error=' . urlencode('Користувача не знайдено'));
            exit;
        }

        View::render('admin/users_edit', [
            'targetUser' => $user,
            'roles' => User::roles(),
            'error' => $_GET['error'] ?? null,
        ]);
    }

    public function updateUser(array $params): void
    {
        $userId = (int) $params['id'];
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $roleId = (int) ($_POST['role_id'] ?? 0);

        if ($fullName === '' || $email === '' || $roleId === 0) {
            header("Location: /admin/users/{$userId}/edit?error=" . urlencode('Заповніть усі обов\'язкові поля'));
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header("Location: /admin/users/{$userId}/edit?error=" . urlencode('Некоректний email'));
            exit;
        }
        if (User::emailExists($email, $userId)) {
            header("Location: /admin/users/{$userId}/edit?error=" . urlencode('Цей email уже використовує інший користувач'));
            exit;
        }
        if ($userId === Auth::id() && $roleId !== (int) User::findById($userId)['role_id']) {
            header("Location: /admin/users/{$userId}/edit?error=" . urlencode('Не можна змінити власну роль'));
            exit;
        }

        User::update($userId, $fullName, $email, $roleId);
        Audit::log('user', $userId, 'updated_by_admin', Auth::id());
        $this->redirectUsers('success', 'Дані користувача оновлено');
    }

    public function deleteUser(array $params): void
    {
        $userId = (int) $params['id'];

        if ($userId === Auth::id()) {
            $this->redirectUsers('error', 'Не можна видалити власний обліковий запис');
            return;
        }

        $user = User::findById($userId);
        if (!$user) {
            $this->redirectUsers('error', 'Користувача не знайдено');
            return;
        }

        $ok = User::delete($userId);

        if (!$ok) {
            $this->redirectUsers(
                'error',
                'Неможливо видалити "' . $user['full_name'] . '" — з ним пов\'язані дані '
                . '(створені проєкти, задачі, коментарі або облік часу). '
                . 'Використайте деактивацію замість видалення, щоб зберегти історію.'
            );
            return;
        }

        Audit::log('user', $userId, 'deleted_by_admin', Auth::id(), ['email' => $user['email']]);
        $this->redirectUsers('success', 'Користувача "' . $user['full_name'] . '" видалено');
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

        Audit::log('user', $userId, 'password_changed_by_admin', Auth::id());
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
        Audit::log('user', $userId, $active ? 'activated_by_admin' : 'deactivated_by_admin', Auth::id());
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

    public function updateProjectVisibility(array $params): void
    {
        $projectId = (int) $params['id'];
        $visibility = $_POST['visibility'] ?? '';

        $project = Project::find($projectId);
        if (!$project) {
            header('Location: /admin/projects?error=' . urlencode('Проєкт не знайдено'));
            exit;
        }
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            header('Location: /admin/projects?error=' . urlencode('Некоректне значення видимості'));
            exit;
        }

        Project::updateVisibility($projectId, $visibility, Auth::id());
        header('Location: /admin/projects?success=' . urlencode('Видимість проєкту "' . $project['name'] . '" оновлено'));
        exit;
    }

    /** Статус проєкту (активний/архівний/закритий) — попередньо був недоступний через UI, змінювався лише напряму в БД. */
    public function updateProjectStatus(array $params): void
    {
        $projectId = (int) $params['id'];
        $status = $_POST['status'] ?? '';

        $project = Project::find($projectId);
        if (!$project) {
            header('Location: /admin/projects?error=' . urlencode('Проєкт не знайдено'));
            exit;
        }
        if (!in_array($status, self::STATUSES, true)) {
            header('Location: /admin/projects?error=' . urlencode('Некоректне значення статусу'));
            exit;
        }

        Project::updateStatus($projectId, $status, Auth::id());
        header('Location: /admin/projects?success=' . urlencode('Статус проєкту "' . $project['name'] . '" оновлено'));
        exit;
    }

    // ---------- Черги тікетів ----------

    /** Налаштування блокування облікового запису після невдалих спроб входу. */
    public function securitySettings(): void
    {
        View::render('admin/security_settings', [
            'maxAttempts' => (int) Setting::get('max_login_attempts', '5'),
            'lockoutMinutes' => (int) Setting::get('lockout_minutes', '15'),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function updateSecuritySettings(): void
    {
        $maxAttempts = (int) ($_POST['max_login_attempts'] ?? 0);
        $lockoutMinutes = (int) ($_POST['lockout_minutes'] ?? 0);

        if ($maxAttempts < 1 || $maxAttempts > 20) {
            header('Location: /admin/security?error=' . urlencode('Кількість спроб має бути від 1 до 20'));
            exit;
        }
        if ($lockoutMinutes < 1 || $lockoutMinutes > 1440) {
            header('Location: /admin/security?error=' . urlencode('Тривалість блокування має бути від 1 до 1440 хвилин (24 год)'));
            exit;
        }

        Setting::set('max_login_attempts', (string) $maxAttempts);
        Setting::set('lockout_minutes', (string) $lockoutMinutes);
        Audit::log('app_settings', 0, 'login_lockout_settings_changed', Auth::id(), [
            'max_login_attempts' => $maxAttempts,
            'lockout_minutes' => $lockoutMinutes,
        ]);

        header('Location: /admin/security?success=' . urlencode('Налаштування збережено'));
        exit;
    }

    /** Ручне зняття блокування конкретного користувача (без очікування таймауту). */
    public function unlockUser(array $params): void
    {
        $userId = (int) $params['id'];
        $user = User::findById($userId);
        if (!$user) {
            header('Location: /admin/users?error=' . urlencode('Користувача не знайдено'));
            exit;
        }

        User::resetFailedLogins($userId);
        Audit::log('user', $userId, 'unlocked_by_admin', Auth::id());

        header('Location: /admin/users?success=' . urlencode('Блокування знято для "' . $user['full_name'] . '"'));
        exit;
    }

    public function queues(): void
    {
        View::render('admin/queues', [
            'queues' => Ticket::queuesWithTicketCount(),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function showCreateQueueForm(): void
    {
        View::render('admin/queues_create', [
            'error' => $_GET['error'] ?? null,
        ]);
    }

    public function storeQueue(): void
    {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '') {
            header('Location: /admin/queues/create?error=' . urlencode('Назва черги обов\'язкова'));
            exit;
        }
        if (Ticket::queueNameExists($name)) {
            header('Location: /admin/queues/create?error=' . urlencode('Черга з такою назвою вже існує'));
            exit;
        }

        Ticket::createQueue($name, $description ?: null, Auth::id());
        header('Location: /admin/queues?success=' . urlencode('Чергу "' . $name . '" створено'));
        exit;
    }
}
