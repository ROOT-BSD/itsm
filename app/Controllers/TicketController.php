<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;

class TicketController
{
    /** Допустимі значення статусу тікета (ENUM у БД). */
    private const STATUSES = \App\Models\Ticket::STATUSES;

    /** Оператори служби підтримки й керівники ІТ-підрозділу додатково бачать непризначені тікети — щоб було що брати в роботу. */
    private function canSeeUnassigned(): bool
    {
        return \App\Core\Access::canSeeUnassignedTickets(Auth::role());
    }

    public function index(): void
    {
        Auth::requireLogin();

        $tickets = Ticket::allOpenVisibleTo(Auth::id(), Auth::hasRole(['admin']), $this->canSeeUnassigned());
        // Фільтр за чергою (з сторінки «Мій підрозділ → Черги тікетів»); видимість тікетів він не розширює.
        if (!empty($_GET['queue'])) {
            $queueId = (int) $_GET['queue'];
            $tickets = array_values(array_filter($tickets, fn(array $t): bool => (int) $t['queue_id'] === $queueId));
        }
        // Фільтри за пріоритетом, міткою, статусом (не розширюють видимість).
        $fPrio = isset(Ticket::PRIORITIES[$_GET['priority'] ?? '']) ? $_GET['priority'] : '';
        $fStatus = in_array($_GET['status'] ?? '', Ticket::STATUSES, true) ? $_GET['status'] : '';
        $fLabel = (int) ($_GET['label'] ?? 0);
        // Мітки — внутрішні, заявники їх не бачать.
        $labelMap = Auth::hasRole(['admin', 'unit_admin', 'it_manager', 'sysadmin', 'support_operator', 'observer']) ? Ticket::labelsForTickets(array_map(fn($t) => (int) $t['id'], $tickets)) : [];
        $tickets = array_values(array_filter($tickets, function (array $t) use ($fPrio, $fStatus, $fLabel, $labelMap): bool {
            if ($fPrio !== '' && ($t['priority'] ?? 'normal') !== $fPrio) {
                return false;
            }
            if ($fStatus !== '' && $t['status'] !== $fStatus) {
                return false;
            }
            if ($fLabel > 0 && !in_array($fLabel, array_map('intval', array_column($labelMap[(int) $t['id']] ?? [], 'id')), true)) {
                return false;
            }
            return true;
        }));
        $policies = Ticket::slaPoliciesByQueue();

        foreach ($tickets as &$ticket) {
            $ticket['sla'] = Ticket::slaStatus($ticket, $policies);
        }
        unset($ticket);

        // Прострочені тікети — вгору списку, щоб їх одразу було видно (сенс ескалації).
        $rank = ['critical' => 3, 'high' => 2, 'normal' => 1, 'low' => 0];
        $byPriority = ($_GET['sort'] ?? '') === 'priority';
        usort($tickets, function ($a, $b) use ($rank, $byPriority) {
            $aOverdue = (int) ($a['sla']['response_overdue'] || $a['sla']['resolution_overdue']);
            $bOverdue = (int) ($b['sla']['response_overdue'] || $b['sla']['resolution_overdue']);
            $pa = $rank[$a['priority'] ?? 'normal'] ?? 1;
            $pb = $rank[$b['priority'] ?? 'normal'] ?? 1;
            // Звичайно: прострочені вгору; «за пріоритетом»: спершу пріоритет, потім прострочення. Далі — порядок за датою (usort стабільний).
            return $byPriority ? ($pb <=> $pa ?: $bOverdue <=> $aOverdue) : ($bOverdue <=> $aOverdue);
        });

        View::render('tickets/index', [
            'fPrio' => $fPrio, 'fStatus' => $fStatus, 'fLabel' => $fLabel, 'byPriority' => $byPriority,
            'allLabels' => $labelMap || Auth::hasRole(['admin', 'unit_admin', 'it_manager', 'sysadmin', 'support_operator', 'observer']) ? Ticket::labels() : [], 'labelMap' => $labelMap,
            'tickets' => $tickets,
        ]);
    }

    public function showCreateForm(): void
    {
        Auth::requireLogin();

        $preselectedProjectId = !empty($_GET['project_id']) ? (int) $_GET['project_id'] : null;
        if ($preselectedProjectId !== null) {
            $preselectedProject = Project::find($preselectedProjectId);
            if (!$preselectedProject || !Project::isVisibleTo($preselectedProject, Auth::id(), Auth::hasRole(['admin']))) {
                $preselectedProjectId = null;
            }
        }

        View::render('tickets/create', [
            'queues' => Ticket::queues(),
            'projects' => Project::allVisibleTo(Auth::id(), Auth::hasRole(['admin'])),
            'preselectedProjectId' => $preselectedProjectId,
            'error' => $_GET['error'] ?? null,
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();

        $queueId = (int) ($_POST['queue_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $projectId = !empty($_POST['project_id']) ? (int) $_POST['project_id'] : null;

        if ($queueId === 0 || $subject === '') {
            header('Location: /tickets/create?error=' . urlencode('Оберіть чергу і вкажіть тему звернення'));
            exit;
        }
        if (!$this->queueExists($queueId)) {
            header('Location: /tickets/create?error=' . urlencode('Оберіть коректну чергу'));
            exit;
        }
        if ($projectId !== null) {
            $project = Project::find($projectId);
            if (!$project || !Project::isVisibleTo($project, Auth::id(), Auth::hasRole(['admin']))) {
                header('Location: /tickets/create?error=' . urlencode('Проєкт не знайдено або немає до нього доступу'));
                exit;
            }
        }

        // Заявник — поточний користувач (окремий портал для співробітників без
        // облікового запису основного функціоналу — майбутній крок, п. 2.13 ТЗ)
        $me = User::findById(Auth::id());

        $id = Ticket::create($queueId, $me['full_name'], $me['email'], Auth::id(), $subject, $description ?: null, $projectId, 'web');

        // Зображення з форми (вибрані файли або вставлений скриншот). Тікет уже створено: частина файлів
        // може бути відхилена, і це не скасовує тікет — користувач побачить, що саме не прикріпилось.
        $images = \App\Services\AttachmentService::attachCreationUploads(
            'ticket', $id, $_FILES['files'] ?? [], Auth::id(), 'web',
            (int) \App\Core\Config::get('attachments.max_per_request', 10)
        );
        Ticket::applyCreationRules($id); // правила черги — вже з урахуванням вкладень
        $query = [];
        if ($images['added'] > 0) {
            $query['success'] = 'Звернення створено. Прикріплено зображень: ' . $images['added'] . '.';
        }
        if ($images['errors']) {
            $query['error'] = implode(' ', $images['errors']);
        }
        header('Location: /tickets/' . $id . ($query ? '?' . http_build_query($query) : ''));
        exit;
    }

    public function show(array $params): void
    {
        Auth::requireLogin();
        $ticket = Ticket::find((int) $params['id']);

        if (!$ticket) {
            http_response_code(404);
            echo 'Тікет не знайдено.';
            return;
        }
        if (!Ticket::isVisibleTo($ticket, Auth::id(), Auth::hasRole(['admin']), $this->canSeeUnassigned())) {
            http_response_code(403);
            echo 'Доступ до цього тікета обмежено — його бачать лише заявник, призначений оператор та адміністратор системи.';
            return;
        }

        View::render('tickets/show', [
            'ticket' => $ticket,
            'comments' => Ticket::comments($ticket['id']),
            'users' => User::allActive(),
            'sla' => Ticket::slaStatus($ticket, Ticket::slaPoliciesByQueue()),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
            'attachments' => \App\Models\Attachment::forOwner('ticket', (int) $ticket['id']),
            'kbSuggestions' => \App\Models\WikiPage::suggest((string) $ticket['subject'] . ' ' . mb_substr((string) $ticket['description'], 0, 500), Auth::role(), 4),
            'allLabels' => Ticket::labels(),
            'ticketLabels' => Ticket::labelsForTickets([(int) $ticket['id']])[(int) $ticket['id']] ?? [],
            'links' => $this->visibleLinks((int) $ticket['id']),
            'ticketAssets' => $this->visibleAssets((int) $ticket['id']),
            'canLinkAssets' => \App\Core\Access::canUseAssets(Auth::role()) && \App\Models\Asset::ready(),
            'timeLogs' => \App\Models\TicketTimeLog::forTicket((int) $ticket['id']),
            'canLogTime' => \App\Core\Access::canAssignTicketOperator(Auth::role()) && \App\Models\TicketTimeLog::ready(),
            'timeCategories' => \App\Models\Task::distinctCategoriesVisibleTo((int) Auth::id(), Auth::hasRole(['admin'])),
        ]);
    }

    /** Активи тікета, які бачить поточний користувач (реєстр доступний лише персоналу). */
    private function visibleAssets(int $ticketId): array
    {
        if (!\App\Core\Access::canUseAssets(Auth::role())) {
            return [];
        }
        $role = Auth::role();
        $uid = (int) Auth::id();
        return array_values(array_filter(\App\Models\Asset::assetsOfTicket($ticketId), fn($a) => \App\Models\Asset::isVisible($a, $role, $uid)));
    }

    /**
     * Зв'язки тікета; тікети, до яких у користувача немає доступу, не показуємо (лише рахуємо), щоб не розкривати їхню тему.
     *
     * @return array{original: ?array, duplicates: array, related: array, hidden: int}
     */
    private function visibleLinks(int $ticketId): array
    {
        $links = \App\Models\TicketLink::forTicket($ticketId);
        $hidden = 0;
        $isAdmin = Auth::hasRole(['admin']);
        $canUnassigned = $this->canSeeUnassigned();
        $keep = function (array $row) use (&$hidden, $isAdmin, $canUnassigned): bool {
            if (Ticket::isVisibleTo($row, Auth::id(), $isAdmin, $canUnassigned)) {
                return true;
            }
            $hidden++;
            return false;
        };
        $links['duplicates'] = array_values(array_filter($links['duplicates'], $keep));
        $links['related'] = array_values(array_filter($links['related'], $keep));
        if ($links['original'] !== null && !$keep($links['original'])) {
            $links['original'] = null;
        }
        $links['hidden'] = $hidden;
        return $links;
    }

    public function assignOperator(array $params): void
    {
        Auth::requireLogin();

        // Призначати виконавця тікета можуть лише персонал ІТ-підрозділу,
        // а не будь-який заявник — інакше рядовий користувач міг би
        // призначити (або зняти) оператора на чужому зверненні.
        if (!\App\Core\Access::canAssignTicketOperator(Auth::role())) {
            http_response_code(403);
            echo 'Призначати оператора тікета можуть лише керівник ІТ-підрозділу, адміністратор системи або оператор служби підтримки.';
            return;
        }

        $operatorId = !empty($_POST['operator_id']) ? (int) $_POST['operator_id'] : null;
        Ticket::assignOperator((int) $params['id'], $operatorId, Auth::id());
        header('Location: /tickets/' . $params['id']);
        exit;
    }

    public function setLabels(array $params): void
    {
        Auth::requireLogin();
        if (!\App\Core\Access::canAssignTicketOperator(Auth::role())) {
            http_response_code(403);
            echo 'Призначати мітки можуть лише керівник ІТ-підрозділу, адміністратор системи або оператор служби підтримки.';
            return;
        }
        $ticket = Ticket::find((int) $params['id']);
        if (!$ticket || !Ticket::isVisibleTo($ticket, Auth::id(), Auth::hasRole(['admin']), $this->canSeeUnassigned())) {
            http_response_code(404);
            echo 'Тікет не знайдено.';
            return;
        }
        Ticket::setLabels((int) $params['id'], array_map('intval', (array) ($_POST['labels'] ?? [])), (int) Auth::id());
        header('Location: /tickets/' . $params['id'] . '?success=' . urlencode('Мітки збережено'));
        exit;
    }

    public function setPriority(array $params): void
    {
        Auth::requireLogin();
        if (!\App\Core\Access::canAssignTicketOperator(Auth::role())) {
            http_response_code(403);
            echo 'Змінювати пріоритет тікета можуть лише керівник ІТ-підрозділу, адміністратор системи або оператор служби підтримки.';
            return;
        }
        $ticket = Ticket::find((int) $params['id']);
        if (!$ticket || !Ticket::isVisibleTo($ticket, Auth::id(), Auth::hasRole(['admin']), $this->canSeeUnassigned())) {
            http_response_code(404);
            echo 'Тікет не знайдено.';
            return;
        }
        $priority = (string) ($_POST['priority'] ?? '');
        if (!isset(Ticket::PRIORITIES[$priority])) {
            header('Location: /tickets/' . $params['id'] . '?error=' . urlencode('Некоректний пріоритет'));
            exit;
        }
        if (($ticket['priority'] ?? 'normal') !== $priority) {
            Ticket::setPriority((int) $params['id'], $priority, Auth::id());
        }
        header('Location: /tickets/' . $params['id'] . '?success=' . urlencode('Пріоритет змінено'));
        exit;
    }

    public function updateStatus(array $params): void
    {
        Auth::requireLogin();

        $ticket = Ticket::find((int) $params['id']);
        if (!$ticket) {
            http_response_code(404);
            echo 'Тікет не знайдено.';
            return;
        }
        if (!Ticket::isVisibleTo($ticket, Auth::id(), Auth::hasRole(['admin']), $this->canSeeUnassigned())) {
            http_response_code(403);
            echo 'Доступ до цього тікета обмежено.';
            return;
        }

        $status = $_POST['status'] ?? '';
        if (!in_array($status, self::STATUSES, true)) {
            header('Location: /tickets/' . $params['id'] . '?error=' . urlencode('Некоректний статус'));
            exit;
        }

        Ticket::updateStatus((int) $params['id'], $status, Auth::id());
        $timeNote = '';
        if (in_array($status, ['resolved', 'closed'], true) && $ticket['status'] !== $status) {
            $timeNote = $this->logMinutes($ticket, $_POST['spent_minutes'] ?? 0, 'Вирішення тікета', 'Витрачено при встановленні статусу «' . Ticket::statusLabel($status) . '»');
        }

        // «Застосувати до всіх дублів»: той самий статус отримують дублі цього тікета, які поточний користувач бачить.
        $suffix = '';
        if (!empty($_POST['with_duplicates']) && \App\Core\Access::canAssignTicketOperator(Auth::role())) {
            $n = 0;
            foreach (\App\Models\TicketLink::duplicateIds((int) $params['id']) as $dupId) {
                $dup = Ticket::find($dupId);
                if ($dup && Ticket::isVisibleTo($dup, Auth::id(), Auth::hasRole(['admin']), $this->canSeeUnassigned()) && $dup['status'] !== $status) {
                    Ticket::updateStatus($dupId, $status, Auth::id());
                    $n++;
                }
            }
            $suffix = '?success=' . urlencode('Статус застосовано і до дублів: ' . $n . '.' . $timeNote);
        } elseif ($timeNote !== '') {
            $suffix = '?success=' . urlencode('Статус оновлено.' . $timeNote);
        }
        header('Location: /tickets/' . $params['id'] . $suffix);
        exit;
    }

    /** Додати зв'язок «пов'язаний» / «дубль» між тікетами. Лише персонал; обидва тікети мають бути доступні користувачу. */
    public function addLink(array $params): void
    {
        Auth::requireLogin();
        $ticket = $this->findStaffTicketOrFail((int) $params['id']);
        $back = '/tickets/' . (int) $ticket['id'];

        $otherId = (int) ($_POST['other_id'] ?? 0);
        $kind = (string) ($_POST['kind'] ?? '');
        if (!in_array($kind, ['related', 'duplicate_of', 'has_duplicate'], true)) {
            $this->linkRedirect($back, 'error', 'Оберіть вид зв\'язку');
        }
        $other = $otherId > 0 ? Ticket::find($otherId) : null;
        if (!$other || !Ticket::isVisibleTo($other, Auth::id(), Auth::hasRole(['admin']), $this->canSeeUnassigned())) {
            $this->linkRedirect($back, 'error', 'Тікет №' . $otherId . ' не знайдено або немає доступу');
        }
        $error = \App\Models\TicketLink::add((int) $ticket['id'], $otherId, $kind, Auth::id());
        $this->linkRedirect($back, $error === null ? 'success' : 'error', $error ?? 'Зв\'язок додано');
    }

    public function removeLink(array $params): void
    {
        Auth::requireLogin();
        $ticket = $this->findStaffTicketOrFail((int) $params['id']);
        $link = \App\Models\TicketLink::find((int) ($params['link'] ?? 0));
        if (!$link || !in_array((int) $ticket['id'], [(int) $link['ticket_id'], (int) $link['linked_id']], true)) {
            $this->linkRedirect('/tickets/' . (int) $ticket['id'], 'error', 'Зв\'язок не знайдено');
        }
        \App\Models\TicketLink::remove($link, Auth::id());
        $this->linkRedirect('/tickets/' . (int) $ticket['id'], 'success', 'Зв\'язок видалено');
    }

    private function findStaffTicketOrFail(int $id, string $deniedMessage = 'Зв\'язувати тікети можуть лише персонал підтримки та адміністратори.'): array
    {
        if (!\App\Core\Access::canAssignTicketOperator(Auth::role())) {
            http_response_code(403);
            echo $deniedMessage;
            exit;
        }
        $ticket = Ticket::find($id);
        if (!$ticket) {
            http_response_code(404);
            echo 'Тікет не знайдено.';
            exit;
        }
        if (!Ticket::isVisibleTo($ticket, Auth::id(), Auth::hasRole(['admin']), $this->canSeeUnassigned())) {
            http_response_code(403);
            echo 'Доступ до цього тікета обмежено.';
            exit;
        }
        return $ticket;
    }

    private function linkRedirect(string $url, string $kind, string $message): never
    {
        header('Location: ' . $url . '?' . $kind . '=' . urlencode($message));
        exit;
    }

    /**
     * Записує витрачений час (хвилини) з форми відповіді / зміни статусу. Порожнє чи 0 — нічого не записується.
     * Лише для персоналу підтримки. @return string примітка для повідомлення ('' якщо нічого не записано)
     */
    private function logMinutes(array $ticket, mixed $raw, string $category, string $comment): string
    {
        if (!\App\Core\Access::canAssignTicketOperator(Auth::role()) || !\App\Models\TicketTimeLog::ready()) {
            return '';
        }
        $minutes = (int) round((float) str_replace(',', '.', trim((string) $raw)));
        if ($minutes <= 0) {
            return '';
        }
        $minutes = min($minutes, 1440);
        $id = \App\Models\TicketTimeLog::add((int) $ticket['id'], (int) Auth::id(), round($minutes / 60, 4), date('Y-m-d'), $category, $comment);
        \App\Models\Audit::log('ticket', (int) $ticket['id'], 'time_logged', (int) Auth::id(), ['log' => $id, 'minutes' => $minutes, 'date' => date('Y-m-d')]);
        return ' Записано часу: ' . $minutes . ' хв.';
    }

    /** Облік часу по тікету: запис додає персонал підтримки, який бачить тікет. */
    public function addTime(array $params): void
    {
        Auth::requireLogin();
        $ticket = $this->findStaffTicketOrFail((int) $params['id'], 'Облік часу по тікетах доступний лише персоналу підтримки та адміністраторам.');
        $url = '/tickets/' . (int) $ticket['id'];
        $hours = (float) str_replace(',', '.', (string) ($_POST['hours'] ?? ''));
        $date = (string) ($_POST['log_date'] ?? '');
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        if ($hours <= 0 || $hours > 24) {
            $this->linkRedirect($url, 'error', 'Вкажіть кількість годин від 0,01 до 24.');
        }
        if (!$d || $d->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            $this->linkRedirect($url, 'error', 'Вкажіть коректну дату (не з майбутнього).');
        }
        $category = mb_substr(trim((string) ($_POST['activity_category'] ?? '')), 0, 100);
        $comment = mb_substr(trim((string) ($_POST['comment'] ?? '')), 0, 255);
        $id = \App\Models\TicketTimeLog::add((int) $ticket['id'], (int) Auth::id(), round($hours, 2), $date, $category, $comment);
        \App\Models\Audit::log('ticket', (int) $ticket['id'], 'time_logged', (int) Auth::id(), ['log' => $id, 'hours' => round($hours, 2), 'date' => $date]);
        $this->linkRedirect($url, 'success', 'Час записано: ' . number_format($hours, 2, ',', '') . ' год.');
    }

    public function deleteTime(array $params): void
    {
        Auth::requireLogin();
        $ticket = $this->findStaffTicketOrFail((int) $params['id'], 'Облік часу по тікетах доступний лише персоналу підтримки та адміністраторам.');
        $url = '/tickets/' . (int) $ticket['id'];
        $log = \App\Models\TicketTimeLog::find((int) $params['log']);
        if (!$log || (int) $log['ticket_id'] !== (int) $ticket['id']) {
            $this->linkRedirect($url, 'error', 'Запис не знайдено.');
        }
        if ((int) $log['user_id'] !== (int) Auth::id() && !Auth::hasRole(['admin'])) {
            $this->linkRedirect($url, 'error', 'Видалити запис може його автор або адміністратор системи.');
        }
        \App\Models\TicketTimeLog::delete((int) $log['id']);
        \App\Models\Audit::log('ticket', (int) $ticket['id'], 'time_deleted', (int) Auth::id(), ['log' => (int) $log['id'], 'hours' => (float) $log['hours']]);
        $this->linkRedirect($url, 'success', 'Запис часу видалено.');
    }

    public function addComment(array $params): void
    {
        Auth::requireLogin();

        $ticket = Ticket::find((int) $params['id']);
        if (!$ticket) {
            http_response_code(404);
            echo 'Тікет не знайдено.';
            return;
        }
        if (!Ticket::isVisibleTo($ticket, Auth::id(), Auth::hasRole(['admin']), $this->canSeeUnassigned())) {
            http_response_code(403);
            echo 'Доступ до цього тікета обмежено.';
            return;
        }

        $body = trim($_POST['body'] ?? '');
        if ($body !== '') {
            // Оператор чи заявник — визначаємо за роллю поточного користувача.
            $authorType = \App\Core\Access::ticketCommentAuthorType(Auth::role());
            Ticket::addComment((int) $params['id'], $authorType, Auth::id(), $body);
            $note = $this->logMinutes($ticket, $_POST['spent_minutes'] ?? 0, 'Відповідь на тікет', 'Відповідь у листуванні');
            if ($note !== '') {
                header('Location: /tickets/' . $params['id'] . '?success=' . urlencode('Відповідь надіслано.' . $note));
                exit;
            }
        }
        header('Location: /tickets/' . $params['id']);
        exit;
    }

    /** Оцінка CSAT залогіненим заявником — лише своя оцінка, лише для вирішеного/закритого тікета, лише один раз. */
    public function submitCsat(array $params): void
    {
        Auth::requireLogin();

        $id = (int) $params['id'];
        $ticket = Ticket::find($id);
        if (!$ticket) {
            http_response_code(404);
            echo 'Тікет не знайдено.';
            return;
        }
        // Навмисно суворіше за звичайну видимість тікета: оцінювати якість вирішення
        // може лише сам заявник, а не оператор чи адміністратор, що його переглядає.
        if ((int) ($ticket['requester_user_id'] ?? 0) !== Auth::id()) {
            http_response_code(403);
            echo 'Оцінити звернення може лише заявник.';
            return;
        }

        $score = (int) ($_POST['csat_score'] ?? 0);
        if ($score < 1 || $score > 5) {
            header('Location: /tickets/' . $id . '?error=' . urlencode('Оцінка має бути від 1 до 5'));
            exit;
        }

        $applied = Ticket::submitCsat($id, $score, Auth::id());
        if (!$applied) {
            header('Location: /tickets/' . $id . '?error=' . urlencode('Оцінити можна лише вирішене чи закрите звернення, і лише один раз'));
            exit;
        }
        header('Location: /tickets/' . $id . '?success=' . urlencode('Дякуємо за оцінку!'));
        exit;
    }

    private function queueExists(int $queueId): bool
    {
        foreach (Ticket::queues() as $queue) {
            if ((int) $queue['id'] === $queueId) {
                return true;
            }
        }
        return false;
    }
}
