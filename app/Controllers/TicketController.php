<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Ticket;
use App\Models\User;

class TicketController
{
    /** Допустимі значення статусу тікета (ENUM у БД). */
    private const STATUSES = ['new', 'in_progress', 'waiting_customer', 'resolved', 'closed'];

    public function index(): void
    {
        Auth::requireLogin();
        View::render('tickets/index', [
            'tickets' => Ticket::allVisibleTo(Auth::id(), Auth::hasRole(['admin'])),
        ]);
    }

    public function showCreateForm(): void
    {
        Auth::requireLogin();
        View::render('tickets/create', [
            'queues' => Ticket::queues(),
            'error' => $_GET['error'] ?? null,
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();

        $queueId = (int) ($_POST['queue_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($queueId === 0 || $subject === '') {
            header('Location: /tickets/create?error=' . urlencode('Оберіть чергу і вкажіть тему звернення'));
            exit;
        }
        if (!$this->queueExists($queueId)) {
            header('Location: /tickets/create?error=' . urlencode('Оберіть коректну чергу'));
            exit;
        }

        // Заявник — поточний користувач (окремий портал для співробітників без
        // облікового запису основного функціоналу — майбутній крок, п. 2.13 ТЗ)
        $me = User::findById(Auth::id());

        $id = Ticket::create($queueId, $me['full_name'], $me['email'], Auth::id(), $subject, $description ?: null);
        header('Location: /tickets/' . $id);
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
        if (!Ticket::isVisibleTo($ticket, Auth::id(), Auth::hasRole(['admin']))) {
            http_response_code(403);
            echo 'Доступ до цього тікета обмежено — його бачать лише заявник, призначений оператор та адміністратор системи.';
            return;
        }

        View::render('tickets/show', [
            'ticket' => $ticket,
            'comments' => Ticket::comments($ticket['id']),
            'users' => User::allActive(),
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
        if (!Ticket::isVisibleTo($ticket, Auth::id(), Auth::hasRole(['admin']))) {
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
        if (!Ticket::isVisibleTo($ticket, Auth::id(), Auth::hasRole(['admin']))) {
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
