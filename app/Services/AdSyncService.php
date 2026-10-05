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
 * Відповідність локальному запису — за email (той самий ключ, яким людина входить
 * у формі логіну); якщо AD колись змінить людині email, наступна синхронізація
 * створить новий обліковий запис замість оновлення старого — відоме обмеження
 * такого підходу, прийнятне ціною простоти (без окремого стабільного ідентифікатора AD).
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
                    : 'Синхронізація вимкнена (галочка в Адмін-панель → Active Directory) або AD не налаштовано',
            ];
        }

        $ad = Config::get('ad', []);
        $created = 0;
        $updated = 0;
        $skippedLocalConflict = 0;
        $skippedNoEmail = 0;
        $deactivated = 0;

        try {
            $client = new LdapClient($ad['host'], $ad['port'], $ad['encryption'], $ad['verify_cert']);
            $client->connect();
            $client->bind($ad['bind_dn'], $ad['bind_password']);

            $attrs = [$ad['username_attribute'], $ad['email_attribute'], $ad['name_attribute'], $ad['group_attribute']];
            $entries = $client->search($ad['base_dn'], $ad['sync_filter'], $attrs);
            $client->unbind();
        } catch (LdapException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        $defaultRoleId = self::roleIdForCode(Config::get('ad.default_role') ?: 'requester');
        $syncedEmails = [];

        foreach ($entries as $entry) {
            $email = trim((string) ($entry[strtolower($ad['email_attribute'])] ?? ''));
            if ($email === '') {
                $skippedNoEmail++;
                continue;
            }
            $syncedEmails[] = mb_strtolower($email);

            $username = (string) ($entry[strtolower($ad['username_attribute'])] ?? '');
            $name = (string) ($entry[strtolower($ad['name_attribute'])] ?? $username);
            $groupsRaw = $entry[strtolower($ad['group_attribute'])] ?? [];
            $groups = is_array($groupsRaw) ? $groupsRaw : ($groupsRaw !== '' ? [$groupsRaw] : []);
            $roleId = AdGroupMapping::roleIdForGroups($groups) ?? $defaultRoleId;
            $ou = LdapDn::ouPath((string) $entry['dn']);

            try {
                $existing = User::findByEmail($email);
                if ($existing && $existing['auth_source'] === 'local') {
                    $skippedLocalConflict++;
                    continue;
                }

                if ($existing) {
                    User::updateFromAd((int) $existing['id'], $name, $username, $roleId, $ou);
                    Audit::log('user', (int) $existing['id'], 'ad_sync_updated', null, ['ad_username' => $username, 'role_id' => $roleId]);
                    $updated++;
                } else {
                    $newId = User::createFromAd($name, $email, $username, $roleId, $ou);
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
        // синхронізації (звільнились, вимкнений обліковий запис у AD тощо).
        foreach (User::allAdSourced() as $user) {
            if ((int) $user['is_active'] === 1 && !in_array(mb_strtolower($user['email']), $syncedEmails, true)) {
                User::setActive((int) $user['id'], false);
                Audit::log('user', (int) $user['id'], 'ad_sync_deactivated', null, []);
                $deactivated++;
            }
        }

        $summary = "Створено: {$created}, оновлено: {$updated}, деактивовано: {$deactivated}"
            . ($skippedLocalConflict > 0 ? ", пропущено (локальний конфлікт email): {$skippedLocalConflict}" : '')
            . ($skippedNoEmail > 0 ? ", пропущено (без email у AD): {$skippedNoEmail}" : '');

        Setting::set('ad_last_sync_at', date('Y-m-d H:i:s'));
        Setting::set('ad_last_sync_summary', $summary);

        return ['status' => 'ok', 'message' => $summary];
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
