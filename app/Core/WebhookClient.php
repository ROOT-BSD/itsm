<?php

namespace App\Core;

/**
 * Надсилання POST-запиту вебхука. Без cURL і зовнішніх бібліотек (потокові обгортки PHP) — як і решта мережевих
 * клієнтів проєкту.
 *
 * Захист від SSRF (змушування сервера звертатись до чужих внутрішніх сервісів):
 *  - дозволено лише http/https, без логіна й пароля в адресі;
 *  - ім'я хоста РЕЗОЛВИТЬСЯ тут і перевіряється; ЗАВЖДИ заборонені link-local (169.254.0.0/16 — адреса метаданих хмарних
 *    серверів 169.254.169.254, fe80::/10), службові (0.0.0.0/8), мультикаст і зарезервовані діапазони;
 *  - внутрішні адреси (10/8, 172.16/12, 192.168/16, 127/8…) дозволені за замовчуванням — у корпоративній мережі
 *    вебхуки на внутрішні сервіси є нормою; заборонити їх можна параметром WEBHOOK_ALLOW_PRIVATE=false в .env;
 *  - з'єднання йде на ПЕРЕВІРЕНУ IP-адресу (а не на ім'я, яке могло б «перерезолвитись» на іншу між перевіркою й запитом —
 *    DNS rebinding); ім'я лишається в заголовку Host і в перевірці TLS-сертифіката;
 *  - перенаправлення (3xx) НЕ виконуються: вони могли б привести на заборонену адресу в обхід перевірки.
 */
final class WebhookClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: ?int, error: ?string} status — код відповіді (null — відповіді немає), error — причина збою
     */
    public static function post(string $url, string $body, array $headers, bool $verifyTls, bool $allowPrivate, int $timeout = 6): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!$parts || !in_array($scheme, ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return ['status' => null, 'error' => 'Некоректна адреса (потрібна http:// чи https:// без логіна й пароля).'];
        }

        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $error = null;
        $ip = self::resolve($host, $allowPrivate, $error);
        if ($ip === null) {
            return ['status' => null, 'error' => $error];
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $headers['Host'] = trim($host, '[]') . ($port !== $defaultPort ? ':' . $port : '');
        $headers['Content-Length'] = (string) strlen($body);
        $headers['Connection'] = 'close';
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . str_replace(["\r", "\n"], ' ', $value);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $lines),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,   // тіло й код 4xx/5xx читаємо самі
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => [
                'verify_peer' => $verifyTls,
                'verify_peer_name' => $verifyTls,
                'allow_self_signed' => !$verifyTls,
                'peer_name' => trim($host, '[]'),
                'SNI_enabled' => true,
                'SNI_server_name' => trim($host, '[]'),
            ],
        ]);

        $target = $scheme . '://' . (str_contains($ip, ':') ? "[{$ip}]" : $ip) . ':' . $port . $path;
        $http_response_header = [];
        // Збираємо ВСІ попередження потоку, а не лише останнє: причина збою TLS приходить окремим попередженням перед
        // загальним «Failed to open stream», і без цього адміністратор бачив би лише безпорадне «operation failed».
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            $response = file_get_contents($target, false, $context, 0, 4096);
        } finally {
            restore_error_handler();
        }

        $status = null;
        if (!empty($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if ($status === null) {
            $all = implode(' | ', $warnings);
            if (preg_match('/certificate verify failed|self[- ]signed|unable to get local issuer|SSL operation failed|peer certificate/i', $all)) {
                $detail = preg_match('/error:[0-9A-F]+:[^|]*?:([^|]+)/i', $all, $m) ? trim($m[1], ': ') : 'сертифікат не пройшов перевірку';
                return ['status' => null, 'error' => mb_substr('Помилка TLS: сертифікат одержувача не пройшов перевірку (' . $detail . '). Виправте сертифікат або — лише для внутрішніх сервісів — зніміть «Перевіряти TLS-сертифікат».', 0, 480)];
            }
            $reason = $warnings ? end($warnings) : 'немає відповіді';
            $reason = preg_replace('/^file_get_contents\([^)]*\):\s*/', '', $reason) ?: $reason;
            return ['status' => null, 'error' => mb_substr('З\'єднання не вдалось: ' . $reason, 0, 480)];
        }
        unset($response);
        return ['status' => $status, 'error' => null];
    }

    /** Повертає першу дозволену IP-адресу хоста або null (і текст причини в $error). */
    public static function resolve(string $host, bool $allowPrivate, ?string &$error): ?string
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = gethostbynamel($host) ?: [];
            if (!$ips) {
                $error = "Не вдалося розпізнати ім'я хоста «{$host}» (DNS).";
                return null;
            }
        }
        $reason = null;
        foreach ($ips as $ip) {
            $reason = self::blockedReason($ip, $allowPrivate);
            if ($reason === null) {
                return $ip;
            }
        }
        $error = "Адресу заблоковано: {$reason}.";
        return null;
    }

    /** Чому на цю IP відправляти не можна (null — можна). */
    public static function blockedReason(string $ip, bool $allowPrivate): ?string
    {
        // IPv4, «загорнутий» в IPv6 (::ffff:169.254.169.254), перевіряємо як IPv4 — інакше це обхід заборони.
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m)) {
            $ip = $m[1];
        }
        foreach (['169.254.0.0/16' => 'link-local (у т.ч. адреса метаданих хмари 169.254.169.254)', '0.0.0.0/8' => 'службова адреса',
                  '224.0.0.0/4' => 'мультикаст', '240.0.0.0/4' => 'зарезервований діапазон', 'fe80::/10' => 'link-local IPv6',
                  'ff00::/8' => 'мультикаст IPv6', '::/128' => 'невизначена адреса'] as $cidr => $why) {
            if (self::inCidr($ip, $cidr)) {
                return $why;
            }
        }
        if (!$allowPrivate) {
            foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '100.64.0.0/10', '::1/128', 'fc00::/7'] as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    return 'внутрішня мережа (заборонено параметром WEBHOOK_ALLOW_PRIVATE=false)';
                }
            }
        }
        return null;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false; // різні сімейства адрес (IPv4 проти IPv6)
        }
        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($a, 0, $fullBytes) !== substr($b, 0, $fullBytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$fullBytes]) & $mask) === (ord($b[$fullBytes]) & $mask);
    }
}
