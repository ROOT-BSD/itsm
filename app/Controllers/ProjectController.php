<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class ProjectController
{
    /** Допустимі значення видимості (ENUM у БД). */
    private const VISIBILITIES = ['public', 'private', 'restricted'];

    /** Допустимі типи зв'язків між задачами (ENUM у БД). */
    private const RELATION_TYPES = ['blocks', 'blocked_by', 'duplicates', 'related'];

    public function index(): void
    {
        Auth::requireLogin();
        View::render('projects/index', [
            'projects' => Project::allVisibleTo(Auth::id(), Auth::hasRole(['admin'])),
        ]);
    }

    public function showCreateForm(): void
    {
        Auth::requireLogin();
        $this->requireManagerRole();
        View::render('projects/create', ['users' => User::allActive()]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        $this->requireManagerRole();

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $visibility = $_POST['visibility'] ?? 'private';
        $responsibleUserId = !empty($_POST['responsible_user_id']) ? (int) $_POST['responsible_user_id'] : null;

        if ($name === '') {
            header('Location: /projects/create?error=' . urlencode('Назва проєкту обов\'язкова'));
            exit;
        }
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            header('Location: /projects/create?error=' . urlencode('Некоректне значення видимості'));
            exit;
        }

        $id = Project::create($name, $description, $visibility, Auth::id(), $responsibleUserId);
        header('Location: /projects/' . $id);
        exit;
    }

    public function show(array $params): void
    {
        Auth::requireLogin();
        $project = $this->requireProjectAccess((int) $params['id']);

        View::render('projects/show', [
            'project' => $project,
            'tasks' => Task::forProject($project['id']),
            'users' => User::allActive(),
        ]);
    }

    /** Канбан-дошка проєкту: задачі, згруповані по колонках-статусах. */
    public function board(array $params): void
    {
        Auth::requireLogin();
        $project = $this->requireProjectAccess((int) $params['id']);

        $tasks = Task::forProject($project['id']);
        $statuses = Task::statuses();

        $tasksByStatus = [];
        foreach ($statuses as $status) {
            $tasksByStatus[$status['id']] = [];
        }
        foreach ($tasks as $task) {
            $tasksByStatus[$task['status_id']][] = $task;
        }

        View::render('projects/board', [
            'project' => $project,
            'statuses' => $statuses,
            'tasksByStatus' => $tasksByStatus,
        ]);
    }

    /** Діаграма Ганта проєкту: терміни задач + залежності, з можливістю редагування дат. */
    public function gantt(array $params): void
    {
        Auth::requireLogin();
        $project = $this->requireProjectAccess((int) $params['id']);

        View::render('projects/gantt', [
            'project' => $project,
            'tasks' => Task::forProject($project['id']),
            'relations' => Task::relationsForProject($project['id']),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    /** Додавання зв'язку залежності між двома задачами одного проєкту (з форми на сторінці Ганта). */
    public function addRelation(array $params): void
    {
        Auth::requireLogin();
        $project = $this->requireProjectAccess((int) $params['id']);

        $taskId = (int) ($_POST['task_id'] ?? 0);
        $relatedTaskId = (int) ($_POST['related_task_id'] ?? 0);
        $relationType = $_POST['relation_type'] ?? '';

        if (!in_array($relationType, self::RELATION_TYPES, true)) {
            header('Location: /projects/' . $project['id'] . '/gantt?error=' . urlencode('Некоректний тип зв\'язку'));
            exit;
        }
        if ($taskId === 0 || $taskId === $relatedTaskId) {
            header('Location: /projects/' . $project['id'] . '/gantt?error=' . urlencode('Оберіть дві різні задачі'));
            exit;
        }

        $task = Task::find($taskId);
        $relatedTask = Task::find($relatedTaskId);
        if (!$task || !$relatedTask || (int) $task['project_id'] !== $project['id'] || (int) $relatedTask['project_id'] !== $project['id']) {
            header('Location: /projects/' . $project['id'] . '/gantt?error=' . urlencode('Обидві задачі мають належати цьому проєкту'));
            exit;
        }

        Task::addRelation($taskId, $relatedTaskId, $relationType, Auth::id());
        header('Location: /projects/' . $project['id'] . '/gantt?success=' . urlencode('Залежність додано'));
        exit;
    }

    /** Зміна типу вже створеного зв'язку залежності. */
    public function updateRelation(array $params): void
    {
        Auth::requireLogin();
        $project = $this->requireProjectAccess((int) $params['id']);

        $relationId = (int) $params['relationId'];
        $relationType = $_POST['relation_type'] ?? '';

        if (!in_array($relationType, self::RELATION_TYPES, true)) {
            header('Location: /projects/' . $project['id'] . '/gantt?error=' . urlencode('Некоректний тип зв\'язку'));
            exit;
        }

        $relation = Task::findRelationInProject($relationId, $project['id']);
        if (!$relation) {
            header('Location: /projects/' . $project['id'] . '/gantt?error=' . urlencode('Зв\'язок не знайдено'));
            exit;
        }

        Task::updateRelation($relationId, $relationType, Auth::id());
        header('Location: /projects/' . $project['id'] . '/gantt?success=' . urlencode('Зв\'язок оновлено'));
        exit;
    }

    /** Видалення зв'язку залежності. */
    public function deleteRelation(array $params): void
    {
        Auth::requireLogin();
        $project = $this->requireProjectAccess((int) $params['id']);

        $relationId = (int) $params['relationId'];
        $relation = Task::findRelationInProject($relationId, $project['id']);
        if (!$relation) {
            header('Location: /projects/' . $project['id'] . '/gantt?error=' . urlencode('Зв\'язок не знайдено'));
            exit;
        }

        Task::deleteRelation($relationId, Auth::id());
        header('Location: /projects/' . $project['id'] . '/gantt?success=' . urlencode('Зв\'язок видалено'));
        exit;
    }

    public function updateResponsible(array $params): void
    {
        Auth::requireLogin();
        $this->requireManagerRole();

        $projectId = (int) $params['id'];
        $this->requireProjectAccess($projectId);

        $responsibleUserId = !empty($_POST['responsible_user_id']) ? (int) $_POST['responsible_user_id'] : null;

        Project::updateResponsible($projectId, $responsibleUserId, Auth::id());
        header('Location: /projects/' . $projectId);
        exit;
    }

    /** Знаходить проєкт і перевіряє видимість, інакше завершує запит 404/403. */
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

    private function requireManagerRole(): void
    {
        if (!Auth::hasRole(['admin', 'it_manager'])) {
            http_response_code(403);
            echo 'Недостатньо прав для виконання цієї дії.';
            exit;
        }
    }
}
