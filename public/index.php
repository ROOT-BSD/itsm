<?php

require __DIR__ . '/../app/autoload.php';

use App\Core\Auth;
use App\Core\Router;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
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

// --- Проєкти ---
$projects = new ProjectController();
$router->get('/projects', [$projects, 'index']);
$router->get('/projects/create', [$projects, 'showCreateForm']);
$router->post('/projects', [$projects, 'store']);
$router->get('/projects/{id}', fn($p) => $projects->show($p));

// --- Задачі ---
$tasks = new TaskController();
$router->get('/projects/{project_id}/tasks/create', fn($p) => $tasks->showCreateForm($p));
$router->post('/projects/{project_id}/tasks', fn($p) => $tasks->store($p));
$router->get('/tasks/{id}', fn($p) => $tasks->show($p));
$router->post('/tasks/{id}/status', fn($p) => $tasks->updateStatus($p));
$router->post('/tasks/{id}/comments', fn($p) => $tasks->addComment($p));
$router->post('/tasks/{id}/time', fn($p) => $tasks->logTime($p));

// --- Адмін-панель (лише роль admin — перевіряється в конструкторі AdminController) ---
$router->get('/admin', function () {
    (new AdminController())->index();
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

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
