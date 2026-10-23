<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Audit;
use App\Models\Project;
use App\Models\ReportSchedule;
use App\Models\Task;
use App\Models\User;
use App\Services\MailerService;
use App\Services\ReportScheduleService;

/** «Звіти → Звіти за розкладом»: кожен користувач керує своїми розкладами, адміністратор системи бачить усі. */
class ReportScheduleController
{
    public function __construct()
    {
        Auth::requireLogin();
    }

    private function isAdmin(): bool
    {
        return Auth::hasRole(['admin']);
    }

    public function index(): void
    {
        $uid = (int) Auth::id();
        View::render('reports/schedules', [
            'ready' => ReportSchedule::ready(),
            'schedules' => ReportSchedule::ready() ? ReportSchedule::listFor($uid, $this->isAdmin()) : [],
            'isAdmin' => $this->isAdmin(),
            'projects' => Project::allVisibleTo($uid, $this->isAdmin()),
            'users' => User::allActive(),
            'categories' => Task::distinctCategoriesVisibleTo($uid, $this->isAdmin()),
            'mailOn' => MailerService::isConfigured(),
            'lastRun' => \App\Models\Setting::get('reports_last_run'),
            'success' => $_GET['success'] ?? null,
            'error' => $_GET['error'] ?? null,
        ]);
    }

    /** Перевіряє поля форми (спільно для створення й редагування); при помилці — редірект. $back — куди повертати з повідомленням. */
    private function parseForm(string $back, ?array $existing = null): array
    {
        $uid = (int) Auth::id();
        $name = trim((string) ($_POST['name'] ?? ''));
        $freq = ($_POST['frequency'] ?? '') === 'monthly' ? 'monthly' : 'weekly';
        $weekday = (int) ($_POST['weekday'] ?? 1);
        $monthDay = (int) ($_POST['month_day'] ?? 1);
        $hour = (int) ($_POST['send_hour'] ?? 8);
        $format = array_key_exists($_POST['format'] ?? '', ReportSchedule::FORMATS) ? $_POST['format'] : 'pdf';
        if ($name === '' || mb_strlen($name) > 150) {
            $this->redirect('error', 'Вкажіть назву розкладу (до 150 символів).', $back);
        }
        if ($weekday < 1 || $weekday > 7 || $monthDay < 1 || $monthDay > 28 || $hour < 0 || $hour > 23) {
            $this->redirect('error', 'Некоректний день або година.', $back);
        }
        $projectId = !empty($_POST['project_id']) ? (int) $_POST['project_id'] : null;
        // Проєкт, уже заданий у розкладі, лишається дозволеним (адмін міг редагувати чужий розклад).
        if ($projectId !== null && !($existing && (int) $existing['project_id'] === $projectId)) {
            $p = Project::find($projectId);
            if (!$p || !Project::isVisibleTo($p, $uid, $this->isAdmin())) {
                $this->redirect('error', 'Проєкт не знайдено або немає доступу.', $back);
            }
        }
        $logUserId = !empty($_POST['user_id']) ? (int) $_POST['user_id'] : null;
        if ($logUserId !== null && !User::findById($logUserId)) {
            $this->redirect('error', 'Користувача не знайдено.', $back);
        }
        // Додаткові одержувачі: звичайний користувач може вказати лише адреси користувачів системи (щоб не виводити дані назовні);
        // адреси, які вже були в розкладі, залишаються (їх міг додати адміністратор).
        $old = [];
        if ($existing) {
            foreach (preg_split('/[\s,;]+/', (string) ($existing['extra_recipients'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $o) {
                $old[strtolower($o)] = true;
            }
        }
        $extra = [];
        foreach (preg_split('/[\s,;]+/', (string) ($_POST['extra_recipients'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $e) {
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $this->redirect('error', 'Некоректна адреса: ' . mb_substr($e, 0, 80), $back);
            }
            if (!$this->isAdmin() && !isset($old[strtolower($e)]) && !$this->isActiveUserEmail($e)) {
                $this->redirect('error', 'Адреса ' . $e . ' не належить жодному активному користувачу системи. Зовнішні адреси може додавати лише адміністратор системи.', $back);
            }
            $extra[strtolower($e)] = true;
        }
        $category = trim((string) ($_POST['category'] ?? ''));
        return [
            'name' => $name, 'frequency' => $freq, 'weekday' => $weekday, 'month_day' => $monthDay, 'send_hour' => $hour,
            'format' => $format, 'project_id' => $projectId, 'log_user_id' => $logUserId,
            'category' => $category !== '' ? mb_substr($category, 0, 100) : null, 'extra_recipients' => $extra ? implode(', ', array_keys($extra)) : null,
        ];
    }

    public function create(): void
    {
        $uid = (int) Auth::id();
        $d = $this->parseForm('/reports/schedules');
        $id = ReportSchedule::create($d + ['user_id' => $uid]);
        Audit::log('report_schedule', $id, 'created', $uid, ['name' => $d['name'], 'frequency' => $d['frequency'], 'format' => $d['format']]);
        $this->redirect('success', 'Розклад «' . $d['name'] . '» створено.');
    }

    public function edit(array $params): void
    {
        $uid = (int) Auth::id();
        $s = $this->mine((int) ($params['id'] ?? 0));
        View::render('reports/schedule_edit', [
            'sch' => $s,
            'isAdmin' => $this->isAdmin(),
            'projects' => Project::allVisibleTo($uid, $this->isAdmin()),
            'users' => User::allActive(),
            'categories' => Task::distinctCategoriesVisibleTo($uid, $this->isAdmin()),
            'error' => $_GET['error'] ?? null,
        ]);
    }

    public function update(array $params): void
    {
        $s = $this->mine((int) ($params['id'] ?? 0));
        $d = $this->parseForm('/reports/schedules/' . (int) $s['id'] . '/edit', $s);
        // Змінено розклад відправки — не наздоганяємо «пропущений» період заднім числом, відлік іде від зараз (як при створенні).
        $timingChanged = $d['frequency'] !== $s['frequency'] || $d['send_hour'] !== (int) $s['send_hour']
            || ($d['frequency'] === 'weekly' ? $d['weekday'] !== (int) $s['weekday'] : $d['month_day'] !== (int) $s['month_day']);
        ReportSchedule::update((int) $s['id'], $d, $timingChanged);
        Audit::log('report_schedule', (int) $s['id'], 'updated', (int) Auth::id(), ['name' => $d['name'], 'frequency' => $d['frequency'], 'format' => $d['format']]);
        $this->redirect('success', 'Розклад «' . $d['name'] . '» оновлено.');
    }

    public function toggle(array $params): void
    {
        $s = $this->mine((int) ($params['id'] ?? 0));
        $on = !(int) $s['is_active'];
        ReportSchedule::setActive((int) $s['id'], $on);
        Audit::log('report_schedule', (int) $s['id'], $on ? 'enabled' : 'disabled', (int) Auth::id(), ['name' => $s['name']]);
        $this->redirect('success', 'Розклад «' . $s['name'] . '» ' . ($on ? 'увімкнено' : 'вимкнено') . '.');
    }

    public function delete(array $params): void
    {
        $s = $this->mine((int) ($params['id'] ?? 0));
        ReportSchedule::delete((int) $s['id']);
        Audit::log('report_schedule', (int) $s['id'], 'deleted', (int) Auth::id(), ['name' => $s['name']]);
        $this->redirect('success', 'Розклад «' . $s['name'] . '» видалено.');
    }

    /** «Надіслати зараз»: звіт за попередній період негайно, тим самим одержувачам. */
    public function run(array $params): void
    {
        $s = $this->mine((int) ($params['id'] ?? 0));
        @set_time_limit(120);
        $r = ReportScheduleService::run($s);
        Audit::log('report_schedule', (int) $s['id'], 'sent_now', (int) Auth::id(), ['ok' => $r['ok'] ? 'так' : 'ні']);
        $this->redirect($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Надіслано: ' . $r['message'] : 'Не надіслано: ' . $r['message']);
    }

    private function mine(int $id): array
    {
        $s = ReportSchedule::ready() ? ReportSchedule::find($id) : null;
        if (!$s || ((int) $s['user_id'] !== (int) Auth::id() && !$this->isAdmin())) {
            http_response_code(404);
            echo 'Розклад не знайдено.';
            exit;
        }
        return $s;
    }

    private function isActiveUserEmail(string $email): bool
    {
        $stmt = \App\Core\Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE email = :e AND is_active = 1');
        $stmt->execute(['e' => $email]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function redirect(string $key, string $message, string $to = '/reports/schedules'): never
    {
        header('Location: ' . $to . '?' . $key . '=' . urlencode($message));
        exit;
    }
}
