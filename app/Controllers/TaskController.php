<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Task;

class TaskController
{
    public function showCreateForm(array $params): void
    {
        Auth::requireLogin();
        View::render('tasks/create', [
            'projectId' => (int) $params['project_id'],
            'types' => Task::types(),
        ]);
    }

    public function store(array $params): void
    {
        Auth::requireLogin();
        $projectId = (int) $params['project_id'];

        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            header("Location: /projects/{$projectId}/tasks/create?error=" . urlencode('Назва задачі обов\'язкова'));
            exit;
        }

        // Статус "new" (id визначаємо за кодом, щоб не залежати від порядку вставки)
        $statusId = $this->statusIdByCode('new');

        $id = Task::create([
            'project_id' => $projectId,
            'type_id' => (int) $_POST['type_id'],
            'status_id' => $statusId,
            'title' => $title,
            'description' => trim($_POST['description'] ?? ''),
            'priority' => $_POST['priority'] ?? 'normal',
            'author_id' => Auth::id(),
            'assignee_id' => $_POST['assignee_id'] ?? null,
            'due_date' => $_POST['due_date'] ?? null,
        ]);

        header("Location: /tasks/{$id}");
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
        ]);
    }

    public function updateStatus(array $params): void
    {
        Auth::requireLogin();
        Task::updateStatus((int) $params['id'], (int) $_POST['status_id'], Auth::id());
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
        Task::logTime(
            (int) $params['id'],
            Auth::id(),
            (float) $_POST['hours'],
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
}
