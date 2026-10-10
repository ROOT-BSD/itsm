<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;

/**
 * Email-сповіщення про активність у системі — тікети (відповідь, зміна
 * статусу), проєкти й задачі (створення з відповідальним/виконавцем,
 * призначення), наближення й настання терміну виконання задачі.
 *
 * Кожен публічний метод — окрема подія, викликається напряму з відповідної
 * моделі одразу після зміни в БД (той самий підхід, що й Audit::log()).
 * Усі методи мовчки нічого не роблять, якщо сповіщення вимкнені або SMTP не
 * налаштовано, і НІКОЛИ не кидають виняток — лист, що не надіслався, не
 * повинен зривати дію, яка його спричинила (MailerService::send() і сам
 * ніколи не кидає, тут — додатковий страховий try/catch на випадок помилки
 * при підготовці листа, наприклад збою запиту до БД).
 */
class NotificationService
{
    private static function ticketsEnabled(): bool
    {
        return Setting::get('email_notify_tickets_enabled', '0') === '1' && MailerService::isConfigured();
    }

    private static function projectsEnabled(): bool
    {
        return Setting::get('email_notify_projects_enabled', '0') === '1' && MailerService::isConfigured();
    }

    private static function tasksEnabled(): bool
    {
        return Setting::get('email_notify_tasks_enabled', '0') === '1' && MailerService::isConfigured();
    }

    private static function forumEnabled(): bool
    {
        return Setting::get('email_notify_forum_enabled', '0') === '1' && MailerService::isConfigured();
    }

    private static function remindersEnabled(): bool
    {
        return Setting::get('email_notify_reminders_enabled', '0') === '1' && MailerService::isConfigured();
    }

    // ---------- Тікети ----------

    /**
     * Новий коментар до тікета — сповіщаємо "іншу сторону": коментар
     * оператора йде заявнику, коментар заявника — призначеному оператору.
     * Спрацьовує однаково для коментаря через веб-інтерфейс і через
     * email-to-ticket (обидва шляхи ведуть через Ticket::addComment()).
     */
    public static function ticketCommentAdded(int $ticketId, string $authorType, ?int $authorId): void
    {
        if (!self::ticketsEnabled()) {
            return;
        }
        try {
            $ticket = Ticket::find($ticketId);
            if (!$ticket) {
                return;
            }

            if ($authorType === 'operator') {
                if ((int) $ticket['requester_user_id'] === (int) $authorId) {
                    return; // оператор і заявник — та сама людина (рідкісний випадок) — не сповіщаємо саму себе
                }
                self::sendTicketMail(
                    $ticket['requester_email'],
                    $ticket['requester_name'],
                    !empty($ticket['requester_user_id']),
                    $ticket,
                    "Нова відповідь у зверненні «{$ticket['subject']}» [#{$ticketId}]",
                    'Оператор залишив нове повідомлення у вашому зверненні.'
                );
            } else {
                $operatorId = $ticket['assigned_operator_id'] ?? null;
                if (!$operatorId || (int) $operatorId === (int) $authorId) {
                    return; // немає призначеного оператора, або це він сам собі коментує
                }
                $operator = User::findById((int) $operatorId);
                if (!$operator || !$operator['is_active']) {
                    return;
                }
                self::sendTicketMail(
                    $operator['email'],
                    $operator['full_name'],
                    true,
                    $ticket,
                    "Нова відповідь заявника у зверненні «{$ticket['subject']}» [#{$ticketId}]",
                    'Заявник залишив нове повідомлення у зверненні, яке на вас призначене.'
                );
            }
        } catch (\Throwable) {
            // навмисно мовчки — лист не надіслався, але коментар уже успішно збережено
        }
    }

    /** Зміна статусу тікета — сповіщаємо і заявника, і призначеного оператора, окрім того, хто саме її зробив. */
    public static function ticketStatusChanged(int $ticketId, string $newStatus, int $actingUserId): void
    {
        if (!self::ticketsEnabled()) {
            return;
        }
        try {
            $ticket = Ticket::find($ticketId);
            if (!$ticket) {
                return;
            }

            $labels = ['new' => 'Новий', 'in_progress' => 'В роботі', 'waiting_customer' => 'Очікує відповіді заявника', 'resolved' => 'Вирішено', 'closed' => 'Закрито'];
            $statusLabel = $labels[$newStatus] ?? $newStatus;
            $subject = "Змінено статус звернення «{$ticket['subject']}» [#{$ticketId}]";
            $body = "Новий статус звернення: {$statusLabel}.";

            if ((int) $ticket['requester_user_id'] !== $actingUserId) {
                self::sendTicketMail($ticket['requester_email'], $ticket['requester_name'], !empty($ticket['requester_user_id']), $ticket, $subject, $body);
            }

            $operatorId = $ticket['assigned_operator_id'] ?? null;
            if ($operatorId && (int) $operatorId !== $actingUserId) {
                $operator = User::findById((int) $operatorId);
                if ($operator && $operator['is_active']) {
                    self::sendTicketMail($operator['email'], $operator['full_name'], true, $ticket, $subject, $body);
                }
            }
        } catch (\Throwable) {
        }
    }

    private static function sendTicketMail(string $email, string $name, bool $hasAccount, array $ticket, string $subject, string $intro): void
    {
        $link = Setting::appUrl() . ($hasAccount ? '/tickets/' . $ticket['id'] : '/support/track/' . $ticket['access_token']);
        $body = "{$intro}\n\nПереглянути звернення:\n{$link}\n\nЦе автоматичний лист.";
        $result = MailerService::send($email, $name, $subject, $body);
        if (!$result['ok']) {
            Audit::log('ticket', (int) $ticket['id'], 'notification_failed', null, ['to' => $email, 'error' => mb_substr($result['message'], 0, 200)]);
        }
    }

    // ---------- Форум ----------

    /** Скільки адресатів сповіщаємо про одну відповідь: SMTP-надсилання синхронне, довга розсилка гальмувала б запит автора. */
    private const FORUM_MAX_RECIPIENTS = 20;

    /**
     * Нова відповідь у темі форуму — сповіщаємо автора теми й усіх, хто в ній писав, крім того, хто відповів. Лист отримує
     * лише той, хто за своєю роллю бачить розділ теми (тема могла бути перенесена в закритий розділ), тож текст відповіді
     * не витікає тим, кому розділ недоступний.
     */
    public static function forumReplyAdded(int $topicId, int $postId, int $authorId): void
    {
        if (!self::forumEnabled()) {
            return;
        }
        try {
            $topic = \App\Models\Forum::findTopic($topicId);
            $post = \App\Models\Forum::findPost($postId);
            $author = User::findById($authorId);
            if (!$topic || !$post) {
                return;
            }

            $link = Setting::appUrl() . '/forum/posts/' . $postId;
            $by = $author ? $author['full_name'] : 'Хтось';
            $excerpt = \App\Core\Markdown::plainText((string) $post['body']);
            $excerpt = trim(preg_replace('/\s+/u', ' ', $excerpt) ?? '');
            if (mb_strlen($excerpt) > 300) {
                $excerpt = rtrim(mb_substr($excerpt, 0, 300)) . '…';
            }
            $subject = "Нова відповідь у темі форуму «{$topic['title']}»";

            $sent = 0;
            foreach (\App\Models\Forum::participants($topicId, $authorId) as $user) {
                if (!in_array($topic['board_visibility'], \App\Models\Forum::allowedVisibilities($user['role_code']), true)) {
                    continue;
                }
                if ($sent >= self::FORUM_MAX_RECIPIENTS) {
                    break;
                }
                $body = "Доброго дня, {$user['full_name']}!\n\n{$by} відповів(ла) у темі «{$topic['title']}» (розділ «{$topic['board_name']}»):\n\n{$excerpt}\n\nПерейти до відповіді:\n{$link}\n\nЦе автоматичний лист. Ви отримуєте його, бо створили цю тему або писали в ній.";
                $result = MailerService::send($user['email'], $user['full_name'], $subject, $body);
                $sent++;
                if (!$result['ok']) {
                    Audit::log('forum_topic', $topicId, 'notification_failed', null, ['to' => $user['email'], 'error' => mb_substr($result['message'], 0, 200)]);
                    // Несправний SMTP-сервер не оживе за наступні секунди, а кожна спроба — це таймаут: не змушуємо автора
                    // відповіді чекати їх усіх підряд. Решта адресатів просто не отримає цього листа.
                    if (!str_contains($result['message'], 'Некоректна адреса')) {
                        break;
                    }
                }
            }
        } catch (\Throwable) {
            // навмисно мовчки — відповідь уже збережено, лист не надіслався
        }
    }

    /**
     * Нова тема в розділі форуму — лист підписникам розділу (кнопка «Підписатися» на сторінці розділу), крім автора.
     * Одержувач мусить за роллю бачити розділ; кількість листів за одну тему обмежена (FORUM_MAX_RECIPIENTS), щоб
     * створення теми не затримувалось на великій розсилці.
     */
    public static function forumTopicCreated(int $topicId, int $authorId): void
    {
        if (!self::forumEnabled()) {
            return;
        }
        try {
            $topic = \App\Models\Forum::findTopic($topicId);
            if (!$topic) {
                return;
            }
            $author = User::findById($authorId);
            $by = $author ? $author['full_name'] : 'Хтось';
            $firstPost = \App\Models\Forum::findPost(\App\Models\Forum::firstPostId($topicId));
            $excerpt = '';
            if ($firstPost) {
                $excerpt = trim(preg_replace('/\s+/u', ' ', \App\Core\Markdown::plainText((string) $firstPost['body'])) ?? '');
                if (mb_strlen($excerpt) > 300) {
                    $excerpt = rtrim(mb_substr($excerpt, 0, 300)) . '…';
                }
            }
            $link = Setting::appUrl() . '/forum/topics/' . $topicId;
            $boardLink = Setting::appUrl() . '/forum/boards/' . (int) $topic['board_id'];
            $subject = "Нова тема у розділі форуму «{$topic['board_name']}»: {$topic['title']}";

            $sent = 0;
            foreach (\App\Models\Forum::boardSubscribers((int) $topic['board_id'], $authorId) as $user) {
                if (!in_array($topic['board_visibility'], \App\Models\Forum::allowedVisibilities($user['role_code']), true)) {
                    continue;
                }
                if ($sent >= self::FORUM_MAX_RECIPIENTS) {
                    break;
                }
                $body = "Доброго дня, {$user['full_name']}!\n\n{$by} створив(ла) нову тему «{$topic['title']}» у розділі «{$topic['board_name']}»:\n\n{$excerpt}\n\nПерейти до теми:\n{$link}\n\nЦе автоматичний лист. Ви отримуєте його, бо підписані на нові теми розділу. Скасувати підписку можна на сторінці розділу:\n{$boardLink}";
                $result = MailerService::send($user['email'], $user['full_name'], $subject, $body);
                $sent++;
                if (!$result['ok']) {
                    Audit::log('forum_topic', $topicId, 'notification_failed', null, ['to' => $user['email'], 'error' => mb_substr($result['message'], 0, 200)]);
                    if (!str_contains($result['message'], 'Некоректна адреса')) {
                        break;
                    }
                }
            }
        } catch (\Throwable) {
            // навмисно мовчки — тему вже створено, лист не надіслався
        }
    }

    // ---------- Проєкти ----------

    /** Проєкт створено з одразу вказаним відповідальним — окреме сповіщення про призначення (нижче) при створенні не дублюється. */
    public static function projectCreated(int $projectId, ?int $responsibleUserId, int $createdBy): void
    {
        if (!self::projectsEnabled() || !$responsibleUserId || $responsibleUserId === $createdBy) {
            return;
        }
        self::sendProjectResponsibleMail($projectId, $responsibleUserId, isNew: true);
    }

    /** Відповідального проєкту змінено — сповіщаємо нового (лише якщо він справді змінився, не при повторному збереженні того самого значення). */
    /** Користувачу надали доступ до проєкту — лист «Вам надано доступ» (за перемикачем «Сповіщення про проєкти»; без листа самому собі). */
    public static function projectMemberAdded(int $projectId, int $memberUserId, int $actingUserId): void
    {
        if (!self::projectsEnabled() || $memberUserId === $actingUserId) {
            return;
        }
        try {
            $project = \App\Models\Project::find($projectId);
            $user = User::findById($memberUserId);
            $actor = User::findById($actingUserId);
            if (!$project || !$user || !$user['is_active']) {
                return;
            }

            $link = Setting::appUrl() . '/projects/' . $projectId;
            $by = $actor ? " користувачем {$actor['full_name']}" : '';
            $subject = "Вам надано доступ до проєкту «{$project['name']}»";
            $body = "Доброго дня, {$user['full_name']}!\n\n{$subject}{$by}. Ви бачите цей проєкт і його задачі й можете працювати з ними.\n\nПереглянути проєкт:\n{$link}\n\nЦе автоматичний лист.";
            $result = MailerService::send($user['email'], $user['full_name'], $subject, $body);
            if (!$result['ok']) {
                Audit::log('project', $projectId, 'notification_failed', null, ['to' => $user['email'], 'error' => mb_substr($result['message'], 0, 200)]);
            }
        } catch (\Throwable) {
        }
    }

    public static function projectResponsibleChanged(int $projectId, ?int $responsibleUserId, ?int $previousResponsibleUserId, int $actingUserId): void
    {
        if (!self::projectsEnabled() || !$responsibleUserId || $responsibleUserId === $previousResponsibleUserId || $responsibleUserId === $actingUserId) {
            return;
        }
        self::sendProjectResponsibleMail($projectId, $responsibleUserId, isNew: false);
    }

    private static function sendProjectResponsibleMail(int $projectId, int $responsibleUserId, bool $isNew): void
    {
        try {
            $project = \App\Models\Project::find($projectId);
            $user = User::findById($responsibleUserId);
            if (!$project || !$user || !$user['is_active']) {
                return;
            }

            $link = Setting::appUrl() . '/projects/' . $projectId;
            $subject = $isNew
                ? "Вас призначено відповідальним за новий проєкт «{$project['name']}»"
                : "Вас призначено відповідальним за проєкт «{$project['name']}»";
            $body = "Доброго дня, {$user['full_name']}!\n\n{$subject}.\n\nПереглянути проєкт:\n{$link}\n\nЦе автоматичний лист.";
            $result = MailerService::send($user['email'], $user['full_name'], $subject, $body);
            if (!$result['ok']) {
                Audit::log('project', $projectId, 'notification_failed', null, ['to' => $user['email'], 'error' => mb_substr($result['message'], 0, 200)]);
            }
        } catch (\Throwable) {
        }
    }

    // ---------- Задачі ----------

    /** Задачу створено з одразу вказаним виконавцем. */
    public static function taskCreated(int $taskId, ?int $assigneeId, int $authorId): void
    {
        if (!self::tasksEnabled() || !$assigneeId || $assigneeId === $authorId) {
            return;
        }
        self::sendTaskAssigneeMail($taskId, $assigneeId, isNew: true);
    }

    /** Виконавця задачі змінено — сповіщаємо нового, лише якщо він справді змінився. */
    public static function taskAssigneeChanged(int $taskId, ?int $assigneeId, ?int $previousAssigneeId, int $actingUserId): void
    {
        if (!self::tasksEnabled() || !$assigneeId || $assigneeId === $previousAssigneeId || $assigneeId === $actingUserId) {
            return;
        }
        self::sendTaskAssigneeMail($taskId, $assigneeId, isNew: false);
    }

    private static function sendTaskAssigneeMail(int $taskId, int $assigneeId, bool $isNew): void
    {
        try {
            $task = \App\Models\Task::find($taskId);
            $user = User::findById($assigneeId);
            if (!$task || !$user || !$user['is_active']) {
                return;
            }

            $link = Setting::appUrl() . '/tasks/' . $taskId;
            $subject = $isNew
                ? "Вас призначено виконавцем нової задачі «{$task['title']}»"
                : "Вас призначено виконавцем задачі «{$task['title']}»";
            $due = !empty($task['due_date']) ? "\nТермін виконання: {$task['due_date']}." : '';
            $body = "Доброго дня, {$user['full_name']}!\n\n{$subject} (проєкт «{$task['project_name']}»).{$due}\n\nПереглянути задачу:\n{$link}\n\nЦе автоматичний лист.";
            $result = MailerService::send($user['email'], $user['full_name'], $subject, $body);
            if (!$result['ok']) {
                Audit::log('task', $taskId, 'notification_failed', null, ['to' => $user['email'], 'error' => mb_substr($result['message'], 0, 200)]);
            }
        } catch (\Throwable) {
        }
    }

    // ---------- Нагадування про термін виконання задачі (викликається з cron) ----------

    /**
     * Перевіряє незакриті задачі з призначеним виконавцем на три пороги:
     * 2 дні до терміну, 1 день до терміну, настання терміну (включно з уже
     * простроченими, якщо нагадування про це ще не надсилалось). Кожне
     * нагадування — рівно один раз на задачу (UNIQUE у task_due_reminders).
     *
     * @return array{sent: int, skipped_reason: string|null}
     */
    public static function sendDueDateReminders(): array
    {
        if (!self::remindersEnabled()) {
            return ['sent' => 0, 'skipped_reason' => 'Сповіщення вимкнені або SMTP не налаштовано'];
        }

        $sent = 0;
        $sent += self::sendRemindersForThreshold('2d', '+2 days', exact: true);
        $sent += self::sendRemindersForThreshold('1d', '+1 day', exact: true);
        $sent += self::sendRemindersForThreshold('due', 'today', exact: false); // exact=false: і сьогодні, і вже прострочені
        return ['sent' => $sent, 'skipped_reason' => null];
    }

    private static function sendRemindersForThreshold(string $type, string $dateExpr, bool $exact): int
    {
        $targetDate = date('Y-m-d', strtotime($dateExpr));
        $comparison = $exact ? 't.due_date = :target_date' : 't.due_date <= :target_date';

        $stmt = \App\Core\Database::connection()->prepare(
            "SELECT t.id, t.title, t.due_date, t.assignee_id, p.name AS project_name
             FROM tasks t
             JOIN task_statuses ts ON ts.id = t.status_id
             JOIN projects p ON p.id = t.project_id
             LEFT JOIN task_due_reminders r ON r.task_id = t.id AND r.reminder_type = :type
             WHERE ts.is_closed = 0 AND t.assignee_id IS NOT NULL AND r.id IS NULL AND {$comparison}"
        );
        $stmt->execute(['type' => $type, 'target_date' => $targetDate]);
        $tasks = $stmt->fetchAll();

        $sent = 0;
        foreach ($tasks as $task) {
            try {
                $user = User::findById((int) $task['assignee_id']);
                if ($user && $user['is_active']) {
                    $label = match ($type) {
                        '2d' => 'через 2 дні',
                        '1d' => 'завтра',
                        default => $task['due_date'] < date('Y-m-d') ? 'вже прострочено' : 'сьогодні',
                    };
                    $link = Setting::appUrl() . '/tasks/' . $task['id'];
                    $subject = "Нагадування: термін задачі «{$task['title']}» — {$label}";
                    $body = "Доброго дня, {$user['full_name']}!\n\n"
                        . "Термін виконання задачі «{$task['title']}» (проєкт «{$task['project_name']}») — {$task['due_date']} ({$label}).\n\n"
                        . "Переглянути задачу:\n{$link}\n\nЦе автоматичний лист.";
                    $result = MailerService::send($user['email'], $user['full_name'], $subject, $body);
                    if (!$result['ok']) {
                        continue; // не позначаємо як надіслане — спробуємо знову наступного запуску
                    }
                }

                // Позначаємо оброблено навіть якщо виконавець неактивний — щоб не перевіряти його щоразу.
                \App\Core\Database::connection()
                    ->prepare('INSERT IGNORE INTO task_due_reminders (task_id, reminder_type) VALUES (:task_id, :type)')
                    ->execute(['task_id' => $task['id'], 'type' => $type]);
                $sent++;
            } catch (\Throwable) {
                continue;
            }
        }
        return $sent;
    }
}
