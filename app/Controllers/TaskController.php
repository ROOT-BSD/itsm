<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskController
{
    /** Допустимі значення пріоритету (ENUM у БД) — валідуємо тут, щоб замість
     *  незрозумілої помилки БД користувач бачив зрозуміле повідомлення. */
    private const PRIORITIES = ['low', 'normal', 'high', 'critical'];

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
            echo 'Доступ до цього проєкту обмежено — його бачать лише автор, відповідальний та адміністратор системи.';
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
