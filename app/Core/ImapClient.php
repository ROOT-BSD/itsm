<?php

namespace App\Core;

/**
 * Мінімальний IMAP-клієнт (RFC 3501) без залежностей.
 *
 * Чому власний, а не ext-imap: розширення imap вилучене з ядра PHP 8.4 і
 * часто відсутнє на серверах; проєкт свідомо працює без Composer/PECL.
 *
 * Реалізовано рівно те, що потрібно для перетворення пошти в тікети:
 * з'єднання (SSL / STARTTLS / без шифрування), LOGIN, SELECT,
 * UID SEARCH, UID FETCH, UID STORE, LOGOUT. Робота лише за UID — вони
 * стабільні, на відміну від порядкових номерів, що зсуваються при видаленні.
 */
class ImapClient
{
    /** @var resource|null */
    private $stream = null;
    private int $tagCounter = 0;

    public function __construct(
        private string $host,
        private int $port = 993,
        private string $encryption = 'ssl',   // ssl | tls (STARTTLS) | none
        private bool $verifyCert = true,
        private int $timeout = 20
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function connect(): void
    {
        $encryption = strtolower($this->encryption);
        $scheme = $encryption === 'ssl' ? 'ssl' : 'tcp';

        $context = stream_context_create(['ssl' => [
            'verify_peer' => $this->verifyCert,
            'verify_peer_name' => $this->verifyCert,
            'allow_self_signed' => !$this->verifyCert,
            'SNI_enabled' => true,
            'peer_name' => $this->host,
        ]]);

        $errno = 0;
        $errstr = '';
        // При збої TLS-рукостискання PHP видає кілька warning-ів підряд, і справжня причина (OpenSSL) — в першому,
        // а не в $errstr чи error_get_last() (там лише «Unknown error»). Тому збираємо їх усі.
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $stream = stream_socket_client(
                "{$scheme}://{$this->host}:{$this->port}",
                $errno,
                $errstr,
                $this->timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );
        } finally {
            restore_error_handler();
        }
        if (!$stream) {
            throw new ImapException($this->explainConnectError(trim($errstr . ' ' . implode(' ', $warnings))));
        }
        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;

        $greeting = $this->readLine();
        if (!preg_match('/^\* (OK|PREAUTH)/i', $greeting)) {
            throw new ImapException('Сервер відхилив з\'єднання: ' . trim($greeting));
        }

        if ($encryption === 'tls') {
            $response = $this->run('STARTTLS');
            if ($response['status'] !== 'OK') {
                throw new ImapException('Сервер не підтримує STARTTLS: ' . $response['text']);
            }
            if (!@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new ImapException('Не вдалося увімкнути TLS (STARTTLS).'
                    . ($this->verifyCert ? ' Якщо сертифікат самопідписаний — вкажіть MAIL_IMAP_VERIFY_CERT=false у .env.' : ''));
            }
        }
    }

    public function login(string $username, string $password): void
    {
        $response = $this->run('LOGIN', [$this->astring($username), $this->astring($password)]);
        if ($response['status'] !== 'OK') {
            throw new ImapException('Помилка автентифікації: ' . ($response['text'] ?: 'сервер відхилив логін/пароль'));
        }
    }

    /** @return int кількість листів у папці */
    public function select(string $folder): int
    {
        $response = $this->run('SELECT', [$this->astring($folder)]);
        if ($response['status'] !== 'OK') {
            throw new ImapException("Не вдалося відкрити папку «{$folder}»: " . $response['text']);
        }
        foreach ($response['untagged'] as $line) {
            if (preg_match('/^\* (\d+) EXISTS/i', $line, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    /** @return int[] UID непрочитаних (і не позначених до видалення) листів, від найстаріших */
    public function searchUnseen(): array
    {
        $response = $this->run('UID SEARCH', ['UNSEEN', 'UNDELETED']);
        if ($response['status'] !== 'OK') {
            throw new ImapException('Помилка пошуку листів: ' . $response['text']);
        }
        foreach ($response['untagged'] as $line) {
            if (preg_match('/^\* SEARCH\s*(.*)$/mi', $line, $m)) {
                $uids = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
                $uids = array_map('intval', $uids);
                sort($uids);
                return $uids;
            }
        }
        return [];
    }

    public function fetchSize(int $uid): ?int
    {
        $response = $this->run('UID FETCH', [(string) $uid, '(RFC822.SIZE)']);
        foreach ($response['untagged'] as $line) {
            if (preg_match('/RFC822\.SIZE\s+(\d+)/i', $line, $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /** Повний сирий лист (заголовки + тіло). BODY.PEEK — щоб читання саме по собі не ставило «прочитано». */
    public function fetchRaw(int $uid): string
    {
        return $this->fetchLiteral($uid, 'BODY.PEEK[]');
    }

    /** Лише заголовки — щоб дізнатись, від кого завеликий лист, не завантажуючи його весь. */
    public function fetchHeaders(int $uid): string
    {
        return $this->fetchLiteral($uid, 'BODY.PEEK[HEADER]');
    }

    private function fetchLiteral(int $uid, string $item): string
    {
        $response = $this->run('UID FETCH', [(string) $uid, '(' . $item . ')']);
        if ($response['status'] !== 'OK') {
            throw new ImapException("Не вдалося отримати лист UID {$uid}: " . $response['text']);
        }
        foreach ($response['untagged'] as $chunk) {
            if (!preg_match('/^\* \d+ FETCH/i', $chunk)) {
                continue;
            }
            if (preg_match('/\{(\d+)\}\r\n/', $chunk, $m, PREG_OFFSET_CAPTURE)) {
                return substr($chunk, $m[0][1] + strlen($m[0][0]), (int) $m[1][0]);
            }
            // дуже короткий лист сервер може віддати як quoted string замість literal
            if (preg_match('/BODY\[[A-Z]*\]\s+"((?:[^"\\\\]|\\\\.)*)"/s', $chunk, $m)) {
                return stripcslashes($m[1]);
            }
        }
        throw new ImapException("Сервер не повернув вміст листа UID {$uid}");
    }

    public function markSeen(int $uid): void
    {
        $response = $this->run('UID STORE', [(string) $uid, '+FLAGS.SILENT', '(\\Seen)']);
        if ($response['status'] !== 'OK') {
            throw new ImapException("Не вдалося позначити лист UID {$uid} прочитаним: " . $response['text']);
        }
    }

    public function logout(): void
    {
        if ($this->stream) {
            try {
                $this->run('LOGOUT');
            } catch (\Throwable) {
                // сервер міг уже розірвати з'єднання — це нормально
            }
            $this->close();
        }
    }

    private function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }

    /**
     * Рядок-аргумент IMAP: звичайний ASCII — у лапках, усе інше (кирилиця
     * в паролі, CR/LF, порожній рядок) — як literal {n}, інакше сервер
     * відхилить або спотворить логін/пароль.
     *
     * @return string|array{literal: string}
     */
    private function astring(string $value): string|array
    {
        if ($value !== '' && preg_match('/^[\x20-\x7E]+$/', $value)) {
            return '"' . addcslashes($value, '"\\') . '"';
        }
        return ['literal' => $value];
    }

    /**
     * @param array<string|array{literal: string}> $args
     * @return array{status: string, text: string, untagged: string[]}
     */
    private function run(string $verb, array $args = []): array
    {
        $tag = sprintf('A%04d', ++$this->tagCounter);

        $this->write($tag . ' ' . $verb);
        foreach ($args as $arg) {
            if (is_array($arg)) {
                $this->write(' {' . strlen($arg['literal']) . "}\r\n");
                $continuation = $this->readLine();
                if ($continuation === '' || $continuation[0] !== '+') {
                    throw new ImapException('Сервер відхилив передачу даних: ' . trim($continuation));
                }
                $this->write($arg['literal']);
            } else {
                $this->write(' ' . $arg);
            }
        }
        $this->write("\r\n");

        return $this->readResponse($tag);
    }

    /** @return array{status: string, text: string, untagged: string[]} */
    private function readResponse(string $tag): array
    {
        $untagged = [];
        while (true) {
            $line = $this->readLine();

            if (str_starts_with($line, $tag . ' ')) {
                preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)\b\s*(.*?)\r?\n?$/is', $line, $m);
                return ['status' => strtoupper($m[1] ?? 'BAD'), 'text' => trim($m[2] ?? ''), 'untagged' => $untagged];
            }

            // Неявна (untagged) відповідь може містити literal: {n}\r\n + рівно n байтів,
            // після яких рядок продовжується. Читаємо за довжиною, а не за роздільниками —
            // вміст листа може містити будь-які послідовності, зокрема схожі на теги.
            $chunk = $line;
            while (preg_match('/\{(\d+)\}\r\n$/', $chunk, $lm)) {
                $chunk .= $this->readBytes((int) $lm[1]);
                $chunk .= $this->readLine();
            }

            if (preg_match('/^\* BYE\b/i', $chunk)) {
                throw new ImapException('Сервер розірвав з\'єднання: ' . trim($chunk));
            }
            $untagged[] = $chunk;
        }
    }

    private function readLine(): string
    {
        $line = '';
        do {
            $chunk = fgets($this->stream, 8192);
            if ($chunk === false) {
                $this->failRead();
            }
            $line .= $chunk;
        } while (!str_ends_with($line, "\n"));
        return $line;
    }

    private function readBytes(int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($this->stream, min(65536, $length - strlen($buffer)));
            if ($chunk === false || $chunk === '') {
                $this->failRead();
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    private function write(string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $n = @fwrite($this->stream, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new ImapException('Не вдалося надіслати дані серверу — з\'єднання розірвано.');
            }
            $written += $n;
        }
    }

    /** Технічну причину збою підключення — в зрозумілий адміністратору опис із підказкою, що виправити. */
    private function explainConnectError(string $detail): string
    {
        $target = "{$this->host}:{$this->port}";
        $detail = trim((string) preg_replace('/^stream_socket_client\(\): /', '', $detail));

        if (preg_match('/certificate verify failed|self[- ]signed|unable to get local issuer|hostname mismatch|did not match/i', $detail)) {
            return "Сертифікат сервера {$target} не пройшов перевірку (самопідписаний, прострочений або не збігається ім'я хоста). "
                . 'Для внутрішнього сервера з власним сертифікатом вкажіть MAIL_IMAP_VERIFY_CERT=false у .env.';
        }
        if (preg_match('/wrong version number|unexpected eof|handshake|ssl routines|SSL operation failed/i', $detail)) {
            return "Не вдалося встановити захищене з'єднання з {$target}. Найчастіше причина — невідповідність порту та типу шифрування: "
                . 'порт 993 потребує MAIL_IMAP_ENCRYPTION=ssl, порт 143 — tls (STARTTLS) або none.';
        }
        if (stripos($detail, 'refused') !== false) {
            return "Сервер {$target} відхилив з'єднання — перевірте адресу, порт і що IMAP увімкнено на сервері.";
        }
        if (stripos($detail, 'getaddrinfo') !== false || stripos($detail, 'name or service not known') !== false) {
            return "Не вдалося знайти сервер «{$this->host}» — перевірте MAIL_IMAP_HOST.";
        }
        if (stripos($detail, 'timed out') !== false) {
            return "Сервер {$target} не відповів за {$this->timeout} с — перевірте адресу та мережевий доступ (файрвол).";
        }
        return "Не вдалося підключитися до {$target}" . ($detail !== '' ? " — {$detail}" : '') . '.';
    }

    private function failRead(): never
    {
        $meta = is_resource($this->stream) ? stream_get_meta_data($this->stream) : [];
        throw new ImapException(!empty($meta['timed_out'])
            ? "Тайм-аут очікування відповіді від сервера ({$this->timeout} с)."
            : 'З\'єднання з сервером розірвано.');
    }
}
