<?php

namespace App\Services;

use App\Core\AdAuth;
use App\Core\Config;
use App\Core\LdapClient;
use App\Core\LdapDn;
use App\Core\LdapException;
use App\Models\AdGroupMapping;
use App\Models\Audit;
use App\Models\Setting;
use App\Models\User;

/**
 * Синхронізація користувачів з Active Directory (bin/sync-ad-users.php, за cron).
 * Відповідність локальному запису — спершу за стабільним objectGUID (users.ad_guid, міграція 028),
 * потім за email (той самий ключ, яким людина входить у формі логіну). Знайшовши за email акаунт
 * без GUID, синхронізація запам'ятовує GUID; якщо AD змінив людині email, акаунт знаходиться за GUID
 * і email оновлюється замість створення дубля. Без міграції 028 працює лише зіставлення за email.
 *
 * Ручні перевизначення (users.ad_role_locked / users.ad_blocked, міграція 027): закріплену вручну
 * роль синхронізація не змінює, а вручну деактивованого користувача не вмикає знову, навіть якщо він
 * є в каталозі. Решту полів (ім'я, username, OU) оновлює як завжди.
 *
 * Локальні ("local") облікові записи синхронізація НІКОЛИ не чіпає, навіть якщо
 * їхній email збігається з кимось у AD — щоб не можна було випадково (чи навмисно)
 * перехопити через AD обліковий запис адміністратора системи, створений локально.
 */
class AdSyncService
{
    private static function enabled(): bool
    {
        return Setting::get('ad_sync_enabled', '0') === '1' && AdAuth::isConfigured();
    }

    /**
     * @param bool $force true — ручний запуск з адмін-панелі («Синхронізувати зараз»): галочка
     *                    «Синхронізувати користувачів з AD» не потрібна, достатньо налаштованого
     *                    підключення. false — запуск за розкладом (cron): галочка обов'язкова.
     * @return array{status: string, message: string} status: ok|disabled|error
     */
    public static function sync(bool $force = false): array
    {
        if ($force ? !AdAuth::isConfigured() : !self::enabled()) {
            return [
                'status' => 'disabled',
                'message' => $force
                    ? 'Підключення до AD не налаштовано'
                    : 'Синхронізація вимкнена (галочка в Адмін-панель → Налаштування → Active Directory) або AD не налаштовано',
            ];
        }

        $ad = Config::get('ad', []);
        try {
            $client = new LdapClient($ad['host'], $ad['port'], $ad['encryption'], $ad['verify_cert']);
            $client->connect();
            $client->bind($ad['bind_dn'], $ad['bind_password']);

            $attrs = [$ad['username_attribute'], $ad['email_attribute'], $ad['name_attribute'], $ad['group_attribute'], 'objectGUID'];
            $entries = $client->search($ad['base_dn'], $ad['sync_filter'], $attrs);
            $client->unbind();
        } catch (LdapException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        $summary = self::process($entries, $ad);

        Setting::set('ad_last_sync_at', date('Y-m-d H:i:s'));
        Setting::set('ad_last_sync_summary', $summary);

        return ['status' => 'ok', 'message' => $summary];
    }

    /**
     * objectGUID приходить з LDAP як 16 байт у «змішаному» порядку (перші три групи — little-endian).
     * Повертає канонічний рядок нижнього регістру або null, якщо значення відсутнє чи некоректне.
     */
    public static function parseGuid(mixed $raw): ?string
    {
        if (is_array($raw)) {
            $raw = $raw[0] ?? null;
        }
        if (!is_string($raw)) {
            return null;
        }
        if (strlen($raw) === 16) {
            $h = unpack('Va/vb/vc/H4d/H12e', $raw);
            return sprintf('%08x-%04x-%04x-%s-%s', $h['a'], $h['b'], $h['c'], $h['d'], $h['e']);
        }
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $raw)) {
            return strtolower($raw);
        }
        return null;
    }

    /**
     * Зіставляє знайдені в каталозі записи з локальними користувачами (створення/оновлення/деактивація).
     * Окремий метод, щоб логіку можна було перевірити на готових записах без з'єднання з AD.
     *
     * @param array<int, array<string, mixed>> $entries результат LdapClient::search()
     * @param array<string, mixed> $ad конфігурація AD (Config::get('ad'))
     * @return string підсумок для адміністратора
     */
    public static function process(array $entries, array $ad): string
    {
        $created = 0;
        $updated = 0;
        $skippedLocalConflict = 0;
        $skippedNoEmail = 0;
        $deactivated = 0;
        $keptRole = 0;
        $keptBlocked = 0;
        $linked = 0;
        $emailChanged = 0;
        $skippedEmailConflict = 0;

        $defaultRoleId = self::roleIdForCode(Config::get('ad.default_role') ?: 'requester');
        $guidReady = User::guidReady();
        $seenIds = [];

        foreach ($entries as $entry) {
            $email = trim((string) ($entry[strtolower($ad['email_attribute'])] ?? ''));
            $guid = $guidReady ? self::parseGuid($entry['objectguid'] ?? null) : null;
            if ($email === '') {
                $skippedNoEmail++;
                continue;
            }

            $username = (string) ($entry[strtolower($ad['username_attribute'])] ?? '');
            $name = (string) ($entry[strtolower($ad['name_attribute'])] ?? $username);
            $groupsRaw = $entry[strtolower($ad['group_attribute'])] ?? [];
            $groups = is_array($groupsRaw) ? $groupsRaw : ($groupsRaw !== '' ? [$groupsRaw] : []);
            $roleId = AdGroupMapping::roleIdForGroups($groups) ?? $defaultRoleId;
            $ou = LdapDn::ouPath((string) $entry['dn']);

            try {
                // Зіставлення: спершу за стабільним objectGUID, потім за email.
                $byGuid = $guid !== null ? User::findByAdGuid($guid) : null;
                $byEmail = User::findByEmail($email);
                $existing = $byGuid ?: $byEmail;
                $newEmail = null;   // якщо AD змінив пошту відомому за GUID користувачеві
                $newGuid = null;    // якщо знайшли за email і GUID ще не збережено

                if ($byGuid) {
                    if (mb_strtolower($byGuid['email']) !== mb_strtolower($email)) {
                        if ($byEmail && (int) $byEmail['id'] !== (int) $byGuid['id']) {
                            // Нова пошта вже належить іншому акаунту — не чіпаємо пошту, лишаємо стару.
                            $skippedEmailConflict++;
                        } else {
                            $newEmail = $email;
                        }
                    }
                } elseif ($byEmail) {
                    if ($byEmail['auth_source'] === 'local') {
                        $skippedLocalConflict++;
                        continue;
                    }
                    if ($guid !== null) {
                        if (!empty($byEmail['ad_guid']) && strtolower($byEmail['ad_guid']) !== $guid) {
                            // Той самий email, але інший об'єкт AD (пошту «успадкував» інший працівник).
                            // Старий акаунт належить іншій людині — не перехоплюємо його.
                            $skippedEmailConflict++;
                            continue;
                        }
                        if (empty($byEmail['ad_guid'])) {
                            $newGuid = $guid;
                        }
                    }
                }

                if ($existing) {
                    $seenIds[(int) $existing['id']] = true;
                    // Ручні перевизначення (міграція 027): лише рахуємо їх для підсумку — сам UPDATE їх поважає.
                    if (!empty($existing['ad_role_locked']) && (int) $existing['role_id'] !== $roleId) {
                        $keptRole++;
                    }
                    if (!empty($existing['ad_blocked'])) {
                        $keptBlocked++;
                    }
                    User::updateFromAd((int) $existing['id'], $name, $username, $roleId, $ou);
                    if ($newGuid !== null || $newEmail !== null) {
                        User::setAdIdentity((int) $existing['id'], $newGuid, $newEmail);
                    }
                    if ($newGuid !== null) {
                        $linked++;
                    }
                    if ($newEmail !== null) {
                        $emailChanged++;
                        Audit::log('user', (int) $existing['id'], 'ad_sync_email_changed', null, ['old' => $existing['email'], 'new' => $newEmail]);
                    }
                    Audit::log('user', (int) $existing['id'], 'ad_sync_updated', null, ['ad_username' => $username, 'role_id' => $roleId]);
                    $updated++;
                } else {
                    $newId = User::createFromAd($name, $email, $username, $roleId, $ou, $guid);
                    $seenIds[$newId] = true;
                    Audit::log('user', $newId, 'ad_sync_created', null, ['ad_username' => $username, 'role_id' => $roleId]);
                    $created++;
                }
            } catch (\Throwable) {
                // Найімовірніша причина — одночасний email у двох AD-записах (дублікат UNIQUE);
                // пропускаємо один запис, решта синхронізації триває.
                continue;
            }
        }

        // Деактивація: AD-користувачі, яких цей прогін не побачив серед результатів фільтра
        // синхронізації (звільнились, вимкнений обліковий запис у AD тощо). Порівняння — за id
        // знайденого облікового запису (а не за email), тож зміна пошти не виглядає як зникнення.
        foreach (User::allAdSourced() as $user) {
            if ((int) $user['is_active'] === 1 && !isset($seenIds[(int) $user['id']])) {
                User::setActive((int) $user['id'], false);
                Audit::log('user', (int) $user['id'], 'ad_sync_deactivated', null, []);
                $deactivated++;
            }
        }

        $summary = "Створено: {$created}, оновлено: {$updated}, деактивовано: {$deactivated}"
            . ($skippedLocalConflict > 0 ? ", пропущено (локальний конфлікт email): {$skippedLocalConflict}" : '')
            . ($skippedNoEmail > 0 ? ", пропущено (без email у AD): {$skippedNoEmail}" : '')
            . ($keptRole > 0 ? ", збережено ручну роль: {$keptRole}" : '')
            . ($linked > 0 ? ", прив'язано за GUID: {$linked}" : '')
            . ($emailChanged > 0 ? ", змінено email (за GUID): {$emailChanged}" : '')
            . ($skippedEmailConflict > 0 ? ", пропущено (email належить іншому об'єкту AD): {$skippedEmailConflict}" : '')
            . ($keptBlocked > 0 ? ", лишено вимкненими вручну: {$keptBlocked}" : '');

        return $summary;
    }

    private static function roleIdForCode(string $code): int
    {
        $roles = User::roles();
        foreach ($roles as $role) {
            if ($role['code'] === $code) {
                return (int) $role['id'];
            }
        }
        // AD_DEFAULT_ROLE у .env вказує код, якого немає серед ролей у БД (помилка
        // налаштування) — свідомо повертаємось саме до 'requester', а не до довільної
        // ролі за сортуванням id, яке ніяк не гарантує відповідність рівню привілеїв.
        foreach ($roles as $role) {
            if ($role['code'] === 'requester') {
                return (int) $role['id'];
            }
        }
        // 'requester' теж відсутній (нетипова БД) — останній захист від створення
        // користувача без ролі взагалі: перша роль за id, а не довільна.
        return (int) $roles[0]['id'];
    }
}
