<?php

namespace App\Controllers;

use App\Core\RateLimiter;
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
        // Першим, до будь-якої обробки (у т.ч. файлів): бот, що шле форму без угаву, не має навантажувати ні БД, ні диск.
        $this->throttle(RateLimiter::attempt('submit'));

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

        // Зображення (PNG/JPG) — лише якщо вмикнено; ліміти суворіші, ніж для співробітників, бо завантажує
        // будь-хто без входу (частота запитів обмежена за IP — див. config rate_limits, ліміти файлів — attachments.portal_*).
        $images = ['added' => 0, 'errors' => []];
        if (\App\Core\Config::get('attachments.portal_enabled', true)) {
            $images = \App\Services\AttachmentService::attachCreationUploads(
                'ticket', $id, $_FILES['files'] ?? [], null, 'portal',
                (int) \App\Core\Config::get('attachments.portal_max_files', 3),
                (int) \App\Core\Config::get('attachments.portal_max_bytes', 5 * 1048576)
            );
        }

        $success = 'Звернення успішно надіслано! Збережіть це посилання, щоб відстежувати статус.'
            . ($images['added'] > 0 ? ' Прикріплено зображень: ' . $images['added'] . '.' : '');
        $query = ['success' => $success];
        if ($images['errors']) {
            $query['error'] = implode(' ', $images['errors']);
        }
        header('Location: /support/track/' . $ticket['access_token'] . '?' . http_build_query($query));
        exit;
    }

    public function track(array $params): void
    {
        $this->throttle(RateLimiter::peek('badtoken'));
        $ticket = Ticket::findByToken($params['token'] ?? '');
        if (!$ticket) {
            $this->tokenNotFound();
            return;
        }

        View::render('support/track', [
            'ticket' => $ticket,
            'comments' => Ticket::comments($ticket['id']),
            'attachments' => \App\Models\Attachment::forRequester((int) $ticket['id']),
            'sla' => Ticket::slaStatus($ticket, Ticket::slaPoliciesByQueue()),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    /**
     * Власне вкладення заявника (картинка чи PDF, надіслані ним через портал або листом). Доступ — за секретним
     * посиланням звернення, як і сама сторінка відстеження; вкладення інших тікетів і файли співробітників недоступні
     * (однакова 404, щоб не підказувати, що вони існують). Хибні спроби враховує той самий ліміт, що й перебір токенів.
     */
    public function attachment(array $params): void
    {
        $ticket = $this->findByTokenOrFail($params['token'] ?? '');
        $attachment = \App\Models\Attachment::find((int) ($params['id'] ?? 0));
        if (!$attachment
            || (int) $attachment['ticket_id'] !== (int) $ticket['id']
            || !in_array($attachment['source'], ['portal', 'email'], true)) {
            $this->tokenNotFound();
            return;
        }
        \App\Controllers\AttachmentController::send($attachment);
    }

    public function addComment(array $params): void
    {
        $this->throttle(RateLimiter::attempt('comment'));
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
        $this->throttle(RateLimiter::attempt('rate'));
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
        $this->throttle(RateLimiter::peek('badtoken'));
        $ticket = Ticket::findByToken($token);
        if (!$ticket) {
            $this->tokenNotFound();
            exit;
        }
        return $ticket;
    }

    /** Неіснуюче посилання: рахуємо промах (захист від перебору токенів) і показуємо звичайну 404. */
    private function tokenNotFound(): void
    {
        RateLimiter::attempt('badtoken');
        http_response_code(404);
        View::render('support/not_found', []);
    }

    /**
     * Якщо ліміт вичерпано ($retryAfter — секунди до відновлення), відповідає 429 з Retry-After і зупиняє запит.
     * null = запит дозволено.
     */
    private function throttle(?int $retryAfter): void
    {
        if ($retryAfter === null) {
            return;
        }
        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        View::render('support/rate_limited', ['minutes' => max(1, (int) ceil($retryAfter / 60))]);
        exit;
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
