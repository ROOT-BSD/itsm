<?php

namespace App\Controllers;

use App\Core\View;
use App\Models\Ticket;

/**
 * Портал самообслуговування (Епік 12, розширення базової версії тікетів):
 * подання звернення без облікового запису та відстеження його статусу за
 * особистим посиланням (токеном). Жоден метод тут НЕ викликає
 * Auth::requireLogin() — сторінки контролера мають бути доступні анонімно.
 */
class SupportController
{
    public function showForm(): void
    {
        View::render('support/new', [
            'queues' => Ticket::queues(),
            'error' => $_GET['error'] ?? null,
        ]);
    }

    public function store(): void
    {
        // Honeypot: звичайний відвідувач це поле ніколи не бачить (приховане
        // CSS) і не заповнює його; бот, що сліпо заповнює всі поля форми, —
        // заповнить. Мовчки вдаємо успіх, не створюючи тікет і не підказуючи
        // боту, що саме його викрило.
        if (trim($_POST['website'] ?? '') !== '') {
            header('Location: /support?sent=1');
            exit;
        }

        $queueId = (int) ($_POST['queue_id'] ?? 0);
        $name = trim($_POST['requester_name'] ?? '');
        $email = trim($_POST['requester_email'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '' || $email === '' || $subject === '') {
            $this->redirectFormError('Заповніть ім\'я, email і тему звернення');
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->redirectFormError('Некоректна email-адреса');
            return;
        }
        if (!$this->queueExists($queueId)) {
            $this->redirectFormError('Оберіть коректну чергу');
            return;
        }

        $id = Ticket::create($queueId, $name, $email, null, $subject, $description ?: null);
        $ticket = Ticket::find($id);

        header('Location: /support/track/' . $ticket['access_token'] . '?success=' . urlencode('Звернення успішно надіслано! Збережіть це посилання, щоб відстежувати статус.'));
        exit;
    }

    public function track(array $params): void
    {
        $ticket = Ticket::findByToken($params['token'] ?? '');
        if (!$ticket) {
            http_response_code(404);
            View::render('support/not_found', []);
            return;
        }

        View::render('support/track', [
            'ticket' => $ticket,
            'comments' => Ticket::comments($ticket['id']),
            'sla' => Ticket::slaStatus($ticket, Ticket::slaPoliciesByQueue()),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function addComment(array $params): void
    {
        $ticket = $this->findByTokenOrFail($params['token'] ?? '');

        $body = trim($_POST['body'] ?? '');
        if ($body !== '') {
            Ticket::addComment($ticket['id'], 'requester', null, $body);
        }
        header('Location: /support/track/' . $ticket['access_token']);
        exit;
    }

    public function rateCsat(array $params): void
    {
        $ticket = $this->findByTokenOrFail($params['token'] ?? '');

        $score = (int) ($_POST['csat_score'] ?? 0);
        if ($score < 1 || $score > 5) {
            header('Location: /support/track/' . $ticket['access_token'] . '?error=' . urlencode('Оцінка має бути від 1 до 5'));
            exit;
        }
        if (!in_array($ticket['status'], ['resolved', 'closed'], true)) {
            header('Location: /support/track/' . $ticket['access_token'] . '?error=' . urlencode('Оцінити можна лише вирішене звернення'));
            exit;
        }
        if ($ticket['csat_score'] !== null) {
            header('Location: /support/track/' . $ticket['access_token'] . '?error=' . urlencode('Ви вже оцінили це звернення'));
            exit;
        }

        Ticket::submitCsat($ticket['id'], $score);
        header('Location: /support/track/' . $ticket['access_token'] . '?success=' . urlencode('Дякуємо за оцінку!'));
        exit;
    }

    private function findByTokenOrFail(string $token): array
    {
        $ticket = Ticket::findByToken($token);
        if (!$ticket) {
            http_response_code(404);
            View::render('support/not_found', []);
            exit;
        }
        return $ticket;
    }

    private function redirectFormError(string $message): void
    {
        header('Location: /support?error=' . urlencode($message));
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
