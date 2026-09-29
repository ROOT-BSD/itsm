<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\View;
use App\Models\Audit;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\EmailTicketService;

/** Адмін-сторінка email-to-ticket: стан підключення, налаштування, ручний запуск і журнал обробки. */
class EmailController
{
    public function __construct()
    {
        Auth::requireLogin();
        if (!Auth::hasRole(['admin'])) {
            http_response_code(403);
            echo 'Доступ до налаштувань пошти дозволено лише ролі "Адміністратор системи".';
            exit;
        }
    }

    public function index(): void
    {
        $mail = Config::get('mail', []);

        View::render('admin/email', [
            'configured' => EmailTicketService::isConfigured(),
            // Пароль до шаблону НЕ передається взагалі — лише те, що безпечно показати.
            'mail' => [
                'host' => $mail['host'] ?? '',
                'port' => $mail['port'] ?? '',
                'encryption' => $mail['encryption'] ?? '',
                'username' => $mail['username'] ?? '',
                'folder' => $mail['folder'] ?? '',
                'verify_cert' => !empty($mail['verify_cert']),
            ],
            'enabled' => Setting::get('email_ticket_enabled', '0') === '1',
            'queueId' => (int) Setting::get('email_ticket_queue_id', '0'),
            'queues' => Ticket::queues(),
            'lastRunAt' => Setting::get('email_last_run_at'),
            'lastRunSummary' => Setting::get('email_last_run_summary'),
            'log' => EmailTicketService::recentLog(50),
            'scriptPath' => dirname(__DIR__, 2) . '/bin/fetch-mail.php',
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function save(): void
    {
        $enabled = !empty($_POST['enabled']);
        $queueId = (int) ($_POST['queue_id'] ?? 0);

        if ($queueId !== 0 && !in_array($queueId, array_map(fn($q) => (int) $q['id'], Ticket::queues()), true)) {
            $this->redirect('error', 'Чергу не знайдено');
        }

        Setting::set('email_ticket_enabled', $enabled ? '1' : '0');
        Setting::set('email_ticket_queue_id', (string) $queueId);
        Audit::log('app_settings', 0, 'email_settings_changed', Auth::id(), [
            'email_ticket_enabled' => $enabled ? 'так' : 'ні',
            'email_ticket_queue_id' => (string) $queueId,
        ]);

        $this->redirect('success', 'Налаштування збережено');
    }

    public function test(): void
    {
        $result = EmailTicketService::testConnection();
        $this->redirect($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function fetchNow(): void
    {
        // Веб-запит має ліміт виконання (зазвичай 30 с); обробка до 50 листів може тривати довше.
        @set_time_limit(120);
        $result = EmailTicketService::run();
        $this->redirect(in_array($result['status'], ['ok', 'busy'], true) ? 'success' : 'error', $result['message']);
    }

    private function redirect(string $key, string $message): never
    {
        header('Location: /admin/email?' . $key . '=' . urlencode($message));
        exit;
    }
}
