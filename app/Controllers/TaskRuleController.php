<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Models\Audit;
use App\Models\Task;
use App\Models\TaskRule;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TaskRuleService;
use App\Services\TicketRuleService;

/** Автоматичні правила для задач: керує лише адміністратор системи. */
class TaskRuleController
{
    public function __construct()
    {
        Auth::requireLogin();
        if (!Auth::hasRole(['admin'])) {
            http_response_code(403);
            echo 'Доступно лише ролі «Адміністратор системи».';
            exit;
        }
    }

    public function index(): void
    {
        $db = Database::connection();
        $edit = !empty($_GET['edit']) ? TaskRule::find((int) $_GET['edit']) : null;
        View::render('admin/task_rules', [
            'rules' => TaskRule::all(), 'ready' => TaskRule::ready(), 'edit' => $edit,
            'projects' => $db->query('SELECT id, name FROM projects ORDER BY name')->fetchAll(),
            'statuses' => $db->query('SELECT id, name FROM task_statuses ORDER BY sort_order, id')->fetchAll(),
            'users' => User::allActive(),
            'events' => TaskRuleService::EVENTS,
            'error' => $_GET['error'] ?? null, 'success' => $_GET['success'] ?? null,
        ]);
    }

    public function save(): void
    {
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        if ($id !== null && !TaskRule::find($id)) {
            $this->back('error', 'Правило не знайдено.');
        }
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
        $event = array_key_exists($_POST['trigger_event'] ?? '', TaskRuleService::EVENTS) ? $_POST['trigger_event'] : 'created';
        $db = Database::connection();
        $statusIds = array_map('intval', $db->query('SELECT id FROM task_statuses')->fetchAll(\PDO::FETCH_COLUMN));
        $projIds = array_map('intval', $db->query('SELECT id FROM projects')->fetchAll(\PDO::FETCH_COLUMN));
        $userIds = array_map(fn($u) => (int) $u['id'], User::allActive());
        $ws = $event === 'status' && in_array((int) ($_POST['when_status_id'] ?? 0), $statusIds, true) ? (int) $_POST['when_status_id'] : null;
        $proj = (int) ($_POST['project_id'] ?? 0) ?: null;
        if ($proj !== null && !in_array($proj, $projIds, true)) {
            $this->back('error', 'Проєкт не знайдено.', $id);
        }
        $keywords = implode("\n", TicketRuleService::parseKeywords((string) ($_POST['keywords'] ?? '')));
        $matchIn = in_array($_POST['match_in'] ?? '', ['title', 'description', 'both'], true) ? $_POST['match_in'] : 'both';
        $condPrio = isset(Ticket::PRIORITIES[$_POST['cond_priority'] ?? '']) ? $_POST['cond_priority'] : null;
        $condAss = in_array($_POST['cond_assignee'] ?? '', ['assigned', 'unassigned'], true) ? $_POST['cond_assignee'] : 'any';
        $actAss = (int) ($_POST['action_assignee_id'] ?? 0) ?: null;
        if ($actAss !== null && !in_array($actAss, $userIds, true)) {
            $this->back('error', 'Цього користувача не можна призначити.', $id);
        }
        $actPrio = isset(Ticket::PRIORITIES[$_POST['action_priority'] ?? '']) ? $_POST['action_priority'] : null;
        $sysAss = !empty($_POST['notify_system_assignee']);
        $sys = [];
        foreach ((array) ($_POST['notify_system_users'] ?? []) as $u) {
            if (in_array((int) $u, $userIds, true)) {
                $sys[(int) $u] = (int) $u;
            }
        }
        if ($name === '') {
            $this->back('error', 'Вкажіть назву правила.', $id);
        }
        if ($keywords === '' && $proj === null && $condPrio === null && $condAss === 'any' && $ws === null) {
            $this->back('error', 'Вкажіть хоча б одну умову: ключові слова, проєкт, пріоритет, виконавця або статус.', $id);
        }
        if ($actAss === null && $actPrio === null && !$sysAss && !$sys) {
            $this->back('error', 'Оберіть хоча б одну дію: виконавець, пріоритет або сповіщення в системі.', $id);
        }
        $rid = TaskRule::save($id, [
            'name' => $name, 'sort_order' => max(1, min(9999, (int) ($_POST['sort_order'] ?? 100))), 'trigger_event' => $event, 'when_status_id' => $ws,
            'keywords' => $keywords, 'match_in' => $matchIn, 'project_id' => $proj, 'cond_priority' => $condPrio, 'cond_assignee' => $condAss,
            'action_assignee_id' => $actAss, 'action_priority' => $actPrio, 'notify_system_assignee' => $sysAss,
            'notify_system_users' => $sys ? implode(',', $sys) : null, 'is_active' => !empty($_POST['is_active']), 'created_by' => (int) Auth::id(),
        ]);
        Audit::log('task_rule', $rid, $id === null ? 'created' : 'updated', (int) Auth::id(), ['name' => $name]);
        $this->back('success', 'Правило «' . $name . '» збережено.');
    }

    public function toggle(array $params): void
    {
        $r = TaskRule::find((int) $params['id']);
        if (!$r) {
            $this->back('error', 'Правило не знайдено.');
        }
        TaskRule::setActive((int) $r['id'], !(int) $r['is_active']);
        Audit::log('task_rule', (int) $r['id'], (int) $r['is_active'] ? 'disabled' : 'enabled', (int) Auth::id(), ['name' => $r['name']]);
        $this->back('success', 'Правило «' . $r['name'] . '» ' . ((int) $r['is_active'] ? 'вимкнено.' : 'увімкнено.'));
    }

    public function delete(array $params): void
    {
        $r = TaskRule::find((int) $params['id']);
        if (!$r) {
            $this->back('error', 'Правило не знайдено.');
        }
        TaskRule::delete((int) $r['id']);
        Audit::log('task_rule', (int) $r['id'], 'deleted', (int) Auth::id(), ['name' => $r['name']]);
        $this->back('success', 'Правило «' . $r['name'] . '» видалено.');
    }

    private function back(string $key, string $message, ?int $edit = null): never
    {
        header('Location: /admin/task-rules?' . $key . '=' . urlencode($message) . ($key === 'error' && $edit ? '&edit=' . $edit : ''));
        exit;
    }
}
