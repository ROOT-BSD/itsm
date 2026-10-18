<?php

namespace App\Services;

use App\Core\Config;
use App\Core\SmtpClient;
use App\Core\SmtpException;

/**
 * Надсилання пошти (SMTP) — наразі лише автовідповідь заявнику email-to-ticket
 * із посиланням для відстеження. Один лист = одне з'єднання (черги немає —
 * навантаження не того порядку, щоб вона була потрібна).
 *
 * send() ніколи не кидає виняток назовні: помилка надсилання пошти не повинна
 * зривати створення тікета, яке вже відбулось. Викликач читає результат
 * ['ok' => bool, 'message' => string].
 */
class MailerService
{
    private const TIMEOUT = 15;

    public static function isConfigured(): bool
    {
        $c = Config::get('smtp', []);
        return ($c['host'] ?? '') !== '' && ($c['username'] ?? '') !== '' && ($c['from_email'] ?? '') !== '';
    }

    /** Перевірка для адмін-панелі: підключення + логін, без надсилання листа. */
    public static function testConnection(): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'message' => 'Надсилання пошти не налаштовано: заповніть MAIL_SMTP_HOST і MAIL_SMTP_USERNAME/MAIL_SMTP_PASSWORD (або відповідні MAIL_IMAP_*) у файлі .env.'];
        }
        $c = Config::get('smtp', []);
        try {
            $client = new SmtpClient($c['host'], (int) $c['port'], $c['encryption'], (bool) $c['verify_cert'], self::TIMEOUT);
            $client->connect();
            if (($c['password'] ?? '') !== '') {
                $client->login($c['username'], $c['password']);
            }
            $client->quit();
            return ['ok' => true, 'message' => "З'єднання успішне, автентифікація пройшла."];
        } catch (SmtpException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param string|null $inReplyTo Message-ID листа, на який відповідаємо (для правильного threading у клієнті одержувача)
     * @param array<int, array{name: string, mime: string, data: string}> $attachments вкладення (лист стає multipart/mixed)
     * @return array{ok: bool, message: string}
     */
    public static function send(string $toEmail, string $toName, string $subject, string $body, ?string $inReplyTo = null, array $attachments = []): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'message' => 'Надсилання пошти не налаштовано (.env).'];
        }
        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => "Некоректна адреса одержувача: «{$toEmail}»."];
        }

        $c = Config::get('smtp', []);
        try {
            $client = new SmtpClient($c['host'], (int) $c['port'], $c['encryption'], (bool) $c['verify_cert'], self::TIMEOUT);
            $client->connect();
            if (($c['password'] ?? '') !== '') {
                $client->login($c['username'], $c['password']);
            }
            if ($attachments) {
                [$contentType, $encoded] = self::multipart($body, $attachments);
                $headers = self::buildHeaders($c, $toEmail, $toName, $subject, $inReplyTo, $contentType, '7bit');
            } else {
                $headers = self::buildHeaders($c, $toEmail, $toName, $subject, $inReplyTo);
                $encoded = self::encodeBody($body);
            }
            $client->send($c['from_email'], $toEmail, $headers, $encoded);
            $client->quit();
            return ['ok' => true, 'message' => 'Лист надіслано.'];
        } catch (SmtpException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return string[] */
    private static function buildHeaders(array $smtp, string $toEmail, string $toName, string $subject, ?string $inReplyTo, string $contentType = 'text/plain; charset=UTF-8', string $transferEncoding = 'base64'): array
    {
        $headers = [
            'From: ' . self::formatAddress($smtp['from_email'], $smtp['from_name']),
            'To: ' . self::formatAddress($toEmail, $toName),
            'Subject: ' . self::encodeHeaderWord($subject),
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: ' . self::generateMessageId($smtp['from_email']),
            'MIME-Version: 1.0',
            'Content-Type: ' . $contentType,
            'Content-Transfer-Encoding: ' . $transferEncoding,
            // Захист від циклу автовідповідей: наша ж скринька не повинна викликати
            // автовідповідь стороннього поштового сервера на цей лист (RFC 3834).
            'Auto-Submitted: auto-replied',
            'X-Auto-Response-Suppress: All',
        ];
        if ($inReplyTo !== null && $inReplyTo !== '') {
            $headers[] = 'In-Reply-To: ' . $inReplyTo;
            $headers[] = 'References: ' . $inReplyTo;
        }
        return $headers;
    }

    /** multipart/mixed: текст + вкладення (base64). Повертає [значення Content-Type, тіло]. */
    private static function multipart(string $body, array $attachments): array
    {
        $boundary = '=_itsm_' . bin2hex(random_bytes(12));
        $out = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . self::encodeBody($body);
        foreach ($attachments as $a) {
            $name = self::encodeHeaderWord(str_replace(['"', "\r", "\n"], '', (string) $a['name']));
            $out .= "--{$boundary}\r\nContent-Type: {$a['mime']}; name=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n" . chunk_split(base64_encode((string) $a['data']));
        }
        return ['multipart/mixed; boundary="' . $boundary . '"', $out . "--{$boundary}--\r\n"];
    }

    private static function formatAddress(string $email, string $name): string
    {
        return $name !== '' ? self::encodeHeaderWord($name) . " <{$email}>" : $email;
    }

    /** RFC 2047: кодуємо, лише якщо є не-ASCII символи — щоб не захаращувати листи без потреби. */
    private static function encodeHeaderWord(string $value): string
    {
        return preg_match('/^[\x20-\x7E]*$/', $value) ? $value : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** base64 з обов'язковими розривами рядків — без них деякі сервери відхиляють лист (рядок довший за 998 символів, RFC 5322). */
    private static function encodeBody(string $body): string
    {
        return chunk_split(base64_encode($body));
    }

    private static function generateMessageId(string $fromEmail): string
    {
        $domain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1);
        return '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
    }
}
