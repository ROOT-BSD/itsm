<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\ImapClient;
use App\Core\ImapException;
use App\Core\MimeParser;
use App\Models\Attachment;
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

    /**
     * Що не так у налаштуваннях підключення (.env), або null, якщо все гаразд. Перевіряється ДО будь-якої
     * спроби з'єднання: помилку налаштування має бути видно як помилку налаштування, а не як загадкову
     * мережеву («No route to host» на порт 0), і пароль не повинен піти в мережу за хибних параметрів.
     */
    public static function configProblem(): ?string
    {
        $c = Config::get('mail', []);

        $encryption = (string) ($c['encryption'] ?? '');
        if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
            return "Невідоме значення MAIL_IMAP_ENCRYPTION: «{$encryption}». Допустимо: ssl (порт 993), tls або starttls (STARTTLS, порт 143), none. "
                . 'Підключення не виконується, щоб пароль скриньки не пішов у мережу без шифрування.';
        }

        $port = (string) ($c['port'] ?? '');
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            return "MAIL_IMAP_PORT має бути цілим числом від 1 до 65535, а в .env зараз: «{$port}». "
                . 'Зазвичай 993 для ssl і 143 для tls/none. Можна взагалі не вказувати — тоді підставиться за типом шифрування.';
        }

        $host = (string) ($c['host'] ?? '');
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $host) || preg_match('/\s/', $host)
            || (str_contains($host, ':') && !str_starts_with($host, '['))) {
            return "MAIL_IMAP_HOST має бути лише іменем сервера (наприклад, mail.example.org) — без «imap://», пробілів і порту; "
                . "порт вказується окремо в MAIL_IMAP_PORT. Зараз: «{$host}».";
        }
        return null;
    }

    private static function connect(): ImapClient
    {
        if (($problem = self::configProblem()) !== null) {
            throw new ImapException($problem);
        }
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

        // Зображення PNG/JPG відокремлюємо від решти вкладень ДО створення тікета: у примітці «не збережено»
        // мають лишитись лише справді не збережені файли, а підробки/завеликі не повинні потрапити на диск.
        [$images, $m['attachments'], $skippedTiny] = self::partitionImages($m['attachments']);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        $storedFiles = []; // файли на диску, записані цим листом — щоб прибрати їх, якщо транзакцію відкотить
        try {
            $ticket = self::findTicketForReply($m);
            if ($ticket !== null) {
                self::addReplyComment($ticket, $m);
                $ticketId = (int) $ticket['id'];
                $outcome = ['action' => 'comment_added', 'ticket_id' => $ticketId, 'note' => 'Коментар до тікета #' . $ticketId];
            } else {
                $ticketId = self::createTicket($m);
                $outcome = ['action' => 'ticket_created', 'ticket_id' => $ticketId, 'note' => 'Створено тікет #' . $ticketId];
            }

            $savedImages = self::storeImages($ticketId, $images, $storedFiles);
            if ($savedImages > 0) {
                $outcome['note'] .= '; збережено зображень: ' . $savedImages;
            }
            if ($skippedTiny > 0) {
                $outcome['note'] .= '; пропущено дрібних вбудованих зображень (логотипи в підписах): ' . $skippedTiny;
            }

            self::log($messageId, $m['from_email'], $m['subject'], $outcome['action'], $outcome['ticket_id'], $outcome['note']);
            $pdo->commit();

            // Навмисно ПІСЛЯ commit(): збій надсилання листа (SMTP недоступний тощо)
            // не повинен відкотити вже успішно створений тікет. MailerService::send()
            // сам ловить SmtpException і повертає ['ok' => false, ...] — сюди виняток
            // не долітає в жодному разі.
            if ($outcome['action'] === 'ticket_created' && Setting::get('email_autoreply_enabled', '0') === '1') {
                self::sendAutoReply((int) $outcome['ticket_id'], $m);
            }

            return $outcome;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Рядки вкладень відкотились разом із транзакцією, а файли на диску — ні: прибираємо їх явно.
            AttachmentService::deleteFiles($storedFiles);
            throw $e;
        }
    }

    /**
     * Відділяє зображення від решти вкладень листа. Повертає [зображення для збереження, решта вкладень, кількість
     * пропущених дрібних вбудованих зображень].
     *
     * Зображення зберігається, лише якщо вміст ДІЙСНО PNG/JPG (за сигнатурою й розбором, а не за назвою чи MIME від
     * відправника). Вбудовані (inline) зображення менші за email_min_inline_bytes — це переважно логотипи й іконки
     * в підписах, які інакше засмічували б кожен тікет і кожну відповідь, — пропускаються. Звичайні вкладення
     * зберігаються незалежно від розміру. Однакові зображення (за хешем) зберігаються один раз.
     *
     * @param array<int, array<string, mixed>> $attachments
     * @return array{0: array<int, array{name: string, mime: string, bytes: string}>, 1: array<int, array<string, mixed>>, 2: int}
     */
    private static function partitionImages(array $attachments): array
    {
        $maxImages = (int) Config::get('attachments.email_max_images', 10);
        $minInline = (int) Config::get('attachments.email_min_inline_bytes', 10240);
        $images = [];
        $rest = [];
        $seen = [];
        $skippedTiny = 0;

        foreach ($attachments as $a) {
            if (!isset($a['data'])) {
                $rest[] = $a; // не кандидат у зображення (PDF, документ…) або завелике — лише в примітку
                continue;
            }
            $bytes = $a['data'];
            unset($a['data']); // звільняємо пам'ять: далі потрібні тільки метадані

            $hash = sha1($bytes);
            if (isset($seen[$hash])) {
                continue;
            }
            $name = ($a['name'] === '' || str_starts_with($a['name'], '(')) ? 'image-' . (count($images) + 1) : $a['name'];
            $check = AttachmentService::inspectBytes($name, $bytes, ['mimes' => AttachmentService::IMAGE_MIMES]);
            if (!$check['ok']) {
                $rest[] = $a; // назвалося зображенням, але ним не є (або завелике) — не зберігаємо
                continue;
            }
            $isInline = ($a['content_id'] ?? '') !== '' || ($a['disposition'] ?? '') === 'inline';
            if ($isInline && $check['size'] < $minInline) {
                $seen[$hash] = true;
                $skippedTiny++;
                continue;
            }
            if (count($images) >= $maxImages) {
                $rest[] = $a;
                continue;
            }
            $seen[$hash] = true;
            $images[] = ['name' => $check['name'], 'mime' => $check['mime'], 'bytes' => $bytes];
        }

        return [$images, $rest, $skippedTiny];
    }

    /**
     * Записує перевірені зображення листа як вкладення тікета (рядок у БД — у транзакції ingest()).
     * Збій запису одного файлу не скасовує решту й тим більше сам тікет — лише фіксується в журналі аудиту.
     *
     * @param array<int, array{name: string, mime: string, bytes: string}> $images
     * @param string[] $storedFiles заповнюється іменами записаних файлів (для прибирання при відкаті)
     */
    private static function storeImages(int $ticketId, array $images, array &$storedFiles): int
    {
        $saved = 0;
        $room = (int) Config::get('attachments.max_per_entity', 30) - Attachment::countForOwner('ticket', $ticketId);

        foreach ($images as $img) {
            if ($room <= 0) {
                Audit::log('ticket', $ticketId, 'attachment_from_email_failed', null, ['file' => $img['name'], 'error' => 'досягнуто ліміт вкладень на тікет']);
                continue;
            }
            $written = AttachmentService::writeBytes($img['bytes']);
            if (!$written['ok']) {
                Audit::log('ticket', $ticketId, 'attachment_from_email_failed', null, ['file' => $img['name'], 'error' => mb_substr($written['error'], 0, 150)]);
                continue;
            }
            $storedFiles[] = $written['stored_name'];
            $size = strlen($img['bytes']);
            Attachment::create('ticket', $ticketId, $img['name'], $written['stored_name'], $img['mime'], $size, null, 'email');
            Audit::log('ticket', $ticketId, 'attachment_from_email', null, ['file' => $img['name'], 'size_kb' => (int) ceil($size / 1024)]);
            $saved++;
            $room--;
        }
        return $saved;
    }

    /** Автовідповідь заявнику з посиланням для відстеження — лише при СТВОРЕННІ тікета, не на кожен коментар у гілці. */
    private static function sendAutoReply(int $ticketId, array $m): void
    {
        $ticket = Ticket::find($ticketId);
        if (!$ticket || empty($ticket['access_token'])) {
            return;
        }

        $trackUrl = Setting::appUrl() . '/support/track/' . $ticket['access_token'];
        $greeting = $m['from_name'] !== '' ? "Доброго дня, {$m['from_name']}!" : 'Доброго дня!';
        $body = "{$greeting}\n\n"
            . "Ваше звернення «{$ticket['subject']}» зареєстровано під номером #{$ticketId}.\n\n"
            . "Відстежити статус і, за потреби, додати повідомлення можна за посиланням:\n{$trackUrl}\n\n"
            . "Це автоматичний лист — відповідати на нього не потрібно, скористайтесь посиланням вище.";

        $result = MailerService::send($m['from_email'], $m['from_name'], "Ваше звернення отримано [#{$ticketId}]", $body, $m['message_id'] ?: null);
        if (!$result['ok']) {
            Audit::log('ticket', $ticketId, 'autoreply_failed', null, ['error' => mb_substr($result['message'], 0, 200)]);
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
            throw new \RuntimeException('У системі немає жодної черги тікетів — створіть чергу (Адмін-панель → Керування → Черги тікетів).');
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

    /** Вкладення, які НЕ збережено (не зображення PNG/JPG, завеликі чи не пройшли перевірку), — чесно називаємо їх у тексті. */
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
