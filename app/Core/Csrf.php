<?php

namespace App\Core;

/**
 * Захист від CSRF (Cross-Site Request Forgery). Токен генерується один раз
 * на сесію і має збігатися в кожному POST-запиті — інакше запит відхиляється
 * ще до того, як дійде до контролера (перевірка централізована в public/index.php).
 */
class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    /** Повертає поточний токен сесії, генеруючи його за потреби. */
    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /** Готовий HTML прихованого поля для вставки у форму. */
    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . self::token() . '">';
    }

    /** Порівняння токена з запиту з токеном сесії, стійке до timing-атак. */
    public static function verify(?string $submittedToken): bool
    {
        if (empty($_SESSION[self::SESSION_KEY]) || $submittedToken === null || $submittedToken === '') {
            return false;
        }
        return hash_equals($_SESSION[self::SESSION_KEY], $submittedToken);
    }
}
