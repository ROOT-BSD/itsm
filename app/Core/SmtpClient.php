<?php

namespace App\Core;

/**
 * Мінімальний SMTP-клієнт (RFC 5321) без залежностей — той самий підхід, що й
 * ImapClient: власна реалізація замість ext-imap/PECL/Composer.
 *
 * Реалізовано рівно те, що потрібно для надсилання однієї автовідповіді:
 * з'єднання (SSL / STARTTLS / без шифрування), EHLO, AUTH LOGIN, MAIL FROM,
 * RCPT TO, DATA. Без черги повідомлень, без вкладень, без кількох одержувачів —
 * для цього функціоналу цього достатньо.
 */
class SmtpClient
{
    /** @var resource|null */
    private $stream = null;

    public function __construct(
        private string $host,
        private int $port = 587,
        private string $encryption = 'tls', // ssl | tls (STARTTLS) | none
        private bool $verifyCert = true,
        private int $timeout = 15
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
            throw new SmtpException($this->explainConnectError(trim($errstr . ' ' . implode(' ', $warnings))));
        }
        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;

        $greeting = $this->readMultiline();
        if (!str_starts_with($greeting['code'], '2')) {
            throw new SmtpException('Сервер відхилив з\'єднання: ' . $greeting['text']);
        }

        $this->ehlo();

        if ($encryption === 'tls') {
            $response = $this->command('STARTTLS');
            if (!str_starts_with($response['code'], '2')) {
                throw new SmtpException('Сервер не підтримує STARTTLS: ' . $response['text']);
            }
            if (!@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new SmtpException('Не вдалося увімкнути TLS (STARTTLS).'
                    . ($this->verifyCert ? ' Якщо сертифікат самопідписаний — вкажіть MAIL_SMTP_VERIFY_CERT=false у .env.' : ''));
            }
            $this->ehlo(); // після STARTTLS сервер повторно представляється — повторюємо EHLO в тому самому з'єднанні
        }
    }

    private function ehlo(): void
    {
        $response = $this->command('EHLO ' . (gethostname() ?: 'localhost'));
        if (!str_starts_with($response['code'], '2')) {
            throw new SmtpException('Сервер відхилив EHLO: ' . $response['text']);
        }
    }

    public function login(string $username, string $password): void
    {
        $response = $this->command('AUTH LOGIN');
        if ($response['code'] === '503') {
            return; // уже автентифіковано (рідкісний випадок) — не помилка
        }
        if (!str_starts_with($response['code'], '3')) {
            throw new SmtpException('Сервер не підтримує AUTH LOGIN: ' . $response['text']);
        }

        $response = $this->command(base64_encode($username));
        if (!str_starts_with($response['code'], '3')) {
            throw new SmtpException('Сервер відхилив ім\'я користувача: ' . $response['text']);
        }

        $response = $this->command(base64_encode($password));
        if (!str_starts_with($response['code'], '2')) {
            throw new SmtpException('Помилка автентифікації: ' . ($response['text'] ?: 'сервер відхилив логін/пароль'));
        }
    }

    /** @param string[] $headerLines кожен рядок без завершального \r\n */
    public function send(string $from, string $to, array $headerLines, string $body): void
    {
        $response = $this->command('MAIL FROM:<' . $from . '>');
        if (!str_starts_with($response['code'], '2')) {
            throw new SmtpException("Сервер відхилив відправника «{$from}»: " . $response['text']);
        }

        $response = $this->command('RCPT TO:<' . $to . '>');
        if (!str_starts_with($response['code'], '2')) {
            throw new SmtpException("Сервер відхилив одержувача «{$to}»: " . $response['text']);
        }

        $response = $this->command('DATA');
        if (!str_starts_with($response['code'], '3')) {
            throw new SmtpException('Сервер відхилив команду DATA: ' . $response['text']);
        }

        // Байт-стаффінг: рядок, що починається з крапки, подвоюється — інакше сервер
        // сприйняв би його як кінець повідомлення (RFC 5321, п. 4.5.2).
        $message = implode("\r\n", $headerLines) . "\r\n\r\n" . $body;
        $message = preg_replace('/\r\n\./', "\r\n..", $message) ?? $message;
        if (str_starts_with($message, '.')) {
            $message = '.' . $message;
        }

        $this->write($message . "\r\n.\r\n");
        $response = $this->readMultiline();
        if (!str_starts_with($response['code'], '2')) {
            throw new SmtpException('Сервер відхилив лист: ' . $response['text']);
        }
    }

    public function quit(): void
    {
        if ($this->stream) {
            try {
                $this->command('QUIT');
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

    /** @return array{code: string, text: string} */
    private function command(string $line): array
    {
        $this->write($line . "\r\n");
        return $this->readMultiline();
    }

    /**
     * SMTP-відповідь може бути багаторядковою: "250-перший\r\n250-другий\r\n250 останній\r\n"
     * (дефіс = буде продовження, пробіл = останній рядок).
     *
     * @return array{code: string, text: string}
     */
    private function readMultiline(): array
    {
        $code = '';
        $lines = [];
        do {
            $line = $this->readLine();
            $code = substr($line, 0, 3);
            $lines[] = trim(substr($line, 4));
            $isLast = strlen($line) < 4 || $line[3] !== '-';
        } while (!$isLast);
        return ['code' => $code, 'text' => implode(' ', array_filter($lines))];
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

    private function write(string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $n = @fwrite($this->stream, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new SmtpException('Не вдалося надіслати дані серверу — з\'єднання розірвано.');
            }
            $written += $n;
        }
    }

    private function failRead(): never
    {
        $meta = is_resource($this->stream) ? stream_get_meta_data($this->stream) : [];
        throw new SmtpException(!empty($meta['timed_out'])
            ? "Тайм-аут очікування відповіді від сервера ({$this->timeout} с)."
            : 'З\'єднання з сервером розірвано.');
    }

    /** Технічну причину збою підключення — в зрозумілий адміністратору опис із підказкою, що виправити. */
    private function explainConnectError(string $detail): string
    {
        $target = "{$this->host}:{$this->port}";
        $detail = trim((string) preg_replace('/^stream_socket_client\(\): /', '', $detail));

        if (preg_match('/certificate verify failed|self[- ]signed|unable to get local issuer|hostname mismatch|did not match/i', $detail)) {
            return "Сертифікат сервера {$target} не пройшов перевірку (самопідписаний, прострочений або не збігається ім'я хоста). "
                . 'Для внутрішнього сервера з власним сертифікатом вкажіть MAIL_SMTP_VERIFY_CERT=false у .env.';
        }
        if (preg_match('/wrong version number|unexpected eof|handshake|ssl routines|SSL operation failed/i', $detail)) {
            return "Не вдалося встановити захищене з'єднання з {$target}. Найчастіше причина — невідповідність порту та типу шифрування: "
                . 'порт 465 потребує MAIL_SMTP_ENCRYPTION=ssl, порт 587/25 — tls (STARTTLS) або none.';
        }
        if (stripos($detail, 'refused') !== false) {
            return "Сервер {$target} відхилив з'єднання — перевірте адресу, порт і що SMTP увімкнено на сервері.";
        }
        if (stripos($detail, 'getaddrinfo') !== false || stripos($detail, 'name or service not known') !== false) {
            return "Не вдалося знайти сервер «{$this->host}» — перевірте MAIL_SMTP_HOST.";
        }
        if (stripos($detail, 'timed out') !== false) {
            return "Сервер {$target} не відповів за {$this->timeout} с — перевірте адресу та мережевий доступ (файрвол).";
        }
        return "Не вдалося підключитися до {$target}" . ($detail !== '' ? " — {$detail}" : '') . '.';
    }
}
