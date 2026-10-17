<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskController
{
    /** Допустимі значення пріоритету (ENUM у БД) — валідуємо тут, щоб замість
     *  незрозумілої помилки БД користувач бачив зрозуміле повідомлення. */
    private const PRIORITIES = \App\Models\Task::PRIORITIES;

    /** Глобальний список відкритих задач з видимих користувачу проєктів (посилання з дашборду). */
    public function indexOpen(): void
    {
        Auth::requireLogin();
        View::render('tasks/index', [
            'tasks' => Task::allOpenVisibleTo(Auth::id(), Auth::hasRole(['admin'])),
        ]);
    }

    public function showCreateForm(array $params): void
    {
        Auth::requireLogin();
        $projectId = (int) $params['project_id'];
        $this->requireProjectAccess($projectId);

        View::render('tasks/create', [
            'projectId' => $projectId,
            'types' => Task::types(),
            'users' => User::allActive(),
            'milestones' => Milestone::forProject($projectId),
        ]);
    }

    public function store(array $params): void
    {
        Auth::requireLogin();
        $projectId = (int) $params['project_id'];
        $this->requireProjectAccess($projectId);

        $title = trim($_POST['title'] ?? '');
        $typeId = (int) ($_POST['type_id'] ?? 0);
        $priority = $_POST['priority'] ?? 'normal';

        if ($title === '') {
            $this->redirectCreateError($projectId, 'Назва задачі обов\'язкова');
            return;
        }
        if (!$this->typeExists($typeId)) {
            $this->redirectCreateError($projectId, 'Оберіть коректний тип задачі');
            return;
        }
        if (!in_array($priority, self::PRIORITIES, true)) {
            $this->redirectCreateError($projectId, 'Некоректний пріоритет');
            return;
        }

        $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        if ($startDate !== null && !$this->isValidDate($startDate)) {
            $this->redirectCreateError($projectId, 'Некоректна дата початку');
            return;
        }
        if ($dueDate !== null && !$this->isValidDate($dueDate)) {
            $this->redirectCreateError($projectId, 'Некоректний термін виконання');
            return;
        }
        if ($startDate !== null && $dueDate !== null && $startDate > $dueDate) {
            $this->redirectCreateError($projectId, 'Дата початку не може бути пізніше терміну виконання');
            return;
        }

        $milestoneId = !empty($_POST['milestone_id']) ? (int) $_POST['milestone_id'] : null;
        if ($milestoneId !== null) {
            $milestone = Milestone::find($milestoneId);
            if (!$milestone || (int) $milestone['project_id'] !== $projectId) {
                $this->redirectCreateError($projectId, 'Етап має належати цьому ж проєкту');
                return;
            }
        }

        // Статус "new" (id визначаємо за кодом, щоб не залежати від порядку вставки)
        $statusId = $this->statusIdByCode('new');

        $id = Task::create([
            'project_id' => $projectId,
            'type_id' => $typeId,
            'status_id' => $statusId,
            'title' => $title,
            'description' => trim($_POST['description'] ?? ''),
            'priority' => $priority,
            'author_id' => Auth::id(),
            'assignee_id' => !empty($_POST['assignee_id']) ? (int) $_POST['assignee_id'] : null,
            'start_date' => $startDate,
            'due_date' => $dueDate,
            'milestone_id' => $milestoneId,
        ]);

        header("Location: /tasks/{$id}");
        exit;
    }

    private function redirectCreateError(int $projectId, string $message): void
    {
        header("Location: /projects/{$projectId}/tasks/create?error=" . urlencode($message));
        exit;
    }

    public function show(array $params): void
    {
        Auth::requireLogin();
        $task = Task::find((int) $params['id']);

        if (!$task) {
            http_response_code(404);
            echo 'Задачу не знайдено.';
            return;
        }
        $this->requireProjectAccess((int) $task['project_id']);

        View::render('tasks/show', [
            'task' => $task,
            'comments' => Task::comments($task['id']),
            'statuses' => Task::statuses(),
            'users' => User::allActive(),
            'milestones' => Milestone::forProject((int) $task['project_id']),
            'timeLogs' => Task::timeLogsForTask($task['id']),
            'projects' => Project::allVisibleTo(Auth::id(), Auth::hasRole(['admin'])),
            'attachments' => \App\Models\Attachment::forOwner('task', (int) $task['id']),
            'checklistReady' => \App\Models\TaskChecklist::ready(),
            'checklist' => \App\Models\TaskChecklist::ready() ? \App\Models\TaskChecklist::forTask((int) $task['id']) : [],
            'subtasks' => \App\Models\TaskChecklist::ready() ? \App\Models\TaskChecklist::subtasks((int) $task['id']) : [],
            'parentTask' => \App\Models\TaskChecklist::ready() && !empty($task['parent_task_id']) ? Task::find((int) $task['parent_task_id']) : null,
        ]);
    }

    public function updateStatus(array $params): void
    {
        Auth::requireLogin();
        $task = $this->findTaskOrFail((int) $params['id']);

        $statusId = (int) ($_POST['status_id'] ?? 0);
        if (!$this->statusExists($statusId)) {
            header('Location: /tasks/' . $params['id'] . '?error=' . urlencode('Некоректний статус'));
            exit;
        }

        if (($problem = Task::statusChangeProblem((int) $params['id'], $statusId)) !== null) {
            header('Location: /tasks/' . $params['id'] . '?error=' . urlencode($problem));
            exit;
        }

        Task::updateStatus((int) $params['id'], $statusId, Auth::id());
        header('Location: /tasks/' . $params['id']);
        exit;
    }

    public function updateAssignee(array $params): void
    {
        Auth::requireLogin();
        $task = $this->findTaskOrFail((int) $params['id']);

        $assigneeId = !empty($_POST['assignee_id']) ? (int) $_POST['assignee_id'] : null;
        Task::updateAssignee((int) $params['id'], $assigneeId, Auth::id());
        header('Location: /tasks/' . $params['id']);
        exit;
    }

    /** Прив'язка задачі до етапу дорожньої карти (або зняття прив'язки). */
    public function updateMilestone(array $params): void
    {
        Auth::requireLogin();
        $task = $this->findTaskOrFail((int) $params['id']);

        $milestoneId = !empty($_POST['milestone_id']) ? (int) $_POST['milestone_id'] : null;

        // Захист: етап має належати ТОМУ Ж проєкту, що й задача — інакше можна
        // було б прив'язати задачу до етапу чужого проєкту прямим POST-запитом.
        if ($milestoneId !== null) {
            $milestone = Milestone::find($milestoneId);
            if (!$milestone || (int) $milestone['project_id'] !== (int) $task['project_id']) {
                header('Location: /tasks/' . $params['id'] . '?error=' . urlencode('Етап має належати цьому ж проєкту'));
                exit;
            }
        }

        Task::updateMilestone((int) $params['id'], $milestoneId, Auth::id());
        header('Location: /tasks/' . $params['id']);
        exit;
    }

    /** Зміна назви задачі прямо на її сторінці. */
    public function updateTitle(array $params): void
    {
        Auth::requireLogin();
        $task = $this->findTaskOrFail((int) $params['id']);

        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            header('Location: /tasks/' . $params['id'] . '?error=' . urlencode('Назва задачі — від 1 до 255 символів'));
            exit;
        }
        if ($title !== $task['title']) {
            Task::updateTitle((int) $params['id'], $title, (string) $task['title'], Auth::id());
        }
        header('Location: /tasks/' . $params['id'] . '?success=' . urlencode('Назву змінено'));
        exit;
    }

    public function updateDescription(array $params): void
    {
        Auth::requireLogin();
        $this->findTaskOrFail((int) $params['id']);

        $description = trim($_POST['description'] ?? '');
        Task::updateDescription((int) $params['id'], $description !== '' ? $description : null, Auth::id());
        header('Location: /tasks/' . $params['id']);
        exit;
    }

    /** Перенесення задачі в інший проєкт — будь-який видимий користувачу (основний або підпроєкт), не лише в межах поточної ієрархії. */
    public function updateProject(array $params): void
    {
        Auth::requireLogin();
        $task = $this->findTaskOrFail((int) $params['id']);

        $newProjectId = (int) ($_POST['project_id'] ?? 0);
        $newProject = Project::find($newProjectId);
        if (!$newProject || !Project::isVisibleTo($newProject, Auth::id(), Auth::hasRole(['admin']))) {
            header('Location: /tasks/' . $params['id'] . '?error=' . urlencode('Проєкт не знайдено або немає до нього доступу'));
            exit;
        }

        if ($newProjectId === (int) $task['project_id']) {
            header('Location: /tasks/' . $params['id']);
            exit;
        }

        if (!empty($task['parent_task_id'])) {
            header('Location: /tasks/' . $params['id'] . '?error=' . urlencode('Підзадачу не можна переносити окремо: перенесіть батьківську задачу або спершу відв\'яжіть підзадачу'));
            exit;
        }

        Task::updateProject((int) $params['id'], $newProjectId, Auth::id());
        // Підзадачі переїжджають разом із батьківською задачею (вони завжди в одному проєкті).
        if (\App\Models\TaskChecklist::ready()) {
            foreach (\App\Models\TaskChecklist::subtasks((int) $params['id']) as $sub) {
                Task::updateProject((int) $sub['id'], $newProjectId, Auth::id());
            }
        }
        header('Location: /tasks/' . $params['id']);
        exit;
    }

    // ------------------------------------------------------------------ підзадачі

    /** Нова підзадача: успадковує проєкт, тип, пріоритет і етап батьківської задачі; глибина вкладеності — один рівень. */
    public function storeSubtask(array $params): void
    {
        Auth::requireLogin();
        $task = $this->findTaskOrFail((int) $params['id']);
        $this->requireChecklistReady((int) $task['id']);

        if (!empty($task['parent_task_id'])) {
            $this->back($task['id'], 'error', 'Підзадача не може мати власних підзадач — додайте її до батьківської задачі');
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            $this->back($task['id'], 'error', 'Вкажіть назву підзадачі (до 255 символів)');
        }
        $due = !empty($_POST['due_date']) ? (string) $_POST['due_date'] : null;
        if ($due !== null && !$this->isValidDate($due)) {
            $this->back($task['id'], 'error', 'Некоректний термін виконання');
        }
        $assignee = !empty($_POST['assignee_id']) ? (int) $_POST['assignee_id'] : null;
        if ($assignee !== null && !User::findById($assignee)) {
            $this->back($task['id'], 'error', 'Виконавця не знайдено');
        }

        $id = Task::create([
            'project_id' => (int) $task['project_id'],
            'parent_task_id' => (int) $task['id'],
            'type_id' => (int) $task['type_id'],
            'status_id' => $this->statusIdByCode('new'),
            'title' => $title,
            'priority' => $task['priority'],
            'author_id' => Auth::id(),
            'assignee_id' => $assignee,
            'due_date' => $due,
            'milestone_id' => $task['milestone_id'] ?: null,
        ]);
        \App\Models\Audit::log('task', (int) $task['id'], 'subtask_added', Auth::id(), ['related_task_id' => $id]);
        $this->back($task['id'], 'success', 'Підзадачу додано', '#subtasks');
    }

    /** Робить підзадачу самостійною задачею. */
    public function detachSubtask(array $params): void
    {
        Auth::requireLogin();
        $task = $this->findTaskOrFail((int) $params['id']);
        $this->requireChecklistReady((int) $task['id']);
        if (!empty($task['parent_task_id'])) {
            \App\Core\Database::connection()->prepare('UPDATE tasks SET parent_task_id = NULL WHERE id = :id')->execute(['id' => $task['id']]);
            \App\Models\Audit::log('task', (int) $task['id'], 'subtask_detached', Auth::id(), ['related_task_id' => (int) $task['parent_task_id']]);
        }
        $this->back($task['id'], 'success', 'Підзадачу відв\'язано — тепер це самостійна задача');
    }

    // ------------------------------------------------------------------ чек-лист

    /** Додає пункти: кожен непорожній рядок поля — окремий пункт (можна вставити кілька разів списком). */
    public function addChecklist(array $params): void
    {
        Auth::requireLogin();
        $task = $this->findTaskOrFail((int) $params['id']);
        $this->requireChecklistReady((int) $task['id']);

        $lines = preg_split('/\R/u', (string) ($_POST['items'] ?? '')) ?: [];
        $added = 0;
        $room = \App\Models\TaskChecklist::MAX_ITEMS - \App\Models\TaskChecklist::count((int) $task['id']);
        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-*•]|\[[ xX]?\]|\d+[.)])\s+/u', '', $line));
            if ($line === '') {
                continue;
            }
            if ($room <= 0) {
                $this->back($task['id'], 'error', 'У чек-листі може бути не більше ' . \App\Models\TaskChecklist::MAX_ITEMS . ' пунктів' . ($added ? ' (додано: ' . $added . ')' : ''), '#checklist');
            }
            \App\Models\TaskChecklist::add((int) $task['id'], mb_substr($line, 0, \App\Models\TaskChecklist::MAX_LENGTH), Auth::id());
            $added++;
            $room--;
        }
        if ($added === 0) {
            $this->back($task['id'], 'error', 'Введіть текст пункту', '#checklist');
        }
        \App\Models\Audit::log('task', (int) $task['id'], 'checklist_added', Auth::id(), ['items' => $added]);
        $this->back($task['id'], 'success', $added === 1 ? 'Пункт додано' : 'Пунктів додано: ' . $added, '#checklist');
    }

    public function toggleChecklist(array $params): void
    {
        Auth::requireLogin();
        $task = $this->findTaskOrFail((int) $params['id']);
        $this->requireChecklistReady((int) $task['id']);
        $item = $this->checklistItemOrFail((int) $params['item'], (int) $task['id']);

        \App\Models\TaskChecklist::setDone((int) $item['id'], !$item['is_done'], Auth::id());
        $this->back($task['id'], null, null, '#checklist');
    }

    public function deleteChecklist(array $params): void
    {
        Auth::requireLogin();
        $task = $this->findTaskOrFail((int) $params['id']);
        $this->requireChecklistReady((int) $task['id']);

        if (($params['item'] ?? '') === 'done') {
            $n = \App\Models\TaskChecklist::deleteDone((int) $task['id']);
            $this->back($task['id'], 'success', 'Видалено виконаних пунктів: ' . $n, '#checklist');
        }
        $item = $this->checklistItemOrFail((int) $params['item'], (int) $task['id']);
        \App\Models\TaskChecklist::delete((int) $item['id']);
        $this->back($task['id'], null, null, '#checklist');
    }

    private function checklistItemOrFail(int $itemId, int $taskId): array
    {
        $item = \App\Models\TaskChecklist::find($itemId);
        // Пункт має належати саме цій задачі, до якої користувач має доступ, — інакше можна було б чіпати чужі чек-листи за id.
        if (!$item || (int) $item['task_id'] !== $taskId) {
            http_response_code(404);
            echo 'Пункт не знайдено.';
            exit;
        }
        return $item;
    }

    private function requireChecklistReady(int $taskId): void
    {
        if (!\App\Models\TaskChecklist::ready()) {
            $this->back($taskId, 'error', 'Підзадачі й чек-листи потребують міграції 038 — зверніться до адміністратора (update.sh)');
        }
    }

    private function back(int|string $taskId, ?string $kind, ?string $message, string $anchor = ''): never
    {
        $q = $kind !== null && $message !== null ? '?' . $kind . '=' . urlencode($message) : '';
        header('Location: /tasks/' . (int) $taskId . $q . $anchor);
        exit;
    }

    public function delete(array $params): void
    {
        Auth::requireLogin();
        $task = $this->findTaskOrFail((int) $params['id']);
        $projectId = (int) $task['project_id'];

        Task::delete((int) $params['id'], $task['title'], Auth::id());
        header('Location: /projects/' . $projectId);
        exit;
    }

    /**
     * Оновлення дат початку/завершення задачі — викликається ЛИШЕ через
     * JavaScript (перетягування/розтягування смуги на діаграмі Ганта), тому
     * повертає JSON, а не редирект, як решта дій цього контролера.
     */
    public function updateDates(array $params): void
    {
        Auth::requireLogin();
        $task = $this->findTaskOrFail((int) $params['id']);

        $startDate = $_POST['start_date'] ?? '';
        $dueDate = $_POST['due_date'] ?? '';

        header('Content-Type: application/json; charset=utf-8');

        if ($startDate !== '' && !$this->isValidDate($startDate)) {
            http_response_code(422);
            echo json_encode(['error' => 'Некоректна дата початку'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($dueDate !== '' && !$this->isValidDate($dueDate)) {
            http_response_code(422);
            echo json_encode(['error' => 'Некоректна дата завершення'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $startDate = $startDate !== '' ? $startDate : null;
        $dueDate = $dueDate !== '' ? $dueDate : null;

        if ($startDate !== null && $dueDate !== null && $startDate > $dueDate) {
            http_response_code(422);
            echo json_encode(['error' => 'Дата початку не може бути пізніше дати завершення'], JSON_UNESCAPED_UNICODE);
            return;
        }

        Task::updateDates((int) $params['id'], $startDate, $dueDate, Auth::id());
        echo json_encode(['ok' => true]);
    }

    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    public function addComment(array $params): void
    {
        Auth::requireLogin();
        $task = $this->findTaskOrFail((int) $params['id']);

        $body = trim($_POST['body'] ?? '');
        if ($body !== '') {
            Task::addComment((int) $params['id'], Auth::id(), $body);
        }
        header('Location: /tasks/' . $params['id']);
        exit;
    }

    public function logTime(array $params): void
    {
        Auth::requireLogin();
        $task = $this->findTaskOrFail((int) $params['id']);

        $hours = (float) ($_POST['hours'] ?? 0);
        if ($hours <= 0) {
            header('Location: /tasks/' . $params['id'] . '?error=' . urlencode('Кількість годин має бути більшою за нуль'));
            exit;
        }

        Task::logTime(
            (int) $params['id'],
            Auth::id(),
            $hours,
            $_POST['log_date'] ?: date('Y-m-d'),
            $_POST['category'] ?? null,
            $_POST['comment'] ?? null
        );
        header('Location: /tasks/' . $params['id']);
        exit;
    }

    /** Знаходить задачу і перевіряє видимість її проєкту, інакше завершує запит 404/403. */
    private function findTaskOrFail(int $taskId): array
    {
        $task = Task::find($taskId);
        if (!$task) {
            http_response_code(404);
            echo 'Задачу не знайдено.';
            exit;
        }
        $this->requireProjectAccess((int) $task['project_id']);
        return $task;
    }

    /** Перевіряє, чи проєкт існує і видимий поточному користувачу (адмін/автор/відповідальний). */
    private function requireProjectAccess(int $projectId): array
    {
        $project = Project::find($projectId);
        if (!$project) {
            http_response_code(404);
            echo 'Проєкт не знайдено.';
            exit;
        }
        if (!Project::isVisibleTo($project, Auth::id(), Auth::hasRole(['admin']))) {
            http_response_code(403);
            echo 'Доступ до цього проєкту обмежено — його бачать лише автор, відповідальний, учасники проєкту та адміністратор системи.';
            exit;
        }
        return $project;
    }

    private function statusIdByCode(string $code): int
    {
        foreach (Task::statuses() as $status) {
            if ($status['code'] === $code) {
                return (int) $status['id'];
            }
        }
        throw new \RuntimeException("Статус з кодом '{$code}' не знайдено. Перевірте seed.sql.");
    }

    private function statusExists(int $statusId): bool
    {
        foreach (Task::statuses() as $status) {
            if ((int) $status['id'] === $statusId) {
                return true;
            }
        }
        return false;
    }

    private function typeExists(int $typeId): bool
    {
        foreach (Task::types() as $type) {
            if ((int) $type['id'] === $typeId) {
                return true;
            }
        }
        return false;
    }
}
