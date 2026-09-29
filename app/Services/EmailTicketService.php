<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\ImapClient;
use App\Core\ImapException;
use App\Core\MimeParser;
use App\Models\Audit;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;

/**
 * Email-to-ticket: перетворення вхідних листів поштової скриньки на тікети й коментарі.
 *
 * Правила обробки одного листа (ingest):
 *  1. Уже оброблений (Message-ID є в журналі) → нічого не робимо.
 *  2. Автовідповіді, розсилки, повідомлення про недоставку, листи від самої
 *     скриньки → пропускаємо (з причиною в журналі), щоб не створювати
 *     тікетів із сміття й не потрапити в цикл автовідповідей.
 *  3. Лист — відповідь у наявній гілці → коментар до існуючого тікета:
 *     а) за In-Reply-To/References, що збігаються з Message-ID раніше
 *        обробленого листа (відправник може бути будь-хто з гілки);
 *     б) за міткою [#123] у темі — лише якщо відправник = заявник тікета
 *        (номер тікета легко вгадати, на відміну від Message-ID).
 *  4. Інакше — новий тікет у налаштованій черзі. Якщо email відправника
 *     збігається з активним користувачем — тікет прив'язується до нього.
 *
 * Тікет/коментар і запис журналу створюються в одній транзакції: або все, або нічого.
 */
class EmailTicketService
{
    private const LOCK_NAME = 'itsm_email_fetch';
    private const MAX_MESSAGES_PER_RUN = 50;
    private const MAX_MESSAGE_BYTES = 10 * 1024 * 1024;
    private const MAX_BODY_CHARS = 20000;
    // Колонка TEXT вміщує 65 535 БАЙТІВ, а не символів: 20 000 символів-емодзі (по 4 байти) — це вже 80 000 байтів.
    // Лишаємо запас під приписку про скорочення та примітку про вкладення.
    private const MAX_BODY_BYTES = 60000;

    public static function isConfigured(): bool
    {
        $c = Config::get('mail', []);
        return ($c['host'] ?? '') !== '' && ($c['username'] ?? '') !== '' && ($c['password'] ?? '') !== '';
    }

    private static function connect(): ImapClient
    {
        $c = Config::get('mail', []);
        $client = new ImapClient($c['host'], (int) $c['port'], $c['encryption'], (bool) $c['verify_cert']);
        $client->connect();
        $client->login($c['username'], $c['password']);
        return $client;
    }

    /** Перевірка підключення для адмін-панелі: логін + відкриття папки + кількість листів. */
    public static function testConnection(): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'message' => 'Підключення не налаштовано: заповніть MAIL_IMAP_HOST, MAIL_IMAP_USERNAME та MAIL_IMAP_PASSWORD у файлі .env.'];
        }
        try {
            $client = self::connect();
            $total = $client->select(Config::get('mail.folder', 'INBOX'));
            $unseen = count($client->searchUnseen());
            $client->logout();
            return ['ok' => true, 'message' => "З'єднання успішне. У папці листів: {$total}, з них непрочитаних: {$unseen}."];
        } catch (ImapException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Повний цикл: підключення → непрочитані листи → тікети/коментарі → позначка «прочитано».
     * Лист позначається прочитаним лише після успішної обробки; лист, що не вдалося
     * обробити (наприклад, збій БД), лишається непрочитаним і буде повторено наступного разу.
     *
     * @return array{status: string, message: string, created: int, comments: int, skipped: int, duplicates: int, errors: string[]}
     */
    public static function run(): array
    {
        $result = ['status' => 'ok', 'message' => '', 'created' => 0, 'comments' => 0, 'skipped' => 0, 'duplicates' => 0, 'errors' => []];

        if (!self::isConfigured()) {
            return self::finish($result, 'error', 'Підключення не налаштовано (.env).');
        }

        // Захист від паралельних запусків (cron + кнопка «забрати зараз»).
        $pdo = Database::connection();
        if (!(int) $pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn()) {
            return self::finish($result, 'busy', 'Обробка пошти вже виконується — пропущено.');
        }

        try {
            $client = self::connect();
            $client->select(Config::get('mail.folder', 'INBOX'));
            $uids = array_slice($client->searchUnseen(), 0, self::MAX_MESSAGES_PER_RUN);

            foreach ($uids as $uid) {
                try {
                    $size = $client->fetchSize($uid);
                    if ($size !== null && $size > self::MAX_MESSAGE_BYTES) {
                        // Тіло не завантажуємо, але заголовки читаємо — щоб у журналі було видно, від кого лист.
                        $outcome = self::skipOversized($client->fetchHeaders($uid), $uid, $size);
                        $client->markSeen($uid);
                        $outcome['action'] === 'duplicate' ? $result['duplicates']++ : $result['skipped']++;
                        continue;
                    }

                    $outcome = self::ingest($client->fetchRaw($uid));
                    $client->markSeen($uid);

                    match ($outcome['action']) {
                        'ticket_created' => $result['created']++,
                        'comment_added' => $result['comments']++,
                        'duplicate' => $result['duplicates']++,
                        default => $result['skipped']++,
                    };
                } catch (ImapException $e) {
                    throw $e; // збій зв'язку — далі йти немає сенсу
                } catch (\Throwable $e) {
                    $result['errors'][] = "UID {$uid}: " . $e->getMessage();
                }
            }
            $client->logout();
        } catch (ImapException $e) {
            return self::finish($result, 'error', $e->getMessage());
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }

        $message = "Нових тікетів: {$result['created']}, коментарів: {$result['comments']}, пропущено: {$result['skipped']}";
        if ($result['duplicates'] > 0) {
            $message .= ", вже оброблених: {$result['duplicates']}";
        }
        if ($result['errors']) {
            $message .= ', помилок: ' . count($result['errors']) . ' (' . mb_substr($result['errors'][0], 0, 120) . ')';
        }
        return self::finish($result, $result['errors'] ? 'partial' : 'ok', $message);
    }

    /** Запам'ятовує підсумок останнього запуску (показується в адмін-панелі). */
    private static function finish(array $result, string $status, string $message): array
    {
        $result['status'] = $status;
        $result['message'] = $message;
        if ($status !== 'busy') {
            Setting::set('email_last_run_at', date('Y-m-d H:i:s'));
            Setting::set('email_last_run_summary', mb_substr(($status === 'error' ? 'Помилка: ' : '') . $message, 0, 250));
        }
        return $result;
    }

    /**
     * Обробка одного сирого листа.
     *
     * @return array{action: string, ticket_id: ?int, note: string}
     *         action: ticket_created | comment_added | skipped | duplicate
     */
    public static function ingest(string $raw): array
    {
        $m = MimeParser::parse($raw);
        $messageId = self::stableMessageId($m['message_id'], $raw);

        if (self::alreadyProcessed($messageId)) {
            return ['action' => 'duplicate', 'ticket_id' => null, 'note' => 'Уже оброблено'];
        }

        $skipReason = self::skipReason($m);
        if ($skipReason !== null) {
            self::log($messageId, $m['from_email'], $m['subject'], 'skipped', null, $skipReason);
            return ['action' => 'skipped', 'ticket_id' => null, 'note' => $skipReason];
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $ticket = self::findTicketForReply($m);
            if ($ticket !== null) {
                self::addReplyComment($ticket, $m);
                $outcome = ['action' => 'comment_added', 'ticket_id' => (int) $ticket['id'], 'note' => 'Коментар до тікета #' . $ticket['id']];
            } else {
                $ticketId = self::createTicket($m);
                $outcome = ['action' => 'ticket_created', 'ticket_id' => $ticketId, 'note' => 'Створено тікет #' . $ticketId];
            }
            self::log($messageId, $m['from_email'], $m['subject'], $outcome['action'], $outcome['ticket_id'], $outcome['note']);
            $pdo->commit();
            return $outcome;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{action: string, ticket_id: null, note: string} */
    private static function skipOversized(string $rawHeaders, int $uid, int $size): array
    {
        $m = MimeParser::parse($rawHeaders);
        $messageId = self::stableMessageId($m['message_id'], 'size:' . $uid . ':' . $size);
        if (self::alreadyProcessed($messageId)) {
            return ['action' => 'duplicate', 'ticket_id' => null, 'note' => 'Уже оброблено'];
        }
        $note = 'Занадто великий лист (' . round($size / 1048576, 1) . ' МБ, ліміт ' . (self::MAX_MESSAGE_BYTES / 1048576) . ' МБ)';
        self::log($messageId, $m['from_email'], $m['subject'], 'skipped', null, $note);
        return ['action' => 'skipped', 'ticket_id' => null, 'note' => $note];
    }

    private static function stableMessageId(string $messageId, string $raw): string
    {
        $id = $messageId !== '' ? $messageId : 'hash:' . sha1($raw);
        // колонка VARCHAR(255): нетипово довгий Message-ID замінюємо його хешем
        return mb_strlen($id) > 255 ? 'hash:' . sha1($id) : $id;
    }

    private static function alreadyProcessed(string $messageId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM email_ingest_log WHERE message_id = :id');
        $stmt->execute(['id' => $messageId]);
        return (bool) $stmt->fetchColumn();
    }

    private static function skipReason(array $m): ?string
    {
        $h = $m['headers'];

        if ($m['from_email'] === '') {
            return 'Не вдалося визначити адресу відправника';
        }
        if (strlen($m['from_email']) > 150) {
            return 'Занадто довга адреса відправника';
        }

        $auto = strtolower(trim($h['auto-submitted'][0] ?? ''));
        if ($auto !== '' && $auto !== 'no') {
            return "Автоматичний лист (Auto-Submitted: {$auto})";
        }
        $precedence = strtolower(trim($h['precedence'][0] ?? ''));
        if (in_array($precedence, ['bulk', 'junk', 'list', 'auto_reply'], true)) {
            return "Масова розсилка (Precedence: {$precedence})";
        }
        if (isset($h['list-id'])) {
            return 'Розсилка (заголовок List-Id)';
        }
        if (trim($h['return-path'][0] ?? '') === '<>' || preg_match('/^(mailer-daemon|postmaster)@/i', $m['from_email'])) {
            return 'Повідомлення про недоставку';
        }

        // Лист від самої скриньки (напр., в майбутньому — наші ж сповіщення) — запобігає циклам.
        $mailbox = strtolower((string) Config::get('mail.username', ''));
        if ($mailbox !== '' && $mailbox === $m['from_email']) {
            return 'Лист від самої поштової скриньки (захист від циклу)';
        }

        if ($m['subject'] === '' && $m['text'] === '') {
            return 'Порожній лист';
        }
        return null;
    }

    /** Тікет, до якого належить цей лист як відповідь, або null (тоді буде створено новий). */
    private static function findTicketForReply(array $m): ?array
    {
        // а) гілка листування: Message-ID раніше обробленого листа в In-Reply-To / References
        $ids = array_values(array_unique(array_merge($m['in_reply_to'], $m['references'])));
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = Database::connection()->prepare(
                "SELECT ticket_id FROM email_ingest_log
                 WHERE message_id IN ({$placeholders}) AND ticket_id IS NOT NULL
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute($ids);
            $ticketId = $stmt->fetchColumn();
            if ($ticketId && ($ticket = Ticket::find((int) $ticketId))) {
                return $ticket;
            }
        }

        // б) мітка [#123] в темі — лише від заявника (номер тікета вгадується, тож без перевірки відправника
        //    хто завгодно міг би дописувати в чужі тікети)
        if (preg_match('/\[#(\d+)\]/', $m['subject'], $tm)) {
            $ticket = Ticket::find((int) $tm[1]);
            if ($ticket && strcasecmp($ticket['requester_email'], $m['from_email']) === 0) {
                return $ticket;
            }
        }
        return null;
    }

    private static function addReplyComment(array $ticket, array $m): void
    {
        $user = self::activeUserByEmail($m['from_email']);

        $body = self::stripQuotedReply($m['text']);
        if ($body === '') {
            $body = $m['text'] !== '' ? $m['text'] : '(лист без тексту)';
        }
        $body = self::truncate($body);

        // Відповіла інша людина з гілки (напр., колега в копії) — вказуємо, хто саме.
        if (strcasecmp($ticket['requester_email'], $m['from_email']) !== 0) {
            $who = $m['from_name'] !== '' ? "{$m['from_name']} <{$m['from_email']}>" : $m['from_email'];
            $body = "Від: {$who}\n\n" . $body;
        }
        $body .= self::attachmentsNote($m['attachments']);

        // Завжди 'requester', навіть якщо адреса збігається з оператором: From легко підробити,
        // а коментар оператора зачіпає SLA (first_response_at).
        Ticket::addComment((int) $ticket['id'], 'requester', $user ? (int) $user['id'] : null, $body);
        Audit::log('ticket', (int) $ticket['id'], 'comment_from_email', null, ['from' => $m['from_email']]);
    }

    private static function createTicket(array $m): int
    {
        $user = self::activeUserByEmail($m['from_email']);
        $name = $m['from_name'] !== '' ? $m['from_name'] : explode('@', $m['from_email'])[0];

        $subject = trim((string) preg_replace('/^(\s*(re|fw|fwd|відп|пер)(\[\d+\])?\s*:\s*)+/iu', '', $m['subject']));
        $subject = $subject !== '' ? mb_substr($subject, 0, 255) : '(без теми)';

        $description = self::truncate($m['text']) . self::attachmentsNote($m['attachments']);

        $id = Ticket::create(
            self::targetQueueId(),
            mb_substr($name, 0, 150),
            $m['from_email'],
            $user ? (int) $user['id'] : null,
            $subject,
            trim($description) !== '' ? trim($description) : null
        );
        Audit::log('ticket', $id, 'created_from_email', null, ['from' => $m['from_email']]);
        return $id;
    }

    private static function activeUserByEmail(string $email): ?array
    {
        $user = User::findByEmail($email);
        return ($user && (int) $user['is_active'] === 1) ? $user : null;
    }

    /** Черга з налаштувань; 0 або неіснуюча — перша наявна. */
    private static function targetQueueId(): int
    {
        $queues = Ticket::queues();
        if (!$queues) {
            throw new \RuntimeException('У системі немає жодної черги тікетів — створіть чергу (Адмін-панель → Черги тікетів).');
        }
        $wanted = (int) Setting::get('email_ticket_queue_id', '0');
        foreach ($queues as $q) {
            if ((int) $q['id'] === $wanted) {
                return $wanted;
            }
        }
        return (int) $queues[0]['id'];
    }

    private static function truncate(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_BODY_CHARS && strlen($text) <= self::MAX_BODY_BYTES) {
            return $text;
        }
        // mb_strcut ріже за байтами, але по межі символу — не залишає напівсимвола
        $cut = mb_strcut(mb_substr($text, 0, self::MAX_BODY_CHARS), 0, self::MAX_BODY_BYTES, 'UTF-8');
        return $cut . "\n\n[… текст листа скорочено]";
    }

    /** Вкладення не зберігаються (модуля файлів ще немає) — чесно про це повідомляємо в тексті. */
    private static function attachmentsNote(array $attachments): string
    {
        if (!$attachments) {
            return '';
        }
        $names = array_map(fn($a) => $a['name'], array_slice($attachments, 0, 10));
        $more = count($attachments) > 10 ? ' та ще ' . (count($attachments) - 10) : '';
        return "\n\n[Вкладення не збережено в системі: " . implode(', ', $names) . $more . ']';
    }

    /**
     * Відрізає процитований попередній лист: усе після рядка «On … wrote:» /
     * «… написав(ла):» / «-----Original Message-----» / Outlook-блоку «From: … Sent: …»,
     * а також хвостові рядки-цитати «> …». Рядок «… пише:» вважається початком цитати
     * лише якщо містить адресу або час — щоб не відрізати звичайне речення.
     */
    public static function stripQuotedReply(string $text): string
    {
        $lines = explode("\n", $text);
        $out = [];
        foreach ($lines as $i => $line) {
            $t = trim($line);

            if (preg_match('/^-{2,}\s*(Original Message|Forwarded message|Початкове повідомлення|Вихідне повідомлення)/iu', $t)) {
                break;
            }
            $looksLikeHeader = preg_match('/(<[^>\s]+@[^>\s]+>|\d{1,2}:\d{2})/u', $t);
            if ($looksLikeHeader && preg_match('/(wrote|написав|написала|написав\(ла\)|пише|писав|писала):\s*$/iu', $t)) {
                break;
            }
            if (preg_match('/^(From|Від|От):\s.+/iu', $t) && isset($lines[$i + 1])
                && preg_match('/^(Sent|Date|Надіслано|Дата|Отправлено):/iu', trim($lines[$i + 1]))) {
                break;
            }
            $out[] = $line;
        }
        while ($out && (trim(end($out)) === '' || str_starts_with(ltrim(end($out)), '>'))) {
            array_pop($out);
        }
        return trim(implode("\n", $out));
    }

    private static function log(string $messageId, string $from, string $subject, string $result, ?int $ticketId, ?string $note): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO email_ingest_log (message_id, from_email, subject, result, ticket_id, note)
             VALUES (:message_id, :from_email, :subject, :result, :ticket_id, :note)'
        );
        $stmt->execute([
            'message_id' => $messageId,
            'from_email' => mb_substr($from, 0, 150),
            'subject' => mb_substr($subject, 0, 255),
            'result' => $result,
            'ticket_id' => $ticketId,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
        ]);
    }

    /** Останні записи журналу — для адмін-панелі. */
    public static function recentLog(int $limit = 50): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM email_ingest_log ORDER BY id DESC LIMIT ' . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
