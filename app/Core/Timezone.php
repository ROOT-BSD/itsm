<?php

namespace App\Core;

/**
 * Єдиний часовий пояс застосунку. PHP без налаштованого date.timezone працює в UTC, а сервер і БД — у місцевому часі,
 * тому час у системі відставав би від годинника сервера. Пояс задає APP_TIMEZONE у .env (типово Europe/Kyiv); некоректне
 * значення мовчки замінюється на Europe/Kyiv (старіші бази tzdata знають лише Europe/Kiev) або, якщо й воно недоступне, на UTC.
 */
class Timezone
{
    public static function apply(): void
    {
        $wanted = (string) Config::get('app.timezone', 'Europe/Kyiv');
        foreach ([$wanted, 'Europe/Kyiv', 'Europe/Kiev'] as $tz) {
            if ($tz !== '' && @date_default_timezone_set($tz)) {
                return;
            }
        }
        date_default_timezone_set('UTC');
    }

    /** Зсув поточного поясу відносно UTC у вигляді «+03:00» — для SET time_zone у сесії БД. */
    public static function offset(): string
    {
        return date('P');
    }
}
