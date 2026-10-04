<?php

namespace App\Core;

/**
 * Автентифікація через Active Directory (LDAP), для користувачів із
 * users.auth_source = 'ad'. Схема "service account шукає DN → окремий bind
 * як сам користувач" — не можна одразу прив'язати логін до DN (реальні
 * username в AD не обов'язково збігаються з форматом DN), тож спершу
 * службовий обліковий запис знаходить потрібний запис у каталозі, а тоді
 * ВЖЕ іншим з'єднанням перевіряється пароль самого користувача.
 */
class AdAuth
{
    public static function isConfigured(): bool
    {
        $ad = Config::get('ad', []);
        return ($ad['host'] ?? '') !== '' && ($ad['base_dn'] ?? '') !== '' && ($ad['bind_dn'] ?? '') !== '';
    }

    /** Перевірка для адмін-панелі: підключення й пошук службовим акаунтом, без автентифікації користувача. */
    public static function testConnection(): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'message' => 'Інтеграція з AD не налаштована: заповніть AD_HOST, AD_BASE_DN, AD_BIND_DN, AD_BIND_PASSWORD у .env.'];
        }
        $ad = Config::get('ad', []);
        try {
            $client = self::connectAndBindService($ad);
            $results = $client->search($ad['base_dn'], $ad['sync_filter'], [$ad['username_attribute']]);
            $client->unbind();
            return ['ok' => true, 'message' => "З'єднання успішне. Знайдено користувачів за фільтром синхронізації: " . count($results) . '.'];
        } catch (LdapException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Перевіряє логін/пароль проти AD. Повертає атрибути користувача (ім'я, email, групи DN)
     * при успіху, або null — і при невірному паролі, і якщо користувача в AD не знайдено.
     * Різниця між цими двома випадками навмисно не повідомляється викликачу — так само,
     * як і для локальної автентифікації, щоб не підказувати стороннім, хто є у каталозі.
     *
     * @return array{dn: string, name: string, email: string, groups: string[]}|null
     */
    public static function authenticate(string $username, string $password): ?array
    {
        if (!self::isConfigured() || $username === '' || $password === '') {
            return null;
        }
        $ad = Config::get('ad', []);

        try {
            $service = self::connectAndBindService($ad);
            $filter = self::buildUserFilter($ad['user_filter'], $username);
            $attrs = [$ad['username_attribute'], $ad['email_attribute'], $ad['name_attribute'], $ad['group_attribute']];
            $found = $service->search($ad['base_dn'], $filter, $attrs);
            $service->unbind();

            if (count($found) !== 1) {
                return null; // не знайдено або збіг неоднозначний (кілька записів) — з обережності відмовляємо
            }
            $entry = $found[0];

            $userClient = new LdapClient($ad['host'], $ad['port'], $ad['encryption'], $ad['verify_cert']);
            $userClient->connect();
            $userClient->bind($entry['dn'], $password);
            $userClient->unbind();

            $groupsRaw = $entry[strtolower($ad['group_attribute'])] ?? [];
            $groups = is_array($groupsRaw) ? $groupsRaw : ($groupsRaw !== '' ? [$groupsRaw] : []);

            return [
                'dn' => $entry['dn'],
                'name' => $entry[strtolower($ad['name_attribute'])] ?? $username,
                'email' => $entry[strtolower($ad['email_attribute'])] ?? '',
                'groups' => $groups,
            ];
        } catch (LdapException) {
            return null; // недоступний сервер, невірний пароль тощо — усе зводиться до "не увійшов"
        }
    }

    private static function connectAndBindService(array $ad): LdapClient
    {
        $client = new LdapClient($ad['host'], $ad['port'], $ad['encryption'], $ad['verify_cert']);
        $client->connect();
        $client->bind($ad['bind_dn'], $ad['bind_password']);
        return $client;
    }

    /** Підставляє ім'я користувача у фільтр замість {username}, екрануючи спецсимволи LDAP-фільтра (RFC 4515) — без цього логін виду `*)(uid=*` міг би підмінити весь фільтр. */
    private static function buildUserFilter(string $template, string $username): string
    {
        $escaped = str_replace(
            ['\\', '*', '(', ')', "\0"],
            ['\\5c', '\\2a', '\\28', '\\29', '\\00'],
            $username
        );
        return str_replace('{username}', $escaped, $template);
    }
}
