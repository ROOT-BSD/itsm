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
     * @return array{ok: bool, message: string}
     */
    public static function send(string $toEmail, string $toName, string $subject, string $body, ?string $inReplyTo = null): array
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
            $client->send($c['from_email'], $toEmail, self::buildHeaders($c, $toEmail, $toName, $subject, $inReplyTo), self::encodeBody($body));
            $client->quit();
            return ['ok' => true, 'message' => 'Лист надіслано.'];
        } catch (SmtpException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return string[] */
    private static function buildHeaders(array $smtp, string $toEmail, string $toName, string $subject, ?string $inReplyTo): array
    {
        $headers = [
            'From: ' . self::formatAddress($smtp['from_email'], $smtp['from_name']),
            'To: ' . self::formatAddress($toEmail, $toName),
            'Subject: ' . self::encodeHeaderWord($subject),
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: ' . self::generateMessageId($smtp['from_email']),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
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
