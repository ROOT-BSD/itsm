<?php

namespace App\Core;

/**
 * Content-Security-Policy — головний захист від XSS: script-src суворий
 * ('self' + конкретні CDN + одноразовий nonce на запит), без 'unsafe-inline'
 * для скриптів. Inline-обробники (onclick="" тощо) CSP блокує так само, як
 * сторонній <script> — тому в шаблонах такі атрибути замінено на
 * delegated-обробники в одному nonce'd <script> (app/Views/layout/main.php),
 * що реагують на клас .auto-submit-select і атрибут data-confirm.
 *
 * style-src лишає 'unsafe-inline': кілька місць використовують inline
 * style="width: X%" для динамічних прогрес-барів, які nonce не покриває
 * (nonce діє лише на <script>/<style>, не на атрибути) — ризик від inline
 * style значно нижчий за script, тож це свідомий компроміс, а не недогляд.
 */
class Csp
{
    private static ?string $nonce = null;

    /** Той самий nonce для всього запиту — генерується один раз, кешується в пам'яті процесу. */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = bin2hex(random_bytes(16));
        }
        return self::$nonce;
    }

    /** Викликати один раз, якнайраніше — до будь-якого виводу (інакше header() поверне помилку). */
    public static function sendHeaders(): void
    {
        $cdn = 'https://cdn.jsdelivr.net https://cdnjs.cloudflare.com';
        $nonce = self::nonce();

        $policy = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' {$cdn}",
            "style-src 'self' 'unsafe-inline' {$cdn}",
            "img-src 'self' data:",
            "font-src 'self' {$cdn}",
            // CDN в connect-src — не для роботи самих бібліотек (той script-src вище),
            // а щоб DevTools міг підвантажити .map-файл мінімізованого скрипта для
            // налагодження; без цього рядка саме цей один запит (лише коли відкрита
            // консоль розробника) CSP блокує з незрозумілим на перший погляд попередженням.
            "connect-src 'self' {$cdn}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);

        header('Content-Security-Policy: ' . $policy);
        // Старіші браузери CSP frame-ancestors не підтримують — X-Frame-Options як запасний варіант.
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}
