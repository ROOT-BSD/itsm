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
$router->get('/sso/login', [$auth, 'sso']);
$router->get('/logout', [$auth, 'logout']);

// --- Портал самообслуговування (публічно, без входу в систему) ---
$support = new SupportController();
$router->get('/support', [$support, 'showForm']);
$router->post('/support', [$support, 'store']);
$router->get('/support/kb', [$support, 'kbIndex']);
$router->get('/support/kb/suggest', [$support, 'kbSuggest']);
$router->get('/support/kb/files/{id}', [$support, 'kbFile']);
$router->get('/support/kb/{slug}', [$support, 'kbShow']);
$router->get('/support/track/{token}', [$support, 'track']);
$router->get('/support/track/{token}/attachments/{id}', [$support, 'attachment']);
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
$router->get('/reports/schedules', fn() => (new \App\Controllers\ReportScheduleController())->index());
$router->post('/reports/schedules', fn() => (new \App\Controllers\ReportScheduleController())->create());
$router->get('/reports/schedules/{id}/edit', fn($p) => (new \App\Controllers\ReportScheduleController())->edit($p));
$router->post('/reports/schedules/{id}', fn($p) => (new \App\Controllers\ReportScheduleController())->update($p));
$router->post('/reports/schedules/{id}/toggle', fn($p) => (new \App\Controllers\ReportScheduleController())->toggle($p));
$router->post('/reports/schedules/{id}/delete', fn($p) => (new \App\Controllers\ReportScheduleController())->delete($p));
$router->post('/reports/schedules/{id}/run', fn($p) => (new \App\Controllers\ReportScheduleController())->run($p));

// --- Особистий кабінет ---
$profile = new ProfileController();
$router->get('/profile', [$profile, 'index']);
$router->post('/profile/password', [$profile, 'updatePassword']);
$sessions = new \App\Controllers\SessionController();
$router->get('/profile/menu', [$profile, 'menu']);
$router->post('/profile/menu', [$profile, 'saveMenu']);
$router->get('/profile/sessions', [$sessions, 'mine']);
$router->post('/profile/sessions/revoke-others', [$sessions, 'revokeOthers']);
$router->post('/profile/sessions/{id}/revoke', fn($p) => $sessions->revokeMine($p));
$router->get('/admin/sessions', [$sessions, 'adminIndex']);
$router->post('/admin/sessions/{id}/revoke', fn($p) => $sessions->adminRevoke($p));
$router->post('/admin/sessions/user/{id}/revoke', fn($p) => $sessions->adminRevokeUser($p));
$router->get('/admin/login-log', [$sessions, 'adminLog']);
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
$router->post('/projects/{id}/leave', fn($p) => $projects->leave($p));
$router->post('/projects/{id}/members/{userId}/delete', fn($p) => $projects->removeMember($p));

// --- Задачі ---
$tasks = new TaskController();
$router->get('/tasks', fn() => $tasks->indexOpen());
$router->get('/tasks/export', fn() => (new \App\Controllers\ExportController())->tasks());
$router->get('/projects/{project_id}/tasks/create', fn($p) => $tasks->showCreateForm($p));
$router->post('/projects/{project_id}/tasks', fn($p) => $tasks->store($p));
$router->get('/tasks/{id}', fn($p) => $tasks->show($p));
$router->post('/tasks/{id}/status', fn($p) => $tasks->updateStatus($p));
$router->post('/tasks/{id}/dates', fn($p) => $tasks->updateDates($p));
$router->post('/tasks/{id}/schedule', fn($p) => $tasks->updateSchedule($p));
$router->post('/tasks/{id}/milestone', fn($p) => $tasks->updateMilestone($p));
$router->post('/tasks/{id}/assignee', fn($p) => $tasks->updateAssignee($p));
$router->post('/tasks/{id}/comments', fn($p) => $tasks->addComment($p));
$router->post('/tasks/{id}/journal/preview', fn($p) => $tasks->previewEntry($p));
$router->post('/tasks/{id}/journal/{eid}', fn($p) => $tasks->updateEntry($p));
$router->post('/tasks/{id}/journal/{eid}/delete', fn($p) => $tasks->deleteEntry($p));
$router->post('/tasks/{id}/time', fn($p) => $tasks->logTime($p));
$router->post('/tasks/{id}/title', fn($p) => $tasks->updateTitle($p));
$router->post('/tasks/{id}/description', fn($p) => $tasks->updateDescription($p));
$router->post('/tasks/{id}/project', fn($p) => $tasks->updateProject($p));
$router->post('/tasks/{id}/delete', fn($p) => $tasks->delete($p));
$templates = new \App\Controllers\TemplateController();
$router->get('/templates', fn() => $templates->index());
$router->get('/templates/new', fn() => $templates->showNew());
$router->post('/templates', fn() => $templates->store());
$router->get('/templates/{id}', fn($p) => $templates->show($p));
$router->post('/templates/{id}', fn($p) => $templates->update($p));
$router->post('/templates/{id}/delete', fn($p) => $templates->delete($p));
$router->post('/projects/{id}/apply-template', fn($p) => $templates->apply($p));
$router->post('/projects/{id}/save-template', fn($p) => $templates->saveFromProject($p));
$router->post('/tasks/{id}/save-template', fn($p) => $templates->saveFromTask($p));
$router->post('/tasks/{id}/subtasks', fn($p) => $tasks->storeSubtask($p));
$router->post('/tasks/{id}/detach', fn($p) => $tasks->detachSubtask($p));
$router->post('/tasks/{id}/checklist', fn($p) => $tasks->addChecklist($p));
$router->post('/tasks/{id}/checklist/{item}/toggle', fn($p) => $tasks->toggleChecklist($p));
$router->post('/tasks/{id}/checklist/{item}/delete', fn($p) => $tasks->deleteChecklist($p));

// --- Тікети (Service Desk, базова версія — Епік 12) ---
$tickets = new TicketController();
$router->get('/tickets', fn() => $tickets->index());
$router->get('/tickets/export', fn() => (new \App\Controllers\ExportController())->tickets());
$router->get('/tickets/create', fn() => $tickets->showCreateForm());
$router->post('/tickets', fn() => $tickets->store());
$router->get('/tickets/{id}', fn($p) => $tickets->show($p));

// --- Архів (закриті тікети/задачі/проєкти) ---
$router->get('/it-assets', fn() => (new \App\Controllers\AssetController())->index());
$router->get('/it-assets/create', fn() => (new \App\Controllers\AssetController())->form());
$router->get('/it-assets/licenses', fn() => (new \App\Controllers\AssetController())->licenses());
$router->get('/it-assets/export', fn() => (new \App\Controllers\AssetController())->export());
$router->get('/it-assets/template', fn() => (new \App\Controllers\AssetController())->template());
$router->get('/it-assets/template-software', fn() => (new \App\Controllers\AssetController())->templateSoftware());
$router->get('/it-assets/import', fn() => (new \App\Controllers\AssetController())->importForm());
$router->post('/it-assets/import', fn() => (new \App\Controllers\AssetController())->import());
$router->post('/it-assets', fn() => (new \App\Controllers\AssetController())->save());
$router->get('/it-assets/{id}', fn($p) => (new \App\Controllers\AssetController())->show($p));
$router->get('/it-assets/{id}/edit', fn($p) => (new \App\Controllers\AssetController())->form($p));
$router->post('/it-assets/{id}', fn($p) => (new \App\Controllers\AssetController())->save($p));
$router->post('/it-assets/{id}/delete', fn($p) => (new \App\Controllers\AssetController())->delete($p));
$router->post('/it-assets/{id}/links', fn($p) => (new \App\Controllers\AssetController())->addLink($p));
$router->post('/it-assets/{id}/links/{link}/delete', fn($p) => (new \App\Controllers\AssetController())->removeLink($p));
$router->post('/it-assets/{id}/tickets', fn($p) => (new \App\Controllers\AssetController())->addTicket($p));
$router->post('/it-assets/{id}/tickets/{ticket}/delete', fn($p) => (new \App\Controllers\AssetController())->removeTicket($p));
$router->post('/tickets/{id}/assets', fn($p) => (new \App\Controllers\AssetController())->ticketLink($p));
$router->post('/tickets/{id}/assets/{asset}/delete', fn($p) => (new \App\Controllers\AssetController())->ticketUnlink($p));
$router->get('/archive', fn() => (new ArchiveController())->index());
$router->post('/tickets/{id}/operator', fn($p) => $tickets->assignOperator($p));
$router->post('/tickets/{id}/status', fn($p) => $tickets->updateStatus($p));
$router->post('/tickets/{id}/links', fn($p) => $tickets->addLink($p));
$router->post('/tickets/{id}/links/{link}/delete', fn($p) => $tickets->removeLink($p));
$router->post('/tickets/{id}/time', fn($p) => $tickets->addTime($p));
$router->post('/tickets/{id}/time/{log}/delete', fn($p) => $tickets->deleteTime($p));
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
$router->get('/library/versions/{id}/preview', fn($p) => $library->previewVersion($p));
$router->post('/library/versions/{id}/delete', fn($p) => $library->deleteVersion($p));
$router->get('/library/{id}', fn($p) => $library->show($p));
$router->get('/library/{id}/edit', fn($p) => $library->edit($p));
$router->post('/library/{id}', fn($p) => $library->update($p));
$router->post('/library/{id}/versions', fn($p) => $library->addVersion($p));
$router->post('/library/{id}/delete', fn($p) => $library->delete($p));
$router->get('/library/{id}/download', fn($p) => $library->download($p));
$router->get('/library/{id}/preview', fn($p) => $library->preview($p));

// Форум. Статичні адреси («boards/new», «search») — ПЕРЕД адресами з {id}.
$forum = new ForumController();
$router->get('/search', fn() => (new \App\Controllers\SearchController())->index());
$router->get('/forum', fn() => $forum->index());
$router->post('/forum/preview', fn() => $forum->preview());
$router->get('/forum/files/{id}', fn($p) => $forum->file($p));
$router->post('/forum/read-all', fn() => $forum->readAll());
$router->get('/forum/search', fn() => $forum->search());
$router->get('/forum/boards/new', fn() => $forum->newBoard());
$router->post('/forum/boards', fn() => $forum->storeBoard());
$router->get('/forum/boards/{id}', fn($p) => $forum->board($p));
$router->get('/forum/boards/{id}/edit', fn($p) => $forum->editBoard($p));
$router->post('/forum/boards/{id}', fn($p) => $forum->updateBoard($p));
$router->post('/forum/boards/{id}/delete', fn($p) => $forum->deleteBoard($p));
$router->post('/forum/boards/{id}/subscribe', fn($p) => $forum->subscribe($p));
$router->post('/forum/boards/{id}/unsubscribe', fn($p) => $forum->unsubscribe($p));
$router->get('/forum/boards/{id}/topics/new', fn($p) => $forum->newTopic($p));
$router->post('/forum/boards/{id}/topics', fn($p) => $forum->storeTopic($p));
$router->get('/forum/topics/{id}', fn($p) => $forum->topic($p));
$router->get('/forum/topics/{id}/mute', fn($p) => $forum->mutePage($p));
$router->post('/forum/topics/{id}/mute', fn($p) => $forum->mute($p));
$router->post('/forum/topics/{id}/unmute', fn($p) => $forum->unmute($p));
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
$router->get('/admin/backup', fn() => (new \App\Controllers\BackupController())->index());
$router->post('/admin/backup', fn() => (new \App\Controllers\BackupController())->create());
$router->post('/admin/backup/settings', fn() => (new \App\Controllers\BackupController())->settings());
$router->post('/admin/backup/restore', fn() => (new \App\Controllers\BackupController())->restore());
$router->get('/admin/backup/{stamp}/download', fn($p) => (new \App\Controllers\BackupController())->download($p));
$router->post('/admin/backup/{stamp}/verify', fn($p) => (new \App\Controllers\BackupController())->verify($p));
$router->post('/admin/backup/{stamp}/delete', fn($p) => (new \App\Controllers\BackupController())->delete($p));
$router->get('/admin/status', fn() => (new AdminController())->status());
$router->get('/admin/audit/export', fn() => (new \App\Controllers\ExportController())->audit());
$router->get('/admin/audit', function () {
    (new AuditController())->index();
});
$router->get('/admin/tickets-report', fn() => (new AdminController())->ticketsReport());
$router->get('/admin/tickets-report/export', fn() => (new \App\Controllers\ExportController())->ticketsReport());
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
// --- Адміністратор підрозділу (власний AD OU) ---
$router->get('/unit', function () {
    header('Location: /unit/manage');
    exit;
});
$router->get('/unit/manage', function () {
    (new \App\Controllers\UnitController())->hub('manage');
});
$router->get('/unit/settings', function () {
    (new \App\Controllers\UnitController())->hub('settings');
});
$router->get('/unit/users', function () {
    (new \App\Controllers\UnitController())->index();
});
$router->get('/unit/projects', function () {
    (new \App\Controllers\UnitController())->projects();
});
$router->post('/unit/projects/{id}/status', function ($p) {
    (new \App\Controllers\UnitController())->updateProjectStatus($p);
});
$router->post('/unit/projects/{id}/delete', function ($p) {
    (new \App\Controllers\UnitController())->deleteProject($p);
});
$router->get('/unit/board', function () {
    (new \App\Controllers\UnitController())->board();
});
$router->get('/unit/gantt', function () {
    (new \App\Controllers\UnitController())->gantt();
});
$router->get('/unit/time', function () {
    (new \App\Controllers\UnitController())->time();
});
$router->get('/unit/queues', function () {
    (new \App\Controllers\UnitController())->queues();
});
$router->get('/unit/tickets-report', fn() => (new \App\Controllers\UnitController())->ticketsReport());
$router->get('/unit/tickets-report/export', fn() => (new \App\Controllers\ExportController())->unitTicketsReport());
$router->get('/unit/csat', function () {
    (new \App\Controllers\UnitController())->csat();
});
$router->get('/unit/audit/export', fn() => (new \App\Controllers\ExportController())->unitAudit());
$router->get('/unit/audit', function () {
    (new \App\Controllers\UnitController())->audit();
});
$router->post('/unit/users/{id}/role', function ($p) {
    (new \App\Controllers\UnitController())->setRole($p);
});
$router->post('/unit/users/{id}/active', function ($p) {
    (new \App\Controllers\UnitController())->toggleActive($p);
});
$router->post('/unit/users/{id}/password', function ($p) {
    (new \App\Controllers\UnitController())->setPassword($p);
});
$router->post('/unit/users/{id}/unlock', function ($p) {
    (new \App\Controllers\UnitController())->unlock($p);
});
$router->get('/admin/ticket-rules', fn() => (new \App\Controllers\TicketRuleController(false))->index());
$router->post('/admin/ticket-rules', fn() => (new \App\Controllers\TicketRuleController(false))->save());
$router->post('/admin/ticket-rules/test', fn() => (new \App\Controllers\TicketRuleController(false))->test());
$router->post('/admin/ticket-rules/apply-existing', fn() => (new \App\Controllers\TicketRuleController(false))->applyExisting());
$router->post('/admin/ticket-rules/{id}/toggle', fn($p) => (new \App\Controllers\TicketRuleController(false))->toggle($p));
$router->post('/admin/ticket-rules/{id}/delete', fn($p) => (new \App\Controllers\TicketRuleController(false))->delete($p));
$router->get('/ticket-rules', fn() => (new \App\Controllers\TicketRuleController(false, true))->index());
$router->get('/unit/ticket-rules', fn() => (new \App\Controllers\TicketRuleController(true))->index());
$router->post('/unit/ticket-rules', fn() => (new \App\Controllers\TicketRuleController(true))->save());
$router->post('/unit/ticket-rules/{id}/toggle', fn($p) => (new \App\Controllers\TicketRuleController(true))->toggle($p));
$router->post('/unit/ticket-rules/{id}/delete', fn($p) => (new \App\Controllers\TicketRuleController(true))->delete($p));
$router->get('/admin/queues', function () {
    (new AdminController())->queues();
});
$router->get('/admin/queues/create', function () {
    (new AdminController())->showCreateQueueForm();
});
$router->post('/admin/queues', function () {
    (new AdminController())->storeQueue();
});
$router->get('/admin/queues/{id}/edit', function ($p) {
    (new AdminController())->showEditQueueForm($p);
});
$router->post('/admin/queues/{id}/edit', function ($p) {
    (new AdminController())->updateQueue($p);
});
$router->post('/admin/queues/{id}/delete', function ($p) {
    (new AdminController())->deleteQueue($p);
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
