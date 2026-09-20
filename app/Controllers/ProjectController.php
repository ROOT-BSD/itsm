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

    public function index(): void
    {
        Auth::requireLogin();
        View::render('projects/index', ['projects' => Project::all()]);
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
        $project = Project::find((int) $params['id']);

        if (!$project) {
            http_response_code(404);
            echo 'Проєкт не знайдено.';
            return;
        }

        View::render('projects/show', [
            'project' => $project,
            'tasks' => Task::forProject($project['id']),
            'users' => User::allActive(),
        ]);
    }

    public function updateResponsible(array $params): void
    {
        Auth::requireLogin();
        $this->requireManagerRole();

        $projectId = (int) $params['id'];
        $responsibleUserId = !empty($_POST['responsible_user_id']) ? (int) $_POST['responsible_user_id'] : null;

        Project::updateResponsible($projectId, $responsibleUserId, Auth::id());
        header('Location: /projects/' . $projectId);
        exit;
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
