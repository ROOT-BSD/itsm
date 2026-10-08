<?php

namespace App\Core;

/**
 * Розбір Distinguished Name з Active Directory для групування користувачів за
 * організаційними підрозділами (OU).
 *
 * У DN "CN=Іван Петренко,OU=IT,OU=Kyiv,DC=company,DC=local" лише компоненти OU= є
 * організаційними підрозділами. Листовий CN= — сам обліковий запис, DC= — домен, а
 * проміжний CN= (наприклад, стандартна папка "CN=Users") — це «контейнер», а не OU,
 * тож у шлях OU він не потрапляє: користувач із такого контейнера вважається таким,
 * що не належить до жодного OU.
 *
 * Коми, екрановані зворотним слешем всередині значення ("OU=Продажі\, Схід"), не є
 * роздільниками. Рідкісний випадок екранованого самого слеша перед комою ("\\,")
 * розбирається неточно — для назв OU це практично не трапляється.
 */
class LdapDn
{
    /** Роздільник компонентів DN: кома, не екранована зворотним слешем. */
    private const SEPARATOR = '/(?<!\\\\),/';

    /**
     * Шлях OU з DN користувача — лише компоненти OU=, у порядку DN (від найближчого до листа
     * до кореня), без змін у значеннях: "OU=IT,OU=Kyiv". Порожній рядок, якщо користувач
     * не лежить у жодному OU.
     */
    public static function ouPath(string $dn): string
    {
        $parts = preg_split(self::SEPARATOR, $dn) ?: [];
        array_shift($parts); // листовий компонент — сам обліковий запис
        $ous = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^ou=/i', $part)) {
                $ous[] = $part;
            }
        }
        return implode(',', $ous);
    }

    /**
     * Читабельна назва для показу: "OU=IT,OU=Kyiv" -> "Kyiv › IT" (від кореня до листа — звичніший
     * порядок читання, ніж у самому DN). Екранування розкривається: "OU=Продажі\, Схід" -> "Продажі, Схід".
     * Порожній шлях дає порожній рядок.
     */
    public static function ouLabel(string $ouPath): string
    {
        if ($ouPath === '') {
            return '';
        }
        $names = array_map(
            static fn(string $part): string => self::unescape(preg_replace('/^ou=/i', '', trim($part))),
            preg_split(self::SEPARATOR, $ouPath) ?: []
        );
        return implode(' › ', array_reverse($names));
    }

    /**
     * Підрозділ, введений людиною (форма користувача), -> шлях у форматі ouPath ("OU=IT,OU=Kyiv").
     * Приймає і готовий шлях ("OU=IT,OU=Kyiv"), і читабельний запис від кореня до листа ("Kyiv › IT",
     * роздільники › > / або \\ не потрібні в назвах). Порожній рядок -> '' (без підрозділу).
     * Недопустиме (порожня ланка, керуючі символи, задовга назва) -> null.
     */
    public static function fromInput(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }
        if (mb_strlen($input) > 500 || preg_match('/[\x00-\x1f\x7f]/', $input)) {
            return null;
        }

        if (preg_match('/^ou=/i', $input)) {
            $parts = array_map('trim', preg_split(self::SEPARATOR, $input) ?: []);
            foreach ($parts as $part) {
                if (!preg_match('/^ou=\S.*$/iu', $part)) {
                    return null;
                }
            }
            return implode(',', $parts);
        }

        // Читабельний запис: від кореня до листа; у шлях — від листа до кореня.
        $names = array_map('trim', preg_split('/\s*[›>\/\\\\]\s*/u', $input) ?: []);
        $ous = [];
        foreach (array_reverse($names) as $name) {
            if ($name === '' || str_contains($name, '=')) {
                return null;
            }
            $ous[] = 'OU=' . str_replace(',', '\\,', $name);
        }
        return implode(',', $ous);
    }

    private static function unescape(string $value): string
    {
        // \2C -> "," (hex-пара за RFC 4514), потім \, -> "," (екранований спецсимвол)
        $value = preg_replace_callback('/\\\\([0-9a-fA-F]{2})/', static fn(array $m): string => chr((int) hexdec($m[1])), $value);
        return preg_replace('/\\\\(.)/su', '$1', $value);
    }
}
