<?php

require __DIR__ . '/../app/autoload.php';

use App\Core\Auth;
use App\Core\Router;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\CalendarController;
use App\Controllers\ReportController;
use App\Controllers\ProfileController;
use App\Controllers\TicketController;
use App\Controllers\DashboardController;
use App\Controllers\ProjectController;
use App\Controllers\TaskController;

Auth::start();

$router = new Router();

// --- Автентифікація ---
$auth = new AuthController();
$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login']);
$router->get('/logout', [$auth, 'logout']);

// --- Дашборд ---
$dashboard = new DashboardController();
$router->get('/', [$dashboard, 'index']);

// --- Календар ---
$calendar = new CalendarController();
$router->get('/calendar', [$calendar, 'index']);

// --- Звіти ---
$reports = new ReportController();
$router->get('/reports', [$reports, 'index']);
$router->get('/reports/pdf', [$reports, 'generatePdf']);

// --- Особистий кабінет ---
$profile = new ProfileController();
$router->get('/profile', [$profile, 'index']);
$router->post('/profile/password', [$profile, 'updatePassword']);

// --- Проєкти ---
$projects = new ProjectController();
$router->get('/projects', [$projects, 'index']);
$router->get('/projects/create', [$projects, 'showCreateForm']);
$router->post('/projects', [$projects, 'store']);
$router->get('/projects/{id}', fn($p) => $projects->show($p));
$router->get('/projects/{id}/board', fn($p) => $projects->board($p));
$router->get('/projects/{id}/gantt', fn($p) => $projects->gantt($p));
$router->get('/projects/{id}/roadmap', fn($p) => $projects->roadmap($p));
$router->get('/projects/{id}/time', fn($p) => $projects->timeReport($p));
$router->post('/projects/{id}/milestones', fn($p) => $projects->createMilestone($p));
$router->post('/projects/{id}/milestones/{milestoneId}/status', fn($p) => $projects->updateMilestoneStatus($p));
$router->post('/projects/{id}/milestones/{milestoneId}/delete', fn($p) => $projects->deleteMilestone($p));
$router->post('/projects/{id}/relations', fn($p) => $projects->addRelation($p));
$router->post('/projects/{id}/relations/{relationId}', fn($p) => $projects->updateRelation($p));
$router->post('/projects/{id}/relations/{relationId}/delete', fn($p) => $projects->deleteRelation($p));
$router->post('/projects/{id}/responsible', fn($p) => $projects->updateResponsible($p));

// --- Задачі ---
$tasks = new TaskController();
$router->get('/tasks', fn() => $tasks->indexOpen());
$router->get('/projects/{project_id}/tasks/create', fn($p) => $tasks->showCreateForm($p));
$router->post('/projects/{project_id}/tasks', fn($p) => $tasks->store($p));
$router->get('/tasks/{id}', fn($p) => $tasks->show($p));
$router->post('/tasks/{id}/status', fn($p) => $tasks->updateStatus($p));
$router->post('/tasks/{id}/dates', fn($p) => $tasks->updateDates($p));
$router->post('/tasks/{id}/milestone', fn($p) => $tasks->updateMilestone($p));
$router->post('/tasks/{id}/assignee', fn($p) => $tasks->updateAssignee($p));
$router->post('/tasks/{id}/comments', fn($p) => $tasks->addComment($p));
$router->post('/tasks/{id}/time', fn($p) => $tasks->logTime($p));

// --- Тікети (Service Desk, базова версія — Епік 12) ---
$tickets = new TicketController();
$router->get('/tickets', fn() => $tickets->index());
$router->get('/tickets/create', fn() => $tickets->showCreateForm());
$router->post('/tickets', fn() => $tickets->store());
$router->get('/tickets/{id}', fn($p) => $tickets->show($p));
$router->post('/tickets/{id}/operator', fn($p) => $tickets->assignOperator($p));
$router->post('/tickets/{id}/status', fn($p) => $tickets->updateStatus($p));
$router->post('/tickets/{id}/comments', fn($p) => $tickets->addComment($p));

// --- Адмін-панель (лише роль admin — перевіряється в конструкторі AdminController) ---
$router->get('/admin', function () {
    (new AdminController())->index();
});
$router->get('/admin/board', function () {
    (new AdminController())->board();
});
$router->get('/admin/gantt', function () {
    (new AdminController())->gantt();
});
$router->get('/admin/time', function () {
    (new AdminController())->timeReport();
});
$router->get('/admin/users', function () {
    (new AdminController())->users();
});
$router->get('/admin/users/create', function () {
    (new AdminController())->showCreateUserForm();
});
$router->post('/admin/users', function () {
    (new AdminController())->storeUser();
});
$router->get('/admin/users/{id}/edit', function ($p) {
    (new AdminController())->showEditUserForm($p);
});
$router->post('/admin/users/{id}', function ($p) {
    (new AdminController())->updateUser($p);
});
$router->post('/admin/users/{id}/delete', function ($p) {
    (new AdminController())->deleteUser($p);
});
$router->post('/admin/users/{id}/password', function ($p) {
    (new AdminController())->updatePassword($p);
});
$router->post('/admin/users/{id}/active', function ($p) {
    (new AdminController())->toggleActive($p);
});
$router->get('/admin/projects', function () {
    (new AdminController())->projects();
});
$router->post('/admin/projects/{id}/delete', function ($p) {
    (new AdminController())->deleteProject($p);
});
$router->post('/admin/projects/{id}/visibility', function ($p) {
    (new AdminController())->updateProjectVisibility($p);
});
$router->get('/admin/queues', function () {
    (new AdminController())->queues();
});
$router->get('/admin/queues/create', function () {
    (new AdminController())->showCreateQueueForm();
});
$router->post('/admin/queues', function () {
    (new AdminController())->storeQueue();
});

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
