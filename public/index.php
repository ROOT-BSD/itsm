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
use App\Controllers\LibraryController;
use App\Controllers\ForumController;
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
use App\Controllers\ApiAdminController;
use App\Controllers\WebhookAdminController;
use App\Controllers\Api\LookupsApiController;
use App\Controllers\Api\MeApiController;
use App\Controllers\Api\ProjectsApiController;
use App\Controllers\Api\TasksApiController;
use App\Controllers\Api\TicketsApiController;

// REST API (/api/…) не має сесій і cookie: автентифікація лише токеном у заголовку. Без session_start() запит API не створює
// сесію, не видає Set-Cookie й не може «успадкувати» вхід користувача з браузера; CSP-заголовки потрібні лише HTML-сторінкам.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($requestPath !== '/api' && !str_starts_with($requestPath, '/api/')) {
    Auth::start();
    Csp::sendHeaders();
}

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
$router->post('/profile/api-tokens', [$profile, 'createApiToken']);
$router->post('/profile/api-tokens/{id}/revoke', fn($p) => $profile->revokeApiToken($p));

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
$router->get('/wiki/files/{id}', fn($p) => $wiki->file($p));
$router->get('/wiki/{slug}', fn($p) => $wiki->show($p));
$router->get('/wiki/{slug}/edit', fn($p) => $wiki->edit($p));
$router->post('/wiki/{slug}', fn($p) => $wiki->update($p));
$router->post('/wiki/{slug}/delete', fn($p) => $wiki->delete($p));
$router->post('/wiki/{slug}/attachments', fn($p) => $wiki->uploadAttachment($p));
$router->post('/wiki/{slug}/attachments/{id}/delete', fn($p) => $wiki->deleteAttachment($p));
$router->get('/wiki/{slug}/history', fn($p) => $wiki->history($p));
$router->get('/wiki/{slug}/revisions/{id}', fn($p) => $wiki->revision($p));
$router->post('/wiki/{slug}/revisions/{id}/restore', fn($p) => $wiki->restore($p));

// Бібліотека документів. Статичні адреси («new», «categories», «versions») — ПЕРЕД «/library/{id}».
$library = new LibraryController();
$router->get('/library', fn() => $library->index());
$router->get('/library/new', fn() => $library->create());
$router->post('/library', fn() => $library->store());
$router->post('/library/categories', fn() => $library->createCategory());
$router->post('/library/categories/{id}', fn($p) => $library->updateCategory($p));
$router->post('/library/categories/{id}/delete', fn($p) => $library->deleteCategory($p));
$router->get('/library/versions/{id}/download', fn($p) => $library->downloadVersion($p));
$router->post('/library/versions/{id}/delete', fn($p) => $library->deleteVersion($p));
$router->get('/library/{id}', fn($p) => $library->show($p));
$router->get('/library/{id}/edit', fn($p) => $library->edit($p));
$router->post('/library/{id}', fn($p) => $library->update($p));
$router->post('/library/{id}/versions', fn($p) => $library->addVersion($p));
$router->post('/library/{id}/delete', fn($p) => $library->delete($p));
$router->get('/library/{id}/download', fn($p) => $library->download($p));

// Форум. Статичні адреси («boards/new», «search») — ПЕРЕД адресами з {id}.
$forum = new ForumController();
$router->get('/forum', fn() => $forum->index());
$router->get('/forum/search', fn() => $forum->search());
$router->get('/forum/boards/new', fn() => $forum->newBoard());
$router->post('/forum/boards', fn() => $forum->storeBoard());
$router->get('/forum/boards/{id}', fn($p) => $forum->board($p));
$router->get('/forum/boards/{id}/edit', fn($p) => $forum->editBoard($p));
$router->post('/forum/boards/{id}', fn($p) => $forum->updateBoard($p));
$router->post('/forum/boards/{id}/delete', fn($p) => $forum->deleteBoard($p));
$router->get('/forum/boards/{id}/topics/new', fn($p) => $forum->newTopic($p));
$router->post('/forum/boards/{id}/topics', fn($p) => $forum->storeTopic($p));
$router->get('/forum/topics/{id}', fn($p) => $forum->topic($p));
$router->post('/forum/topics/{id}/reply', fn($p) => $forum->reply($p));
$router->post('/forum/topics/{id}/pin', fn($p) => $forum->pin($p));
$router->post('/forum/topics/{id}/lock', fn($p) => $forum->lock($p));
$router->post('/forum/topics/{id}/move', fn($p) => $forum->move($p));
$router->get('/forum/posts/{id}', fn($p) => $forum->post($p));
$router->get('/forum/posts/{id}/edit', fn($p) => $forum->editPost($p));
$router->post('/forum/posts/{id}', fn($p) => $forum->updatePost($p));
$router->post('/forum/posts/{id}/delete', fn($p) => $forum->deletePost($p));

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

// --- Адмін-сторінка REST API ---
$router->get('/admin/api', fn() => (new ApiAdminController())->index());
$router->post('/admin/api/settings', fn() => (new ApiAdminController())->saveSettings());
$router->post('/admin/api/tokens', fn() => (new ApiAdminController())->createToken());
$router->post('/admin/api/tokens/{id}/revoke', fn($p) => (new ApiAdminController())->revokeToken($p));

// --- Адмін-сторінки вебхуків (маршрут «new» стоїть перед «{id}»: точний збіг перевіряється першим) ---
$router->get('/admin/webhooks', fn() => (new WebhookAdminController())->index());
$router->get('/admin/webhooks/new', fn() => (new WebhookAdminController())->create());
$router->post('/admin/webhooks', fn() => (new WebhookAdminController())->store());
$router->get('/admin/webhooks/{id}', fn($p) => (new WebhookAdminController())->show($p));
$router->post('/admin/webhooks/{id}', fn($p) => (new WebhookAdminController())->update($p));
$router->post('/admin/webhooks/{id}/toggle', fn($p) => (new WebhookAdminController())->toggle($p));
$router->post('/admin/webhooks/{id}/rotate-secret', fn($p) => (new WebhookAdminController())->rotateSecret($p));
$router->post('/admin/webhooks/{id}/test', fn($p) => (new WebhookAdminController())->test($p));
$router->post('/admin/webhooks/{id}/delete', fn($p) => (new WebhookAdminController())->delete($p));
$router->get('/admin/webhooks/{id}/deliveries', fn($p) => (new WebhookAdminController())->deliveries($p));
$router->post('/admin/webhooks/deliveries/{id}/retry', fn($p) => (new WebhookAdminController())->retry($p));

// --- REST API v1 ---
// Контролер API автентифікує запит у конструкторі, тому створюється лише всередині обробника конкретного маршруту.
$router->get('/api/v1/me', fn() => (new MeApiController())->show());
$router->get('/api/v1/ticket-queues', fn() => (new LookupsApiController())->queues());
$router->get('/api/v1/task-statuses', fn() => (new LookupsApiController())->taskStatuses());
$router->get('/api/v1/task-types', fn() => (new LookupsApiController())->taskTypes());
$router->get('/api/v1/users', fn() => (new LookupsApiController())->users());

$router->get('/api/v1/projects', fn() => (new ProjectsApiController())->index());
$router->get('/api/v1/projects/{id}', fn($p) => (new ProjectsApiController())->show($p));
$router->get('/api/v1/projects/{id}/tasks', fn($p) => (new ProjectsApiController())->tasks($p));
$router->get('/api/v1/projects/{id}/members', fn($p) => (new ProjectsApiController())->members($p));
$router->post('/api/v1/projects/{id}/tasks', fn($p) => (new TasksApiController())->store($p));

$router->get('/api/v1/tasks', fn() => (new TasksApiController())->index());
$router->get('/api/v1/tasks/{id}', fn($p) => (new TasksApiController())->show($p));
$router->patch('/api/v1/tasks/{id}', fn($p) => (new TasksApiController())->update($p));
$router->post('/api/v1/tasks/{id}/comments', fn($p) => (new TasksApiController())->addComment($p));

$router->get('/api/v1/tickets', fn() => (new TicketsApiController())->index());
$router->post('/api/v1/tickets', fn() => (new TicketsApiController())->store());
$router->get('/api/v1/tickets/{id}', fn($p) => (new TicketsApiController())->show($p));
$router->patch('/api/v1/tickets/{id}', fn($p) => (new TicketsApiController())->update($p));
$router->post('/api/v1/tickets/{id}/comments', fn($p) => (new TicketsApiController())->addComment($p));

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
