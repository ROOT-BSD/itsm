<?php

namespace App\Controllers\Api;

use App\Core\ApiResponse;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ApiSerializer;

/** Задачі: читання, створення в проєкті, зміна окремих полів, коментарі. Доступ — через видимість проєкту задачі. */
class TasksApiController extends ApiController
{
    /** Поля, які дозволено змінювати через PATCH (решта — лише у веб-інтерфейсі). */
    private const PATCHABLE = ['status_id', 'status', 'assignee_id', 'description', 'start_date', 'due_date', 'milestone_id'];

    public function index(): void
    {
        $tasks = Task::allOpenVisibleTo($this->userId, $this->isAdmin);
        if (($_GET['include_closed'] ?? '') === '1') {
            $tasks = array_merge($tasks, Task::allClosedVisibleTo($this->userId, $this->isAdmin));
        }

        $projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : null;
        $assigneeId = isset($_GET['assignee_id']) ? (int) $_GET['assignee_id'] : null;
        $statusId = isset($_GET['status_id']) ? (int) $_GET['status_id'] : null;
        $priority = $_GET['priority'] ?? null;
        $query = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
        if ($priority !== null && !in_array($priority, Task::PRIORITIES, true)) {
            $this->validationFailed(['priority' => 'Допустимі значення: ' . implode(', ', Task::PRIORITIES) . '.']);
        }

        $tasks = array_values(array_filter($tasks, static fn(array $t): bool =>
            ($projectId === null || (int) $t['project_id'] === $projectId)
            && ($assigneeId === null || (int) ($t['assignee_id'] ?? 0) === $assigneeId)
            && ($statusId === null || (int) $t['status_id'] === $statusId)
            && ($priority === null || $t['priority'] === $priority)
            && ($query === '' || str_contains(mb_strtolower($t['title']), $query))
        ));

        // Повне представлення (з іменами) будуємо лише для елементів поточної сторінки.
        $this->paginate($tasks, static fn(array $t) => ($full = Task::find((int) $t['id'])) ? ApiSerializer::task($full) : null);
    }

    public function show(array $params): void
    {
        $task = $this->visibleTask((int) $params['id']);
        $data = ApiSerializer::task($task);
        $data['comments'] = array_map([ApiSerializer::class, 'taskComment'], Task::comments((int) $task['id']));
        ApiResponse::ok($data);
    }

    /** POST /projects/{id}/tasks */
    public function store(array $params): void
    {
        $this->requireWrite();
        $project = Project::find((int) $params['id']);
        if (!$project || !Project::isVisibleTo($project, $this->userId, $this->isAdmin)) {
            $this->notFound('Проєкт');
        }
        $data = $this->body();
        $errors = [];

        $title = $this->stringField($data, 'title', 255, $errors, true);
        $description = $this->stringField($data, 'description', 20000, $errors);
        $typeId = $this->intField($data, 'type_id', $errors, true);
        if ($typeId !== null && !$this->exists(Task::types(), $typeId)) {
            $errors['type_id'] = 'Такого типу задачі немає (див. GET /api/v1/task-types).';
        }
        $priority = $data['priority'] ?? 'normal';
        if (!is_string($priority) || !in_array($priority, Task::PRIORITIES, true)) {
            $errors['priority'] = 'Допустимі значення: ' . implode(', ', Task::PRIORITIES) . '.';
        }
        $assigneeId = $this->intField($data, 'assignee_id', $errors);
        if ($assigneeId !== null && !$this->activeUser($assigneeId)) {
            $errors['assignee_id'] = 'Такого активного користувача немає (див. GET /api/v1/users).';
        }
        [$start, $due] = $this->dates($data, $errors);
        $milestoneId = $this->intField($data, 'milestone_id', $errors);
        if ($milestoneId !== null && !$this->milestoneInProject($milestoneId, (int) $project['id'])) {
            $errors['milestone_id'] = 'Етап має належати цьому ж проєкту.';
        }
        if ($errors) {
            $this->validationFailed($errors);
        }

        $id = Task::create([
            'project_id' => (int) $project['id'],
            'type_id' => $typeId,
            'status_id' => $this->statusIdByCode('new'),
            'title' => $title,
            'description' => $description ?? '',
            'priority' => $priority,
            'author_id' => $this->userId,
            'assignee_id' => $assigneeId,
            'start_date' => $start,
            'due_date' => $due,
            'milestone_id' => $milestoneId,
        ]);
        ApiResponse::ok(ApiSerializer::task(Task::find($id)), 201);
    }

    public function update(array $params): void
    {
        $this->requireWrite();
        $task = $this->visibleTask((int) $params['id']);
        $data = $this->body();
        $errors = [];

        $unknown = array_diff(array_keys($data), self::PATCHABLE);
        foreach ($unknown as $field) {
            $errors[(string) $field] = 'Це поле не можна змінити через API. Дозволені: ' . implode(', ', self::PATCHABLE) . '.';
        }
        if (!$data) {
            $errors['_'] = 'Вкажіть хоча б одне поле для зміни.';
        }

        // --- перевірка ВСІХ полів до першої зміни: запит або застосовується цілком, або не застосовується зовсім ---
        $newStatusId = null;
        if (array_key_exists('status_id', $data) || array_key_exists('status', $data)) {
            if (array_key_exists('status', $data)) {
                $newStatusId = is_string($data['status']) ? $this->statusIdByCode($data['status']) : null;
                if ($newStatusId === null) {
                    $errors['status'] = 'Невідомий статус (див. GET /api/v1/task-statuses, поле code).';
                }
            } else {
                $newStatusId = $this->intField($data, 'status_id', $errors, true);
                if ($newStatusId !== null && !$this->exists(Task::statuses(), $newStatusId)) {
                    $errors['status_id'] = 'Такого статусу немає (див. GET /api/v1/task-statuses).';
                }
            }
        }
        $assigneeGiven = array_key_exists('assignee_id', $data);
        $assigneeId = $assigneeGiven ? $this->intField($data, 'assignee_id', $errors) : null;
        if ($assigneeId !== null && !$this->activeUser($assigneeId)) {
            $errors['assignee_id'] = 'Такого активного користувача немає (див. GET /api/v1/users).';
        }
        $descriptionGiven = array_key_exists('description', $data);
        $description = $descriptionGiven ? $this->stringField($data, 'description', 20000, $errors) : null;

        $datesGiven = array_key_exists('start_date', $data) || array_key_exists('due_date', $data);
        $start = !empty($task['start_date']) ? substr((string) $task['start_date'], 0, 10) : null;
        $due = !empty($task['due_date']) ? substr((string) $task['due_date'], 0, 10) : null;
        if ($datesGiven) {
            [$s, $d] = $this->dates($data, $errors, $start, $due);
            [$start, $due] = [$s, $d];
        }
        $milestoneGiven = array_key_exists('milestone_id', $data);
        $milestoneId = $milestoneGiven ? $this->intField($data, 'milestone_id', $errors) : null;
        if ($milestoneId !== null && !$this->milestoneInProject($milestoneId, (int) $task['project_id'])) {
            $errors['milestone_id'] = 'Етап має належати проєкту цієї задачі.';
        }
        if ($errors) {
            $this->validationFailed($errors);
        }

        // --- застосування: лише те, що справді змінилось (інакше журнал аудиту й сповіщення засмічувались би «змінами» без змін) ---
        $id = (int) $task['id'];
        if ($newStatusId !== null && $newStatusId !== (int) $task['status_id']) {
            Task::updateStatus($id, $newStatusId, $this->userId);
        }
        if ($assigneeGiven && $assigneeId !== (!empty($task['assignee_id']) ? (int) $task['assignee_id'] : null)) {
            Task::updateAssignee($id, $assigneeId, $this->userId);
        }
        if ($descriptionGiven && ($description ?? '') !== (string) ($task['description'] ?? '')) {
            Task::updateDescription($id, $description, $this->userId);
        }
        if ($datesGiven) {
            Task::updateDates($id, $start, $due, $this->userId);
        }
        if ($milestoneGiven && $milestoneId !== (!empty($task['milestone_id']) ? (int) $task['milestone_id'] : null)) {
            Task::updateMilestone($id, $milestoneId, $this->userId);
        }
        ApiResponse::ok(ApiSerializer::task(Task::find($id)));
    }

    public function addComment(array $params): void
    {
        $this->requireWrite();
        $task = $this->visibleTask((int) $params['id']);
        $errors = [];
        $body = $this->stringField($this->body(), 'body', 20000, $errors, true);
        if ($errors) {
            $this->validationFailed($errors);
        }
        Task::addComment((int) $task['id'], $this->userId, $body);
        $comments = Task::comments((int) $task['id']);
        ApiResponse::ok(ApiSerializer::taskComment(end($comments)), 201);
    }

    // ------------------------------------------------------------------ допоміжне

    private function visibleTask(int $id): array
    {
        $task = Task::find($id);
        $project = $task ? Project::find((int) $task['project_id']) : null;
        if (!$task || !$project || !Project::isVisibleTo($project, $this->userId, $this->isAdmin)) {
            $this->notFound('Задачу');
        }
        return $task;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function exists(array $rows, int $id): bool
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    private function activeUser(int $id): bool
    {
        $user = User::findById($id);
        return $user !== null && (int) $user['is_active'] === 1;
    }

    private function milestoneInProject(int $milestoneId, int $projectId): bool
    {
        $m = Milestone::find($milestoneId);
        return $m !== null && (int) $m['project_id'] === $projectId;
    }

    private function statusIdByCode(string $code): ?int
    {
        foreach (Task::statuses() as $status) {
            if ($status['code'] === $code) {
                return (int) $status['id'];
            }
        }
        return null;
    }

    /**
     * Дата початку й термін з тіла (формат РРРР-ММ-ДД; null/порожнє — прибрати). Для PATCH незадане поле лишається поточним.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function dates(array $data, array &$errors, ?string $currentStart = null, ?string $currentDue = null): array
    {
        $out = [];
        foreach (['start_date' => $currentStart, 'due_date' => $currentDue] as $key => $current) {
            if (!array_key_exists($key, $data)) {
                $out[$key] = $current;
                continue;
            }
            $value = $data[$key];
            if ($value === null || $value === '') {
                $out[$key] = null;
            } elseif (is_string($value) && $this->isValidDate($value)) {
                $out[$key] = $value;
            } else {
                $errors[$key] = 'Очікується дата у форматі РРРР-ММ-ДД.';
                $out[$key] = $current;
            }
        }
        if (!isset($errors['start_date'], $errors['due_date']) && $out['start_date'] !== null && $out['due_date'] !== null && $out['start_date'] > $out['due_date']) {
            $errors['start_date'] = 'Дата початку не може бути пізніше терміну виконання.';
        }
        return [$out['start_date'], $out['due_date']];
    }
}
