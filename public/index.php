<?php

require __DIR__ . '/../app/autoload.php';

// Перший рядок після автозавантаження: так у журнал і на сторінку помилки потрапляє будь-який збій нижче (див. App\Core\ErrorHandler).
\App\Core\ErrorHandler::register();

use App\Core\Auth;
use App\Core\Csp;
use App\Core\Router;
use App\Controllers\AdminController;
use App\Controllers\AuditController;
use App\Controllers\EmailController;
use App\Controllers\AdController;
use App\Controllers\AttachmentController;
use App\Controllers\WikiController;
use App\Controllers\AuthController;
use App\Controllers\CalendarController;
use App\Controllers\ReportController;
use App\Controllers\ProfileController;
use App\Controllers\TicketController;
use App\Controllers\ArchiveController;
use App\Controllers\SupportController;
use App\Controllers\DashboardController;
use App\Controllers\ProjectController;
use App\Controllers\TaskController;

Auth::start();
Csp::sendHeaders();

$router = new Router();

// --- Автентифікація ---
$auth = new AuthController();
$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login']);
$router->get('/logout', [$auth, 'logout']);

// --- Портал самообслуговування (публічно, без входу в систему) ---
$support = new SupportController();
$router->get('/support', [$support, 'showForm']);
$router->post('/support', [$support, 'store']);
$router->get('/support/track/{token}', [$support, 'track']);
$router->post('/support/track/{token}/comment', [$support, 'addComment']);
$router->post('/support/track/{token}/rate', [$support, 'rateCsat']);

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
$router->post('/projects/{id}/members', fn($p) => $projects->addMember($p));
$router->post('/projects/{id}/members/{userId}/delete', fn($p) => $projects->removeMember($p));

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
$router->post('/tasks/{id}/description', fn($p) => $tasks->updateDescription($p));
$router->post('/tasks/{id}/project', fn($p) => $tasks->updateProject($p));
$router->post('/tasks/{id}/delete', fn($p) => $tasks->delete($p));

// --- Тікети (Service Desk, базова версія — Епік 12) ---
$tickets = new TicketController();
$router->get('/tickets', fn() => $tickets->index());
$router->get('/tickets/create', fn() => $tickets->showCreateForm());
$router->post('/tickets', fn() => $tickets->store());
$router->get('/tickets/{id}', fn($p) => $tickets->show($p));

// --- Архів (закриті тікети/задачі/проєкти) ---
$router->get('/archive', fn() => (new ArchiveController())->index());
$router->post('/tickets/{id}/operator', fn($p) => $tickets->assignOperator($p));
$router->post('/tickets/{id}/status', fn($p) => $tickets->updateStatus($p));
$router->post('/tickets/{id}/comments', fn($p) => $tickets->addComment($p));
$router->post('/tickets/{id}/csat', fn($p) => $tickets->submitCsat($p));

// --- Вкладення до тікетів і задач (jpg, png, pdf) ---
$attachments = new AttachmentController();
$router->post('/tickets/{id}/attachments', fn($p) => $attachments->uploadToTicket($p));
$router->post('/tasks/{id}/attachments', fn($p) => $attachments->uploadToTask($p));
$router->get('/attachments/{id}', fn($p) => $attachments->download($p));
$router->post('/attachments/{id}/delete', fn($p) => $attachments->delete($p));

// --- Вікі: сторінки в Markdown з історією версій ---
// Порядок важливий: «/wiki/new» і «/wiki/preview» мають стояти ПЕРЕД «/wiki/{slug}», інакше їх прийняв би за адресу сторінки.
$wiki = new WikiController();
$router->get('/wiki', fn() => $wiki->index());
$router->get('/wiki/new', fn() => $wiki->create());
$router->post('/wiki/preview', fn() => $wiki->preview());
$router->post('/wiki', fn() => $wiki->store());
$router->get('/wiki/{slug}', fn($p) => $wiki->show($p));
$router->get('/wiki/{slug}/edit', fn($p) => $wiki->edit($p));
$router->post('/wiki/{slug}', fn($p) => $wiki->update($p));
$router->post('/wiki/{slug}/delete', fn($p) => $wiki->delete($p));
$router->get('/wiki/{slug}/history', fn($p) => $wiki->history($p));
$router->get('/wiki/{slug}/revisions/{id}', fn($p) => $wiki->revision($p));
$router->post('/wiki/{slug}/revisions/{id}/restore', fn($p) => $wiki->restore($p));

// --- Адмін-панель (лише роль admin — перевіряється в конструкторі AdminController) ---
$router->get('/admin', function () {
    (new AdminController())->index();
});
$router->get('/admin/manage', function () {
    (new AdminController())->manage();
});
$router->get('/admin/settings', function () {
    (new AdminController())->settings();
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
$router->get('/admin/audit', function () {
    (new AuditController())->index();
});
$router->get('/admin/csat', function () {
    (new AdminController())->csat();
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
$router->post('/admin/projects/{id}/status', function ($p) {
    (new AdminController())->updateProjectStatus($p);
});
$router->get('/admin/security', function () {
    (new AdminController())->securitySettings();
});
$router->post('/admin/security', function () {
    (new AdminController())->updateSecuritySettings();
});
$router->post('/admin/users/{id}/unlock', function ($p) {
    (new AdminController())->unlockUser($p);
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
$router->post('/admin/queues/{id}/sla', function ($p) {
    (new AdminController())->updateSlaPolicy($p);
});
$router->post('/admin/queues/{id}/default-operator', function ($p) {
    (new AdminController())->updateQueueDefaultOperator($p);
});
$router->get('/admin/ad', function () {
    (new AdController())->index();
});
$router->post('/admin/ad', function () {
    (new AdController())->save();
});
$router->post('/admin/ad/connection', function () {
    (new AdController())->saveConnection();
});
$router->post('/admin/ad/test', function () {
    (new AdController())->test();
});
$router->post('/admin/ad/sync', function () {
    (new AdController())->syncNow();
});
$router->post('/admin/ad/mappings', function () {
    (new AdController())->saveMapping();
});
$router->post('/admin/ad/mappings/{id}/delete', function ($p) {
    (new AdController())->deleteMapping($p);
});
$router->get('/admin/email', function () {
    (new EmailController())->index();
});
$router->post('/admin/email', function () {
    (new EmailController())->save();
});
$router->post('/admin/email/test', function () {
    (new EmailController())->test();
});
$router->post('/admin/email/test-smtp', function () {
    (new EmailController())->testSmtp();
});
$router->post('/admin/email/send-test', function () {
    (new EmailController())->sendTestEmail();
});
$router->post('/admin/email/app-url', function () {
    (new EmailController())->saveAppUrl();
});
$router->post('/admin/email/fetch', function () {
    (new EmailController())->fetchNow();
});

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
