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
    private const STATUSES = ['new', 'in_progress', 'waiting_customer', 'resolved', 'closed'];

    /** Оператори служби підтримки й керівники ІТ-підрозділу додатково бачать непризначені тікети — щоб було що брати в роботу. */
    private function canSeeUnassigned(): bool
    {
        return Auth::hasRole(['it_manager', 'support_operator']);
    }

    public function index(): void
    {
        Auth::requireLogin();

        $tickets = Ticket::allOpenVisibleTo(Auth::id(), Auth::hasRole(['admin']), $this->canSeeUnassigned());
        $policies = Ticket::slaPoliciesByQueue();

        foreach ($tickets as &$ticket) {
            $ticket['sla'] = Ticket::slaStatus($ticket, $policies);
        }
        unset($ticket);

        // Прострочені тікети — вгору списку, щоб їх одразу було видно (сенс ескалації).
        usort($tickets, function ($a, $b) {
            $aOverdue = $a['sla']['response_overdue'] || $a['sla']['resolution_overdue'];
            $bOverdue = $b['sla']['response_overdue'] || $b['sla']['resolution_overdue'];
            return $bOverdue <=> $aOverdue;
        });

        View::render('tickets/index', [
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

        $id = Ticket::create($queueId, $me['full_name'], $me['email'], Auth::id(), $subject, $description ?: null, $projectId);

        // Зображення з форми (вибрані файли або вставлений скриншот). Тікет уже створено: частина файлів
        // може бути відхилена, і це не скасовує тікет — користувач побачить, що саме не прикріпилось.
        $images = \App\Services\AttachmentService::attachCreationUploads(
            'ticket', $id, $_FILES['files'] ?? [], Auth::id(), 'web',
            (int) \App\Core\Config::get('attachments.max_per_request', 10)
        );
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
        ]);
    }

    public function assignOperator(array $params): void
    {
        Auth::requireLogin();

        // Призначати виконавця тікета можуть лише персонал ІТ-підрозділу,
        // а не будь-який заявник — інакше рядовий користувач міг би
        // призначити (або зняти) оператора на чужому зверненні.
        if (!Auth::hasRole(['admin', 'it_manager', 'support_operator'])) {
            http_response_code(403);
            echo 'Призначати оператора тікета можуть лише керівник ІТ-підрозділу, адміністратор системи або оператор служби підтримки.';
            return;
        }

        $operatorId = !empty($_POST['operator_id']) ? (int) $_POST['operator_id'] : null;
        Ticket::assignOperator((int) $params['id'], $operatorId, Auth::id());
        header('Location: /tickets/' . $params['id']);
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
        header('Location: /tickets/' . $params['id']);
        exit;
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
            $authorType = Auth::hasRole(['admin', 'support_operator']) ? 'operator' : 'requester';
            Ticket::addComment((int) $params['id'], $authorType, Auth::id(), $body);
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
