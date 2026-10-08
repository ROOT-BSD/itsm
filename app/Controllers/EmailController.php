<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\View;
use App\Models\Audit;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailTicketService;
use App\Services\MailerService;

/** Адмін-сторінка email-to-ticket: стан підключення (IMAP+SMTP), налаштування, ручний запуск і журнал обробки. */
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
        $smtp = Config::get('smtp', []);

        View::render('admin/email', [
            'configured' => EmailTicketService::isConfigured(),
            'configProblem' => EmailTicketService::configProblem(),
            // Паролі до шаблону НЕ передаються взагалі — лише те, що безпечно показати.
            'mail' => [
                'host' => $mail['host'] ?? '',
                'port' => $mail['port'] ?? '',
                'encryption' => $mail['encryption'] ?? '',
                'username' => $mail['username'] ?? '',
                'folder' => $mail['folder'] ?? '',
                'verify_cert' => !empty($mail['verify_cert']),
            ],
            'smtpConfigured' => MailerService::isConfigured(),
            'smtp' => [
                'host' => $smtp['host'] ?? '',
                'port' => $smtp['port'] ?? '',
                'encryption' => $smtp['encryption'] ?? '',
                'from_email' => $smtp['from_email'] ?? '',
                'from_name' => $smtp['from_name'] ?? '',
                'verify_cert' => !empty($smtp['verify_cert']),
            ],
            'enabled' => Setting::get('email_ticket_enabled', '0') === '1',
            'autoreplyEnabled' => Setting::get('email_autoreply_enabled', '0') === '1',
            'notifyTicketsEnabled' => Setting::get('email_notify_tickets_enabled', '0') === '1',
            'notifyProjectsEnabled' => Setting::get('email_notify_projects_enabled', '0') === '1',
            'notifyTasksEnabled' => Setting::get('email_notify_tasks_enabled', '0') === '1',
            'notifyForumEnabled' => Setting::get('email_notify_forum_enabled', '0') === '1',
            'notifyRemindersEnabled' => Setting::get('email_notify_reminders_enabled', '0') === '1',
            'remindersScriptPath' => dirname(__DIR__, 2) . '/bin/send-reminders.php',
            'queueId' => (int) Setting::get('email_ticket_queue_id', '0'),
            'queues' => Ticket::queues(),
            'lastRunAt' => Setting::get('email_last_run_at'),
            'lastRunSummary' => Setting::get('email_last_run_summary'),
            'log' => EmailTicketService::recentLog(50),
            'scriptPath' => dirname(__DIR__, 2) . '/bin/fetch-mail.php',
            'myEmail' => User::findById(Auth::id())['email'] ?? '',
            'appUrlOverride' => Setting::get('app_url', ''),
            'appUrlEffective' => Setting::appUrl(),
            'appUrlEnvDefault' => rtrim((string) Config::get('app.url', ''), '/'),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    /** Публічна адреса застосунку — щоб посилання в автовідповіді (і будь-де ще в майбутньому) вели на реальний домен, а не на APP_URL з .env (типово http://localhost). */
    public function saveAppUrl(): void
    {
        $url = trim((string) ($_POST['app_url'] ?? ''));

        if ($url !== '') {
            if (!preg_match('#^https?://[^\s/]+#i', $url)) {
                $this->redirect('error', 'Адреса має починатися з http:// або https:// (наприклад, https://itsm.company.com)');
            }
            $url = rtrim($url, '/');
        }

        Setting::set('app_url', $url);
        Audit::log('app_settings', 0, 'app_url_changed', Auth::id(), ['app_url' => $url !== '' ? $url : '(використовується APP_URL з .env)']);

        $this->redirect('success', $url !== '' ? 'Домен збережено.' : 'Домен очищено — знову використовується APP_URL з .env.');
    }

    public function save(): void
    {
        $enabled = !empty($_POST['enabled']);
        $autoreplyEnabled = !empty($_POST['autoreply_enabled']);
        $notifyTickets = !empty($_POST['notify_tickets_enabled']);
        $notifyProjects = !empty($_POST['notify_projects_enabled']);
        $notifyTasks = !empty($_POST['notify_tasks_enabled']);
        $notifyForum = !empty($_POST['notify_forum_enabled']);
        $notifyReminders = !empty($_POST['notify_reminders_enabled']);
        $queueId = (int) ($_POST['queue_id'] ?? 0);

        if ($queueId !== 0 && !in_array($queueId, array_map(fn($q) => (int) $q['id'], Ticket::queues()), true)) {
            $this->redirect('error', 'Чергу не знайдено');
        }
        // Не даємо ввімкнути автовідповідь/сповіщення без налаштованого SMTP — інакше кожна дія
        // (коментар, зміна статусу тощо) мовчки «намагалась» би надіслати лист і щоразу писала
        // б помилку в журнал аудиту.
        $anyNotification = $autoreplyEnabled || $notifyTickets || $notifyProjects || $notifyTasks || $notifyForum || $notifyReminders;
        if ($anyNotification && !MailerService::isConfigured()) {
            $this->redirect('error', 'Спершу налаштуйте надсилання пошти (SMTP) — заповніть MAIL_SMTP_* у .env.');
        }

        Setting::set('email_ticket_enabled', $enabled ? '1' : '0');
        Setting::set('email_autoreply_enabled', $autoreplyEnabled ? '1' : '0');
        Setting::set('email_notify_tickets_enabled', $notifyTickets ? '1' : '0');
        Setting::set('email_notify_projects_enabled', $notifyProjects ? '1' : '0');
        Setting::set('email_notify_tasks_enabled', $notifyTasks ? '1' : '0');
        Setting::set('email_notify_forum_enabled', $notifyForum ? '1' : '0');
        Setting::set('email_notify_reminders_enabled', $notifyReminders ? '1' : '0');
        Setting::set('email_ticket_queue_id', (string) $queueId);
        Audit::log('app_settings', 0, 'email_settings_changed', Auth::id(), [
            'email_ticket_enabled' => $enabled ? 'так' : 'ні',
            'email_autoreply_enabled' => $autoreplyEnabled ? 'так' : 'ні',
            'email_notify_tickets_enabled' => $notifyTickets ? 'так' : 'ні',
            'email_notify_projects_enabled' => $notifyProjects ? 'так' : 'ні',
            'email_notify_tasks_enabled' => $notifyTasks ? 'так' : 'ні',
            'email_notify_forum_enabled' => $notifyForum ? 'так' : 'ні',
            'email_notify_reminders_enabled' => $notifyReminders ? 'так' : 'ні',
            'email_ticket_queue_id' => (string) $queueId,
        ]);

        $this->redirect('success', 'Налаштування збережено');
    }

    public function test(): void
    {
        $result = EmailTicketService::testConnection();
        $this->redirect($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function testSmtp(): void
    {
        $result = MailerService::testConnection();
        $this->redirect($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** Надсилає пробний лист на адресу поточного адміністратора — щоб перевірити SMTP «наскрізно», а не лише з'єднання. */
    public function sendTestEmail(): void
    {
        $me = User::findById(Auth::id());
        if (!$me) {
            $this->redirect('error', 'Не вдалося визначити вашу адресу.');
        }

        $result = MailerService::send(
            $me['email'],
            $me['full_name'],
            'Тестовий лист з ITSM System',
            "Доброго дня, {$me['full_name']}!\n\nЯкщо ви бачите цей лист — надсилання пошти (SMTP) налаштовано правильно."
        );
        $this->redirect($result['ok'] ? 'success' : 'error', $result['ok'] ? "Тестовий лист надіслано на {$me['email']}." : $result['message']);
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
