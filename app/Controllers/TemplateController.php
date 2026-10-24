<?php

namespace App\Controllers;

use App\Core\Access;
use App\Core\Auth;
use App\Core\View;
use App\Models\Project;
use App\Models\Task;
use App\Models\Template;
use App\Services\TemplateService;

/** Шаблони проєктів і задач: перегляд — усім, керування — тим, хто може створювати проєкти. */
class TemplateController
{
    private const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    public function index(): void
    {
        Auth::requireLogin();
        $this->requireReady();
        View::render('templates/index', [
            'templates' => Template::all(),
            'canManage' => Access::canManageProjects(Auth::role()),
        ]);
    }

    public function showNew(): void
    {
        Auth::requireLogin();
        $this->requireManager();
        $this->requireReady();
        $kind = ($_GET['kind'] ?? '') === 'task' ? 'task' : 'project';
        View::render('templates/form', [
            'tpl' => ['id' => null, 'kind' => $kind, 'name' => '', 'description' => '', 'project_description' => ''],
            'items' => [],
            'types' => Task::types(),
        ]);
    }

    public function show(array $params): void
    {
        Auth::requireLogin();
        $this->requireReady();
        $tpl = $this->findOrFail((int) $params['id']);
        View::render('templates/form', [
            'tpl' => $tpl,
            'items' => Template::items((int) $tpl['id']),
            'types' => Task::types(),
            'readOnly' => !Access::canManageProjects(Auth::role()),
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        $this->requireManager();
        $this->requireReady();
        $kind = ($_POST['kind'] ?? '') === 'task' ? 'task' : 'project';
        [$name, $desc, $projDesc, $items, $error] = $this->readForm($kind);
        if ($error !== null) {
            $this->flash('/templates/new?kind=' . $kind, 'error', $error);
        }
        $id = Template::create($kind, $name, $desc, $projDesc, $items, Auth::id());
        $this->flash('/templates/' . $id, 'success', 'Шаблон збережено');
    }

    public function update(array $params): void
    {
        Auth::requireLogin();
        $this->requireManager();
        $this->requireReady();
        $tpl = $this->findOrFail((int) $params['id']);
        [$name, $desc, $projDesc, $items, $error] = $this->readForm($tpl['kind']);
        if ($error !== null) {
            $this->flash('/templates/' . $tpl['id'], 'error', $error);
        }
        Template::update((int) $tpl['id'], $name, $desc, $projDesc, $items, Auth::id());
        $this->flash('/templates/' . $tpl['id'], 'success', 'Шаблон збережено');
    }

    public function delete(array $params): void
    {
        Auth::requireLogin();
        $this->requireManager();
        $this->requireReady();
        $tpl = $this->findOrFail((int) $params['id']);
        Template::delete((int) $tpl['id'], $tpl['name'], Auth::id());
        $this->flash('/templates', 'success', 'Шаблон видалено');
    }

    /** Застосування шаблону до наявного проєкту: створює задачі від дати старту. */
    public function apply(array $params): void
    {
        Auth::requireLogin();
        $this->requireReady();
        $project = Project::find((int) $params['id']);
        if (!$project || !Project::isVisibleTo($project, Auth::id(), Auth::hasRole(['admin']))) {
            http_response_code(404);
            echo 'Проєкт не знайдено.';
            exit;
        }
        $back = '/projects/' . (int) $project['id'];
        $tpl = Template::find((int) ($_POST['template_id'] ?? 0));
        if (!$tpl) {
            $this->flash($back, 'error', 'Оберіть шаблон');
        }
        $base = $this->baseDate($_POST['start_date'] ?? '');
        if ($base === false) {
            $this->flash($back, 'error', 'Некоректна дата старту');
        }
        // Назву можна змінити лише для шаблону задачі (одна задача); для шаблону проєкту поле ігнорується.
        $title = null;
        if ($tpl['kind'] === 'task') {
            $title = trim((string) ($_POST['task_title'] ?? ''));
            if (mb_strlen($title) > 255) {
                $this->flash($back, 'error', 'Назва задачі — до 255 символів');
            }
        }
        try {
            $r = TemplateService::apply((int) $tpl['id'], (int) $project['id'], $base, Auth::id(), $title, self::readRoleUsers());
        } catch (\Throwable $e) {
            $this->flash($back, 'error', 'Не вдалося застосувати шаблон: ' . $e->getMessage());
        }
        $msg = 'За шаблоном «' . $tpl['name'] . '» створено задач: ' . $r['tasks'] . ($r['subtasks'] ? ' (і підзадач: ' . $r['subtasks'] . ')' : '');
        if ($tpl['kind'] === 'task' && $r['first_task_id']) {
            $this->flash('/tasks/' . $r['first_task_id'], 'success', $msg);
        }
        $this->flash($back, 'success', $msg);
    }

    /** «Зберегти як шаблон»: з однієї задачі. */
    public function saveFromTask(array $params): void
    {
        Auth::requireLogin();
        $this->requireManager();
        $this->requireReady();
        $task = Task::find((int) $params['id']);
        if (!$task) {
            http_response_code(404);
            echo 'Задачу не знайдено.';
            exit;
        }
        $project = Project::find((int) $task['project_id']);
        if (!$project || !Project::isVisibleTo($project, Auth::id(), Auth::hasRole(['admin']))) {
            http_response_code(403);
            echo 'Доступ обмежено.';
            exit;
        }
        $id = Template::create('task', mb_substr($task['title'], 0, 150), null, null, [TemplateService::itemFromTask($task)], Auth::id());
        $this->flash('/templates/' . $id, 'success', 'Шаблон задачі створено — перевірте й за потреби відредагуйте');
    }

    /** «Зберегти як шаблон»: з усіх основних задач проєкту (підзадачі потрапляють у шаблон як рядки підзадач). */
    public function saveFromProject(array $params): void
    {
        Auth::requireLogin();
        $this->requireManager();
        $this->requireReady();
        $project = Project::find((int) $params['id']);
        if (!$project || !Project::isVisibleTo($project, Auth::id(), Auth::hasRole(['admin']))) {
            http_response_code(404);
            echo 'Проєкт не знайдено.';
            exit;
        }
        $tasks = array_values(array_filter(Task::forProject((int) $project['id']), fn($t) => empty($t['parent_task_id'])));
        if (!$tasks) {
            $this->flash('/projects/' . $project['id'], 'error', 'У проєкті немає задач, з яких можна зробити шаблон');
        }
        usort($tasks, fn($a, $b) => (int) $a['id'] <=> (int) $b['id']); // від старих до нових
        $tasks = array_slice($tasks, 0, Template::MAX_ITEMS);
        $starts = array_filter(array_map(fn($t) => $t['start_date'] ?: ($t['due_date'] ?: null), $tasks));
        $base = $starts ? min($starts) : null;
        $items = array_map(fn($t) => TemplateService::itemFromTask($t, $base), $tasks);
        $id = Template::create('project', mb_substr($project['name'], 0, 150), null, $project['description'] ?: null, $items, Auth::id());
        $this->flash('/templates/' . $id, 'success', 'Шаблон проєкту створено: дати задач перетворено на зміщення в днях від найранішої дати');
    }

    // ------------------------------------------------------------------

    /** @return array{0: string, 1: ?string, 2: ?string, 3: array, 4: ?string} */
    private function readForm(string $kind): array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            return ['', null, null, [], 'Вкажіть назву шаблону (до 150 символів)'];
        }
        $desc = trim((string) ($_POST['description'] ?? ''));
        $projDesc = trim((string) ($_POST['project_description'] ?? ''));
        $typeIds = array_map(fn($t) => (int) $t['id'], Task::types());
        $items = [];
        foreach ((array) ($_POST['items'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue; // порожній рядок форми
            }
            $prio = in_array($row['priority'] ?? '', self::PRIORITIES, true) ? $row['priority'] : 'normal';
            $type = (int) ($row['type_id'] ?? 0);
            $off = function ($v) {
                $v = trim((string) $v);
                return preg_match('/^-?\d{1,4}$/', $v) ? (int) $v : null;
            };
            $items[] = [
                'title' => mb_substr($title, 0, 255),
                'body' => ($b = trim((string) ($row['body'] ?? ''))) !== '' ? $b : null,
                'type_id' => in_array($type, $typeIds, true) ? $type : null,
                'priority' => $prio,
                'assignee_role' => ($r = mb_substr(trim(str_replace(['[', ']'], '', (string) ($row['assignee_role'] ?? ''))), 0, 60)) !== '' ? $r : null,
                'start_offset' => $off($row['start_offset'] ?? ''),
                'due_offset' => $off($row['due_offset'] ?? ''),
                'checklist' => ($c = TemplateService::lines($row['checklist'] ?? '')) ? implode("\n", $c) : null,
                'subtasks' => ($s = TemplateService::lines($row['subtasks'] ?? '')) ? implode("\n", $s) : null,
            ];
            if ($kind === 'task') {
                break;
            }
        }
        if (!$items) {
            return [$name, null, null, [], $kind === 'task' ? 'Вкажіть назву задачі' : 'Додайте хоча б одну задачу (заповніть назву)'];
        }
        if (count($items) > Template::MAX_ITEMS) {
            return [$name, null, null, [], 'У шаблоні може бути не більше ' . Template::MAX_ITEMS . ' задач'];
        }
        return [$name, $desc !== '' ? mb_substr($desc, 0, 500) : null, $kind === 'project' && $projDesc !== '' ? $projDesc : null, $items, null];
    }

    /** Відповідність «роль → користувач» з форми застосування шаблону (лише активні користувачі). @return array<string,int> */
    public static function readRoleUsers(): array
    {
        $active = array_map(fn($u) => (int) $u['id'], \App\Models\User::allActive());
        $out = [];
        foreach ((array) ($_POST['role_user'] ?? []) as $role => $uid) {
            if ((int) $uid > 0 && in_array((int) $uid, $active, true)) {
                $out[(string) $role] = (int) $uid;
            }
        }
        return $out;
    }

    /** Дата старту з форми: '' → null (терміни не рахуються), некоректна → false. */
    public static function baseDate(string $raw): string|false|null
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        return $d && $d->format('Y-m-d') === $raw ? $raw : false;
    }

    private function findOrFail(int $id): array
    {
        $tpl = Template::find($id);
        if (!$tpl) {
            http_response_code(404);
            echo 'Шаблон не знайдено.';
            exit;
        }
        return $tpl;
    }

    private function requireManager(): void
    {
        if (!Access::canManageProjects(Auth::role())) {
            http_response_code(403);
            echo 'Керувати шаблонами можуть адміністратор системи, адміністратор підрозділу та керівник ІТ-підрозділу.';
            exit;
        }
    }

    private function requireReady(): void
    {
        if (!Template::ready()) {
            http_response_code(503);
            echo 'Шаблони потребують міграції 039 (і 038) — зверніться до адміністратора: update.sh.';
            exit;
        }
    }

    private function flash(string $url, string $kind, string $message): never
    {
        header('Location: ' . $url . (str_contains($url, '?') ? '&' : '?') . $kind . '=' . urlencode($message));
        exit;
    }
}
