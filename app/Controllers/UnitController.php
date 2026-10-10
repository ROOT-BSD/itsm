<?php

namespace App\Controllers;

use App\Core\Access;
use App\Core\Auth;
use App\Core\LdapDn;
use App\Core\Unit;
use App\Core\View;
use App\Models\Audit;
use App\Models\User;

/**
 * «Мій підрозділ» — керування користувачами власного AD OU для ролі «Адміністратор Підрозділу».
 *
 * Межі, які не обговорюються:
 *  - лише користувачі СВОГО підрозділу — і AD (за OU із синхронізації), і локальні (за підрозділом, який задав адміністратор
 *    системи); чужий або неіснуючий id однаково дає 404 — не підказуємо, що він існує;
 *  - не себе, не «Адміністратора системи» і не іншого адміністратора підрозділу;
 *  - роль можна призначити будь-яку, крім «Адміністратора системи» й «Адміністратора Підрозділу» (інакше —
 *    підвищення привілеїв); ці дві ролі видає лише адміністратор системи;
 *  - для AD-користувачів зміни закріплюються прапорцями міграції 027 (роль / деактивація), інакше наступна синхронізація
 *    з AD їх скасувала б; без міграції такі зміни відхиляються з поясненням. Локальних синхронізація не чіпає —
 *    для них прапорці не потрібні, а пароль адміністратор підрозділу може скинути (AD-паролями керує AD).
 */
class UnitController
{
    /** Ролі, яких адміністратор підрозділу не призначає й не чіпає. */
    private const PROTECTED_ROLES = ['admin', Unit::ROLE];

    public function __construct()
    {
        Auth::requireLogin();
        if (!Access::isUnitAdmin(Auth::role())) {
            http_response_code(403);
            echo 'Ця сторінка доступна лише ролі «Адміністратор Підрозділу».';
            exit;
        }
    }

    /** Сторінка-розділ («Керування» чи «Налаштування системи») з картками — як /admin/manage і /admin/settings. */
    public function hub(string $section): void
    {
        $ou = Unit::scopeOu((int) Auth::id());
        View::render('admin/hub', [
            'section' => $section,
            'navClass' => \App\Core\UnitNav::class,
            'unitLabel' => $ou !== null ? LdapDn::ouLabel($ou) : '',
        ]);
    }

    /** Проєкти підрозділу: зміна статусу й видалення (видалення — лише проєктів, створених вами, або де всі причетні люди з підрозділу). */
    public function projects(): void
    {
        $id = (int) Auth::id();
        $projects = \App\Models\Project::allVisibleTo($id, false);
        foreach ($projects as &$p) {
            $p['can_delete'] = \App\Models\Project::unitAdminCanDelete($p, $id);
        }
        unset($p);
        View::render('admin/projects', [
            'unitMode' => true,
            'basePath' => '/unit/projects',
            'projects' => $projects,
            'overdueProjectIds' => \App\Models\Project::overdueMilestoneProjectIds(),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function updateProjectStatus(array $params): void
    {
        $project = $this->projectOr404($params);
        $status = $_POST['status'] ?? '';
        if (!in_array($status, ['active', 'archived', 'closed'], true)) {
            $this->toProjects('error', 'Некоректне значення статусу');
        }
        \App\Models\Project::updateStatus((int) $project['id'], $status, (int) Auth::id());
        $this->toProjects('success', 'Статус проєкту "' . $project['name'] . '" оновлено');
    }

    public function deleteProject(array $params): void
    {
        $project = $this->projectOr404($params);
        if (!\App\Models\Project::unitAdminCanDelete($project, (int) Auth::id())) {
            $this->toProjects('error', 'У проєкті «' . $project['name'] . '» є учасники з інших підрозділів, і створено його не вами — видалити його може лише адміністратор системи.');
        }
        if (trim((string) ($_POST['confirm_name'] ?? '')) !== $project['name']) {
            $this->toProjects('error', 'Назву проєкту введено невірно — видалення скасовано');
        }
        \App\Models\Project::delete((int) $project['id'], (int) Auth::id());
        $this->toProjects('success', 'Проєкт "' . $project['name'] . '" та всі повʼязані дані видалено');
    }

    /** Проєкт, який бачить адміністратор підрозділу; інакше 404 (чужий і неіснуючий не відрізняються). */
    private function projectOr404(array $params): array
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $project = $id > 0 ? \App\Models\Project::find($id) : null;
        if (!$project || !\App\Models\Project::isVisibleTo($project, (int) Auth::id(), false)) {
            http_response_code(404);
            echo 'Проєкт не знайдено у вашому підрозділі.';
            exit;
        }
        return $project;
    }

    private function toProjects(string $key, string $message): never
    {
        header('Location: /unit/projects?' . $key . '=' . urlencode($message));
        exit;
    }

    public function index(): void
    {
        $ou = Unit::scopeOu((int) Auth::id());

        $roles = array_values(array_filter(
            User::roles(),
            fn(array $r): bool => !in_array($r['code'], self::PROTECTED_ROLES, true)
        ));

        View::render('unit/index', [
            'ou' => $ou,
            'ouLabel' => $ou !== null ? LdapDn::ouLabel($ou) : '',
            'users' => $ou !== null ? Unit::users((int) Auth::id()) : [],
            'unitReady' => Unit::localReady(),
            'roles' => $roles,
            'protectedRoles' => self::PROTECTED_ROLES,
            'overridesReady' => User::overridesReady(),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    /** Канбан-дошка задач усіх проєктів, що стосуються підрозділу. */
    public function board(): void
    {
        $tasks = \App\Models\Task::allWithProject((int) Auth::id());
        $statuses = \App\Models\Task::statuses();
        $byStatus = [];
        foreach ($statuses as $st) {
            $byStatus[$st['id']] = [];
        }
        foreach ($tasks as $t) {
            $byStatus[$t['status_id']][] = $t;
        }
        View::render('admin/board', ['unitMode' => true, 'statuses' => $statuses, 'tasksByStatus' => $byStatus]);
    }

    /** Діаграма Ганта задач усіх проєктів, що стосуються підрозділу. */
    public function gantt(): void
    {
        $id = (int) Auth::id();
        View::render('admin/gantt', [
            'unitMode' => true,
            'tasks' => \App\Models\Task::allWithProject($id),
            'relations' => \App\Models\Task::allRelations($id),
        ]);
    }

    /** Облік часу по всіх проєктах, що стосуються підрозділу. */
    public function time(): void
    {
        $id = (int) Auth::id();
        $logs = \App\Models\Task::timeLogsAll($id);
        View::render('admin/time', [
            'unitMode' => true,
            'timeLogs' => $logs,
            'hoursByUser' => \App\Models\Task::hoursByUserAll($id),
            'hoursByProject' => \App\Models\Task::hoursByProjectAll($id),
            'hoursByDay' => \App\Models\Task::hoursByPeriodAll('day', $id),
            'hoursByWeek' => \App\Models\Task::hoursByPeriodAll('week', $id),
            'hoursByMonth' => \App\Models\Task::hoursByPeriodAll('month', $id),
            'hoursByDayUser' => \App\Models\Task::hoursByPeriodAndUserAll('day', $id),
            'hoursByWeekUser' => \App\Models\Task::hoursByPeriodAndUserAll('week', $id),
            'hoursByMonthUser' => \App\Models\Task::hoursByPeriodAndUserAll('month', $id),
            'hoursByDayProject' => \App\Models\Task::hoursByPeriodAndProjectAll('day', $id),
            'hoursByWeekProject' => \App\Models\Task::hoursByPeriodAndProjectAll('week', $id),
            'hoursByMonthProject' => \App\Models\Task::hoursByPeriodAndProjectAll('month', $id),
            'totalHours' => array_sum(array_column($logs, 'hours')),
        ]);
    }

    /** Тікети підрозділу в розрізі черг (лише читання: налаштування черг і SLA — глобальні). */
    public function queues(): void
    {
        $ou = Unit::scopeOu((int) Auth::id());
        View::render('unit/queues', [
            'ou' => $ou,
            'ouLabel' => $ou !== null ? LdapDn::ouLabel($ou) : '',
            'queues' => $ou !== null ? \App\Models\Ticket::queuesForUnit((int) Auth::id()) : [],
        ]);
    }

    /** CSAT за тікетами підрозділу — ті самі показники, що в адмін-звіті, але лише для тікетів підрозділу. */
    public function csat(): void
    {
        $id = (int) Auth::id();
        View::render('admin/csat', [
            'unitMode' => true,
            'summary' => \App\Models\Ticket::csatSummary($id),
            'byQueue' => \App\Models\Ticket::csatByQueue($id),
            'byOperator' => \App\Models\Ticket::csatByOperator($id),
            'recent' => \App\Models\Ticket::recentCsatRatings(50, $id),
        ]);
    }

    /** Журнал аудиту по користувачах підрозділу: їхні дії та події над їхніми обліковими записами. */
    public function audit(): void
    {
        $id = (int) Auth::id();
        $filters = [
            'unit_admin_id' => $id,
            'entity_type' => $_GET['entity_type'] ?? '',
            'user_id' => !empty($_GET['user_id']) ? (int) $_GET['user_id'] : null,
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
        ];
        $perPage = 50;
        $total = Audit::countFiltered($filters);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
        $entries = Audit::filtered($filters, $perPage, ($page - 1) * $perPage);

        $self = User::findById($id);
        $users = array_merge($self ? [$self] : [], Unit::users($id));

        View::render('admin/audit', [
            'unitMode' => true,
            'entries' => $entries,
            'entityNames' => Audit::entityNamesFor($entries),
            'referencedNames' => Audit::referencedNamesFor($entries),
            'entityTypes' => Audit::distinctEntityTypes(),
            'users' => $users,
            'filters' => $filters,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    public function setRole(array $params): void
    {
        $target = $this->targetOr404($params);
        $roleId = (int) ($_POST['role_id'] ?? 0);

        $role = null;
        foreach (User::roles() as $r) {
            if ((int) $r['id'] === $roleId) {
                $role = $r;
            }
        }
        if ($role === null || in_array($role['code'], self::PROTECTED_ROLES, true)) {
            $this->back('error', 'Цю роль призначає лише адміністратор системи.');
        }
        $isAd = $target['auth_source'] === 'ad';
        if ($isAd && !User::overridesReady()) {
            $this->back('error', 'Зміну ролі не можна закріпити, доки адміністратор системи не виконає update.sh (міграція 027): наступна синхронізація з AD скасувала б її.');
        }
        if ((int) $target['role_id'] === $roleId) {
            $this->back('success', 'Роль не змінилась.');
        }

        User::setRole((int) $target['id'], $roleId);
        if ($isAd) {
            User::setAdRoleLocked((int) $target['id'], true);
        }
        Audit::log('user', (int) $target['id'], 'role_changed_by_unit_admin', Auth::id(), [
            'old' => $target['role_name'],
            'new' => $role['name'],
        ] + ($isAd ? ['ad_role_locked' => 1] : []));
        $this->back('success', 'Роль користувача «' . $target['full_name'] . '» змінено на «' . $role['name'] . '»' . ($isAd ? ' і закріплено (синхронізація з AD її не змінює).' : '.'));
    }

    public function toggleActive(array $params): void
    {
        $target = $this->targetOr404($params);
        $active = ($_POST['active'] ?? '1') === '1';

        $isAd = $target['auth_source'] === 'ad';
        if ($isAd && !User::overridesReady()) {
            $this->back('error', 'Деактивацію не можна закріпити, доки адміністратор системи не виконає update.sh (міграція 027): наступна синхронізація з AD ввімкнула б користувача знову.');
        }

        User::setActive((int) $target['id'], $active);
        if ($isAd) {
            User::setAdBlocked((int) $target['id'], !$active);
        }
        Audit::log('user', (int) $target['id'], $active ? 'activated_by_unit_admin' : 'deactivated_by_unit_admin', Auth::id());
        $this->back('success', $active
            ? 'Обліковий запис активовано.'
            : 'Обліковий запис деактивовано' . ($isAd ? ' (синхронізація з AD його не ввімкне).' : '.'));
    }

    /** Скидання пароля ЛОКАЛЬНОГО користувача підрозділу (паролі AD-користувачів веде AD). */
    public function setPassword(array $params): void
    {
        $target = $this->targetOr404($params);
        if ($target['auth_source'] !== 'local') {
            $this->back('error', 'Пароль користувача AD змінюється в Active Directory.');
        }
        $new = (string) ($_POST['new_password'] ?? '');
        if (strlen($new) < 8) {
            $this->back('error', 'Пароль має містити щонайменше 8 символів.');
        }
        if ($new !== (string) ($_POST['confirm_password'] ?? '')) {
            $this->back('error', 'Паролі не збігаються.');
        }
        User::updatePassword((int) $target['id'], $new);
        Audit::log('user', (int) $target['id'], 'password_changed_by_unit_admin', Auth::id());
        $this->back('success', 'Пароль користувача «' . $target['full_name'] . '» змінено.');
    }

    public function unlock(array $params): void
    {
        $target = $this->targetOr404($params);
        User::resetFailedLogins((int) $target['id']);
        Audit::log('user', (int) $target['id'], 'unlocked_by_unit_admin', Auth::id());
        $this->back('success', 'Блокування знято.');
    }

    /** Користувач підрозділу, якого дозволено чіпати; інакше 404 (чужий, неіснуючий, сам адміністратор, захищена роль). */
    private function targetOr404(array $params): array
    {
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $user = $id > 0 ? User::findById($id) : null;

        if (!$user
            || $id === (int) Auth::id()
            || in_array($user['role_code'] ?? '', self::PROTECTED_ROLES, true)
            || !Unit::containsUser((int) Auth::id(), $id)) {
            http_response_code(404);
            echo 'Користувача не знайдено у вашому підрозділі.';
            exit;
        }
        return $user;
    }

    private function back(string $key, string $message): never
    {
        header('Location: /unit/users?' . $key . '=' . urlencode($message));
        exit;
    }
}
