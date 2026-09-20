<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Task;
use App\Models\User;

class TaskController
{
    /** Допустимі значення пріоритету (ENUM у БД) — валідуємо тут, щоб замість
     *  незрозумілої помилки БД користувач бачив зрозуміле повідомлення. */
    private const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    public function showCreateForm(array $params): void
    {
        Auth::requireLogin();
        View::render('tasks/create', [
            'projectId' => (int) $params['project_id'],
            'types' => Task::types(),
            'users' => User::allActive(),
        ]);
    }

    public function store(array $params): void
    {
        Auth::requireLogin();
        $projectId = (int) $params['project_id'];

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
            'due_date' => !empty($_POST['due_date']) ? $_POST['due_date'] : null,
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
        $assigneeId = !empty($_POST['assignee_id']) ? (int) $_POST['assignee_id'] : null;
        Task::updateAssignee((int) $params['id'], $assigneeId, Auth::id());
        header('Location: /tasks/' . $params['id']);
        exit;
    }

    public function addComment(array $params): void
    {
        Auth::requireLogin();
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
