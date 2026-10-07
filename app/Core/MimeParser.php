<?php

namespace App\Core;

/**
 * Розбір сирого листа (RFC 5322 + MIME) у структуру, придатну для створення тікета.
 *
 * Без залежностей і без ext-imap. Розбирає те, що реально трапляється в
 * пошті: RFC 2047 у заголовках (Subject/From, у т.ч. кирилиця в base64 і
 * quoted-printable), вкладені multipart (mixed → alternative → related),
 * кодування тіла base64/quoted-printable, довільні charset (utf-8,
 * windows-1251, koi8-u, iso-8859-*), HTML-лише листи, format=flowed,
 * імена вкладень (у т.ч. RFC 2231). Вкладення загалом лише перелічуються
 * (назва, тип); виняток — зображення PNG/JPG: їх вміст декодується й повертається
 * в ключі 'data', щоб EmailTicketService міг зберегти їх як вкладення тікета.
 *
 * Результат завжди валідний UTF-8. Жоден HTML не зберігається: HTML-лист
 * перетворюється в простий текст, тож у системі немає поверхні для XSS.
 */
class MimeParser
{
    private const MAX_DEPTH = 10;

    /** MIME-типи, що можуть бути PNG/JPG; справжній тип потім перевіряється за вмістом (AttachmentService). */
    private const IMAGE_TYPES = ['image/png', 'image/x-png', 'image/jpeg', 'image/jpg', 'image/pjpeg'];

    /** Більші частини не декодуємо взагалі (захист пам'яті); у EmailTicketService весь лист і так обмежений 10 МБ. */
    private const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    /**
     * @return array{
     *   from_email: string, from_name: string, subject: string, message_id: string,
     *   in_reply_to: string[], references: string[], text: string,
     *   attachments: array<int, array{name: string, type: string, disposition: string, content_id: string, data?: string}>,
     *   headers: array<string, string[]>
     * }
     */
    public static function parse(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        [$headerBlock, $body] = self::splitHeaderBody($raw);
        $headers = self::parseHeaders($headerBlock);

        $from = self::parseAddress($headers['from'][0] ?? '');
        $attachments = [];
        [$text] = self::walk($headers, $body, $attachments, 0);

        return [
            'from_email' => $from['email'],
            'from_name' => $from['name'],
            'subject' => trim(self::decodeHeader($headers['subject'][0] ?? '')),
            'message_id' => self::extractMessageIds($headers['message-id'][0] ?? '')[0] ?? '',
            'in_reply_to' => self::extractMessageIds($headers['in-reply-to'][0] ?? ''),
            'references' => self::extractMessageIds(implode(' ', $headers['references'] ?? [])),
            'text' => self::normalizeText($text),
            'attachments' => $attachments,
            'headers' => $headers,
        ];
    }

    /** @return array{0: string, 1: string} [блок заголовків, тіло] */
    private static function splitHeaderBody(string $raw): array
    {
        if (str_starts_with($raw, "\n")) {
            return ['', substr($raw, 1)];
        }
        $pos = strpos($raw, "\n\n");
        if ($pos === false) {
            return [$raw, ''];
        }
        return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    /** @return array<string, string[]> імена в нижньому регістрі, значення розгорнуті (unfolded), але не декодовані */
    private static function parseHeaders(string $block): array
    {
        $block = preg_replace("/\n[ \t]+/", ' ', $block) ?? $block;
        $headers = [];
        foreach (explode("\n", $block) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false || $pos === 0) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $headers[$name][] = trim(substr($line, $pos + 1));
        }
        return $headers;
    }

    /**
     * Розшифровує RFC 2047 encoded-words (=?utf-8?B?...?=, =?windows-1251?Q?...?=).
     * Сусідні слова з однаковим charset склеюються ДО перекодування — деякі
     * поштові клієнти розрізають багатобайтовий символ між двома словами.
     */
    public static function decodeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return self::toUtf8($value, 'utf-8');
        }

        preg_match_all('/=\?([^?\s]+)\?([bBqQ])\?([^?\s]*)\?=/', $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (!$matches) {
            return self::toUtf8($value, 'utf-8');
        }

        $out = '';
        $last = 0;
        $groupBytes = null;
        $groupCharset = null;

        foreach ($matches as $m) {
            $offset = $m[0][1];
            $between = substr($value, $last, $offset - $last);
            $charset = strtolower(explode('*', $m[1][0])[0]); // 'utf-8*uk' → мова RFC 2231 відкидається
            $encoded = $m[3][0];
            $bytes = strtoupper($m[2][0]) === 'B'
                ? (string) base64_decode($encoded)
                : quoted_printable_decode(str_replace('_', ' ', $encoded));

            $adjacent = $groupBytes !== null && trim($between) === '';
            if ($adjacent && $charset === $groupCharset) {
                $groupBytes .= $bytes;
            } else {
                if ($groupBytes !== null) {
                    $out .= self::toUtf8($groupBytes, $groupCharset);
                }
                $out .= $adjacent ? '' : self::toUtf8($between, 'utf-8');
                $groupBytes = $bytes;
                $groupCharset = $charset;
            }
            $last = $offset + strlen($m[0][0]);
        }
        if ($groupBytes !== null) {
            $out .= self::toUtf8($groupBytes, $groupCharset);
        }
        return $out . self::toUtf8(substr($value, $last), 'utf-8');
    }

    /** Будь-який charset → валідний UTF-8. Невідомий charset або биті байти не ламають розбір. */
    public static function toUtf8(string $data, string $charset): string
    {
        $charset = strtolower(trim($charset, " \t\"'"));
        $aliases = ['cp1251' => 'windows-1251', 'win-1251' => 'windows-1251', 'x-cp1251' => 'windows-1251', 'x-unknown' => 'utf-8', 'unknown-8bit' => 'utf-8'];
        $charset = $aliases[$charset] ?? $charset;

        if ($charset === '' || in_array($charset, ['utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }

        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $data);
            if ($converted !== false) {
                return $converted;
            }
        }
        if (in_array(strtoupper($charset), array_map('strtoupper', mb_list_encodings()), true)) {
            return (string) @mb_convert_encoding($data, 'UTF-8', $charset);
        }
        return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
    }

    /** @return array{name: string, email: string} */
    private static function parseAddress(string $raw): array
    {
        $name = '';
        $email = '';
        if (preg_match('/<([^<>]+)>/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $email = $m[1][0];
            $name = substr($raw, 0, $m[0][1]);
        } elseif (preg_match('/[^\s<>"\',;]+@[^\s<>"\',;]+/', $raw, $m)) {
            $email = $m[0];
        }

        $email = strtolower(trim($email, " \t<>\"'"));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = '';
        }
        $name = trim(self::decodeHeader(trim($name, " \t\"'")));
        return ['name' => $name, 'email' => $email];
    }

    /** @return string[] Message-ID у вигляді <id@host> (з кутовими дужками — так вони зберігаються в журналі) */
    public static function extractMessageIds(string $value): array
    {
        preg_match_all('/<[^<>\s]+>/', $value, $m);
        return $m[0];
    }

    /**
     * Розбір параметрів заголовка: 'text/plain; charset="utf-8"' → ['text/plain', ['charset' => 'utf-8']].
     * Підтримує лапки й RFC 2231 (filename*=UTF-8''%D0..., а також розбиття filename*0*, filename*1*).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function parseParams(string $header): array
    {
        $segments = [];
        $current = '';
        $quoted = false;
        $length = strlen($header);
        for ($i = 0; $i < $length; $i++) {
            $ch = $header[$i];
            if ($ch === '\\' && $quoted && $i + 1 < $length) {
                $current .= $header[++$i];
                continue;
            }
            if ($ch === '"') {
                $quoted = !$quoted;
                $current .= $ch;
                continue;
            }
            if ($ch === ';' && !$quoted) {
                $segments[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $segments[] = $current;

        $value = strtolower(trim((string) array_shift($segments)));
        $params = [];
        $extended = [];

        foreach ($segments as $segment) {
            if (!str_contains($segment, '=')) {
                continue;
            }
            [$key, $val] = array_map('trim', explode('=', $segment, 2));
            $key = strtolower($key);
            if (strlen($val) >= 2 && $val[0] === '"' && str_ends_with($val, '"')) {
                $val = substr($val, 1, -1);
            }

            if (preg_match('/^([^*]+)\*(\d+)?(\*)?$/', $key, $km, PREG_UNMATCHED_AS_NULL)) {
                $extended[$km[1]][(int) ($km[2] ?? 0)] = [$val, $km[3] !== null];
            } else {
                $params[$key] = $val;
            }
        }

        foreach ($extended as $name => $parts) {
            ksort($parts);
            $data = '';
            $charset = 'utf-8';
            foreach ($parts as $index => [$val, $isEncoded]) {
                if ($isEncoded) {
                    if ($index === 0 && preg_match("/^([^']*)'[^']*'(.*)$/s", $val, $cm)) {
                        $charset = $cm[1] !== '' ? $cm[1] : 'utf-8';
                        $val = $cm[2];
                    }
                    $data .= rawurldecode($val);
                } else {
                    $data .= $val;
                }
            }
            $params[$name] = self::toUtf8($data, $charset);
        }

        return [$value, $params];
    }

    /**
     * Обхід MIME-дерева. Повертає [текст, чи_це_text/plain]. Вкладення дописуються в $attachments.
     *
     * @param array<string, string[]> $headers
     * @param array<int, array{name: string, type: string}> $attachments
     * @return array{0: string, 1: bool}
     */
    private static function walk(array $headers, string $body, array &$attachments, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return ['', false];
        }

        [$type, $typeParams] = self::parseParams($headers['content-type'][0] ?? 'text/plain');
        [$disposition, $dispParams] = self::parseParams($headers['content-disposition'][0] ?? '');
        $encoding = strtolower(trim($headers['content-transfer-encoding'][0] ?? '7bit'));
        $filename = trim(self::decodeHeader($dispParams['filename'] ?? $typeParams['name'] ?? ''));

        if (str_starts_with($type, 'multipart/')) {
            $boundary = $typeParams['boundary'] ?? '';
            if ($boundary === '') {
                return ['', false];
            }

            $children = [];
            foreach (self::splitMultipart($body, $boundary) as $partRaw) {
                [$hb, $pb] = self::splitHeaderBody($partRaw);
                $children[] = [self::parseHeaders($hb), $pb];
            }

            if ($type === 'multipart/alternative') {
                // Один і той самий зміст в різних форматах: беремо text/plain, інакше перший непорожній (зазвичай HTML).
                // ТЕКСТ беремо з однієї (кращої) гілки, а ВКЛАДЕННЯ — з усіх: картинки, вставлені в лист
                // (Gmail, Outlook, Apple Mail), лежать у HTML-гілці (multipart/related), тоді як текст ми
                // беремо з text/plain. Якби вкладення брались лише з обраної гілки, такі картинки губились би.
                $best = null;
                $allAttachments = [];
                foreach ($children as [$childHeaders, $childBody]) {
                    $childAttachments = [];
                    [$text, $isPlain] = self::walk($childHeaders, $childBody, $childAttachments, $depth + 1);
                    array_push($allAttachments, ...$childAttachments);
                    if (trim($text) === '') {
                        continue;
                    }
                    if ($best === null || ($isPlain && !$best['plain'])) {
                        $best = ['text' => $text, 'plain' => $isPlain];
                    }
                }
                array_push($attachments, ...$allAttachments);
                if ($best === null) {
                    return ['', false];
                }
                return [$best['text'], $best['plain']];
            }

            // mixed / related / signed / інші: тексти частин по черзі, вкладення накопичуються.
            $texts = [];
            $anyPlain = false;
            foreach ($children as [$childHeaders, $childBody]) {
                [$text, $isPlain] = self::walk($childHeaders, $childBody, $attachments, $depth + 1);
                if (trim($text) !== '') {
                    $texts[] = $text;
                    $anyPlain = $anyPlain || $isPlain;
                }
            }
            return [implode("\n\n", $texts), $anyPlain];
        }

        $isTextBody = in_array($type, ['text/plain', 'text/html'], true)
            && $disposition !== 'attachment'
            && $filename === '';

        if (!$isTextBody) {
            $entry = [
                'name' => $filename !== '' ? $filename : ($type === 'message/rfc822' ? '(вкладений лист)' : "(без назви, {$type})"),
                'type' => $type,
                'disposition' => $disposition,
                'content_id' => trim($headers['content-id'][0] ?? '', " <>\t"),
            ];
            if (self::isImageCandidate($type, $filename)) {
                $data = self::decodeTransfer($body, $encoding);
                if ($data !== '' && strlen($data) <= self::MAX_IMAGE_BYTES) {
                    $entry['data'] = $data;
                }
            }
            $attachments[] = $entry;
            return ['', false];
        }

        $data = self::decodeTransfer($body, $encoding);
        $text = self::toUtf8($data, $typeParams['charset'] ?? 'utf-8');

        if ($type === 'text/html') {
            return [self::htmlToText($text), false];
        }
        if (strtolower($typeParams['format'] ?? '') === 'flowed') {
            $text = self::unflow($text);
        }
        return [$text, true];
    }

    /**
     * Чи варто декодувати частину як можливе зображення PNG/JPG: за MIME-типом, або (клієнти часто шлють
     * фото як application/octet-stream) за розширенням у назві. Остаточне рішення — за вмістом, не тут.
     */
    private static function isImageCandidate(string $type, string $filename): bool
    {
        if (in_array($type, self::IMAGE_TYPES, true)) {
            return true;
        }
        return $type === 'application/octet-stream'
            && in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'], true);
    }

    /** @return string[] тіла (разом із заголовками) частин multipart */
    private static function splitMultipart(string $body, string $boundary): array
    {
        $text = "\n" . $body;
        $pattern = '/\n--' . preg_quote($boundary, '/') . '(--)?[ \t]*(?=\n|\z)/';
        if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $parts = [];
        $count = count($matches);
        for ($i = 0; $i < $count; $i++) {
            if (isset($matches[$i][1]) && $matches[$i][1][0] === '--') {
                break; // закривальний роздільник
            }
            $start = $matches[$i][0][1] + strlen($matches[$i][0][0]);
            $end = $matches[$i + 1][0][1] ?? strlen($text);
            $chunk = substr($text, $start, $end - $start);
            $parts[] = str_starts_with($chunk, "\n") ? substr($chunk, 1) : $chunk;
        }
        return $parts;
    }

    private static function decodeTransfer(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? $body),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /** RFC 3676 format=flowed: рядок, що закінчується пробілом, — м'який перенос, склеюється з наступним. */
    private static function unflow(string $text): string
    {
        $out = [];
        $buffer = '';
        foreach (explode("\n", $text) as $line) {
            $isSoftBreak = str_ends_with($line, ' ') && $line !== '-- ' && !str_starts_with($line, '>');
            if ($isSoftBreak) {
                $buffer .= $line;
                continue;
            }
            $out[] = $buffer . $line;
            $buffer = '';
        }
        if ($buffer !== '') {
            $out[] = $buffer;
        }
        return implode("\n", $out);
    }

    public static function htmlToText(string $html): string
    {
        $steps = [
            ['#<(script|style|head)\b[^>]*>.*?</\1>#is', ''],
            ['#<br\s*/?>#i', "\n"],
            ['#</(p|div|tr|h[1-6]|table|ul|ol|blockquote|pre)>#i', "\n"],
            ['#<li\b[^>]*>#i', "\n- "],
            ['#</(td|th)>#i', ' '],
        ];
        foreach ($steps as [$pattern, $replacement]) {
            $html = preg_replace($pattern, $replacement, $html) ?? $html;
        }
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Єдиний вигляд тексту: \n, без керуючих і невидимих символів, без зайвих пробілів і порожніх рядків. */
    private static function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\xC2\xA0", ' ', $text);
        // \PC — усе, що не керуючі/форматні символи; тому [^\PC\n\t] — керуючі та форматні (нульова ширина, BOM) крім \n і \t
        $text = preg_replace('/[^\PC\n\t]/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }
}
