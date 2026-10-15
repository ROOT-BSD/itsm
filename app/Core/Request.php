<?php

namespace App\Core;

/** Відомості про поточний HTTP-запит, потрібні в кількох місцях. */
class Request
{
    /**
     * Чи надійшов запит по HTTPS — напряму або через проксі/балансувальник, що завершує TLS і передає про це заголовком
     * (X-Forwarded-Proto / X-Forwarded-Ssl / Forwarded). Підроблений заголовок нічим не шкодить: він лише вмикає прапорець
     * Secure для cookie самого відвідувача.
     */
    public static function isHttps(): bool
    {
        $s = $_SERVER;
        if (!empty($s['HTTPS']) && strtolower((string) $s['HTTPS']) !== 'off') {
            return true;
        }
        if ((string) ($s['SERVER_PORT'] ?? '') === '443' || strtolower((string) ($s['REQUEST_SCHEME'] ?? '')) === 'https') {
            return true;
        }
        $proto = strtolower(trim(explode(',', (string) ($s['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if ($proto === 'https' || strtolower((string) ($s['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
            return true;
        }
        return (bool) preg_match('/proto=https/i', (string) ($s['HTTP_FORWARDED'] ?? ''));
    }

    /** Що саме побачив сервер — для діагностики на сторінці «Стан системи». */
    public static function httpsEvidence(): string
    {
        $s = $_SERVER;
        $parts = [];
        foreach (['HTTPS', 'SERVER_PORT', 'REQUEST_SCHEME', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SSL', 'HTTP_FORWARDED'] as $k) {
            $parts[] = $k . '=' . (isset($s[$k]) && $s[$k] !== '' ? (string) $s[$k] : '—');
        }
        return implode(', ', $parts);
    }
}
