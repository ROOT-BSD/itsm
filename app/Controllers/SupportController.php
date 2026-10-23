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

    /**
     * «База знань»: JSON-підказки публічних статей вікі за темою/описом звернення (викликає скрипт форми).
     * Тільки сторінки з видимістю «portal»; частота запитів обмежена ('kb').
     */
    public function kbSuggest(): void
    {
        $this->throttle(RateLimiter::attempt('kb'));
        $q = trim((string) ($_GET['q'] ?? ''));
        $items = mb_strlen($q) >= 3 ? \App\Models\WikiPage::suggest(mb_substr($q, 0, 500), null, 5) : [];
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(array_map(static fn(array $i): array => ['title' => $i['title'], 'url' => '/support/kb/' . $i['slug'], 'excerpt' => $i['excerpt']], $items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Перелік публічних статей «Бази знань» (без входу); ?q= — пошук за темою. */
    public function kbIndex(): void
    {
        $this->throttle(RateLimiter::attempt('kb'));
        $q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 200));
        $found = $q !== '' ? \App\Models\WikiPage::suggest($q, null, 20) : [];
        View::render('support/kb_index', ['q' => $q, 'pages' => $q !== '' ? $found : \App\Models\WikiPage::publicList(), 'searched' => $q !== '']);
    }

    /** Публічна стаття «Бази знань» (лише для читання, без входу). */
    public function kbShow(array $params): void
    {
        $this->throttle(RateLimiter::attempt('kb'));
        $page = preg_match('/^[a-z0-9-]{1,80}$/', (string) ($params['slug'] ?? '')) ? \App\Models\WikiPage::findBySlug($params['slug']) : null;
        if (!$page || !\App\Models\WikiPage::visibleTo($page, null)) {
            http_response_code(404);
            View::render('support/kb_not_found', []);
            return;
        }
        $map = [];
        foreach (\App\Models\WikiAttachment::forPage((int) $page['id']) as $a) {
            $map[(int) $a['id']] = ['name' => $a['original_name'], 'mime' => $a['mime_type'], 'image' => \App\Models\WikiAttachment::isImage((string) $a['mime_type']), 'url' => '/support/kb/files/' . (int) $a['id']];
        }
        $renderer = (new \App\Core\Markdown([]))->setAttachments($map);
        View::render('support/kb', ['page' => $page, 'html' => $renderer->toHtml((string) $page['content'])]);
    }

    /** Файл вкладення публічної статті: лише зображення (мініатюра чи оригінал) і лише з публічної сторінки. */
    public function kbFile(array $params): void
    {
        $this->throttle(RateLimiter::attempt('kb'));
        $att = \App\Models\WikiAttachment::find((int) ($params['id'] ?? 0));
        $page = $att ? \App\Models\WikiPage::findById((int) $att['page_id']) : null;
        if (!$att || !$page || !\App\Models\WikiPage::visibleTo($page, null) || !\App\Models\WikiAttachment::isImage((string) $att['mime_type'])) {
            http_response_code(404);
            echo 'Не знайдено';
            return;
        }
        $path = \App\Services\AttachmentService::path($att['stored_name']);
        if ($path === null || !is_file($path)) {
            http_response_code(404);
            echo 'Не знайдено';
            return;
        }
        $send = $path;
        if (isset($_GET['thumb'])) {
            $send = \App\Services\AttachmentService::thumbnailFor($att['stored_name'], $att['mime_type']) ?? $path;
        }
        $mime = $send !== $path ? \App\Services\AttachmentService::thumbMime($att['mime_type']) : $att['mime_type'];
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($send));
        header('Content-Disposition: inline');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');
        readfile($send);
        exit;
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

        $id = Ticket::create($queueId, $name, $email, null, $subject, $description ?: null, null, 'portal');
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
