<?php

namespace App\Controllers\Api;

use App\Core\Access;
use App\Core\ApiResponse;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ApiSerializer;

/**
 * Тікети. Видимість і рольові правила — ті самі, що у вебі (Ticket::isVisibleTo, App\Core\Access): токен заявника бачить
 * лише його тікети, оператора — свої й ще нікому не призначені, адміністратора — усі.
 */
class TicketsApiController extends ApiController
{
    private const PATCHABLE = ['status', 'operator_id'];

    public function index(): void
    {
        $status = $_GET['status'] ?? null;
        $queueId = isset($_GET['queue_id']) ? (int) $_GET['queue_id'] : null;
        $query = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
        if ($status !== null && !in_array($status, Ticket::STATUSES, true)) {
            $this->validationFailed(['status' => 'Допустимі значення: ' . implode(', ', Ticket::STATUSES) . '.']);
        }

        $tickets = array_values(array_filter(
            Ticket::allVisibleTo($this->userId, $this->isAdmin, Access::canSeeUnassignedTickets($this->role)),
            fn(array $t): bool =>
                ($status === null || $t['status'] === $status)
                && ($queueId === null || (int) $t['queue_id'] === $queueId)
                && (($_GET['assigned_to_me'] ?? '') !== '1' || (int) ($t['assigned_operator_id'] ?? 0) === $this->userId)
                && (($_GET['requested_by_me'] ?? '') !== '1' || (int) ($t['requester_user_id'] ?? 0) === $this->userId)
                && ($query === '' || str_contains(mb_strtolower($t['subject']), $query))
        ));

        $this->paginate($tickets, static fn(array $t) => ($full = Ticket::find((int) $t['id'])) ? ApiSerializer::ticket($full) : null);
    }

    public function show(array $params): void
    {
        $ticket = $this->visibleTicket((int) $params['id']);
        $data = ApiSerializer::ticket($ticket);
        $data['comments'] = array_map([ApiSerializer::class, 'ticketComment'], Ticket::comments((int) $ticket['id']));
        ApiResponse::ok($data);
    }

    /** Створює тікет від імені власника токена (для інтеграцій — заведіть окремого службового користувача). */
    public function store(): void
    {
        $this->requireWrite();
        $data = $this->body();
        $errors = [];

        $queueId = $this->intField($data, 'queue_id', $errors, true);
        if ($queueId !== null && !$this->queueExists($queueId)) {
            $errors['queue_id'] = 'Такої черги немає (див. GET /api/v1/ticket-queues).';
        }
        $subject = $this->stringField($data, 'subject', 255, $errors, true);
        $description = $this->stringField($data, 'description', 20000, $errors);
        $projectId = $this->intField($data, 'project_id', $errors);
        if ($projectId !== null) {
            $project = Project::find($projectId);
            if (!$project || !Project::isVisibleTo($project, $this->userId, $this->isAdmin)) {
                $errors['project_id'] = 'Проєкт не знайдено або немає до нього доступу.';
            }
        }
        if ($errors) {
            $this->validationFailed($errors);
        }

        $id = Ticket::create($queueId, $this->user['full_name'], $this->user['email'], $this->userId, $subject, $description, $projectId);
        ApiResponse::ok(ApiSerializer::ticket(Ticket::find($id)), 201);
    }

    public function update(array $params): void
    {
        $this->requireWrite();
        $ticket = $this->visibleTicket((int) $params['id']);
        $data = $this->body();
        $errors = [];

        foreach (array_diff(array_keys($data), self::PATCHABLE) as $field) {
            $errors[(string) $field] = 'Це поле не можна змінити через API. Дозволені: ' . implode(', ', self::PATCHABLE) . '.';
        }
        if (!$data) {
            $errors['_'] = 'Вкажіть хоча б одне поле для зміни.';
        }

        $status = null;
        if (array_key_exists('status', $data)) {
            $status = $data['status'];
            if (!is_string($status) || !in_array($status, Ticket::STATUSES, true)) {
                $errors['status'] = 'Допустимі значення: ' . implode(', ', Ticket::STATUSES) . '.';
            }
        }
        $operatorGiven = array_key_exists('operator_id', $data);
        $operatorId = null;
        if ($operatorGiven) {
            if (!Access::canAssignTicketOperator($this->role)) {
                $errors['operator_id'] = 'Призначати оператора можуть лише адміністратор, керівник ІТ-підрозділу й оператор служби підтримки.';
            } else {
                $operatorId = $this->intField($data, 'operator_id', $errors);
                if ($operatorId !== null) {
                    $user = User::findById($operatorId);
                    if (!$user || !(int) $user['is_active']) {
                        $errors['operator_id'] = 'Такого активного користувача немає (див. GET /api/v1/users).';
                    }
                }
            }
        }
        if ($errors) {
            $this->validationFailed($errors);
        }

        $id = (int) $ticket['id'];
        if ($status !== null && $status !== $ticket['status']) {
            Ticket::updateStatus($id, $status, $this->userId);
        }
        if ($operatorGiven && $operatorId !== (!empty($ticket['assigned_operator_id']) ? (int) $ticket['assigned_operator_id'] : null)) {
            Ticket::assignOperator($id, $operatorId, $this->userId);
        }
        ApiResponse::ok(ApiSerializer::ticket(Ticket::find($id)));
    }

    public function addComment(array $params): void
    {
        $this->requireWrite();
        $ticket = $this->visibleTicket((int) $params['id']);
        $errors = [];
        $body = $this->stringField($this->body(), 'body', 20000, $errors, true);
        if ($errors) {
            $this->validationFailed($errors);
        }
        Ticket::addComment((int) $ticket['id'], Access::ticketCommentAuthorType($this->role), $this->userId, $body);
        $comments = Ticket::comments((int) $ticket['id']);
        ApiResponse::ok(ApiSerializer::ticketComment(end($comments)), 201);
    }

    // ------------------------------------------------------------------ допоміжне

    private function visibleTicket(int $id): array
    {
        $ticket = Ticket::find($id);
        if (!$ticket || !Ticket::isVisibleTo($ticket, $this->userId, $this->isAdmin, Access::canSeeUnassignedTickets($this->role))) {
            $this->notFound('Тікет');
        }
        return $ticket;
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
