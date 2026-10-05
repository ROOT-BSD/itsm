<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\AdAuth;
use App\Core\Config;
use App\Core\EnvFile;
use App\Core\View;
use App\Models\AdGroupMapping;
use App\Models\Audit;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdSyncService;

/** Адмін-сторінка інтеграції з Active Directory: стан підключення, синхронізація користувачів, відповідність груп ролям. */
class AdController
{
    public function __construct()
    {
        Auth::requireLogin();
        if (!Auth::hasRole(['admin'])) {
            http_response_code(403);
            echo 'Доступ до налаштувань Active Directory дозволено лише ролі "Адміністратор системи".';
            exit;
        }
    }

    public function index(): void
    {
        $ad = Config::get('ad', []);

        View::render('admin/ad', [
            'configured' => AdAuth::isConfigured(),
            'ad' => [
                'host' => $ad['host'] ?? '',
                'port' => $ad['port'] ?? '',
                'encryption' => $ad['encryption'] ?? '',
                'base_dn' => $ad['base_dn'] ?? '',
                'bind_dn' => $ad['bind_dn'] ?? '',
                'sync_filter' => $ad['sync_filter'] ?? '',
                'verify_cert' => !empty($ad['verify_cert']),
            ],
            // Для форми нижче — напряму з файлу, а не з Config::get() вище: той кешує значення
            // в межах процесу (PHP-FPM worker живе довше за один запит), тож одразу після
            // збереження показував би СТАРІ дані ще до перезапуску PHP. Пароль з міркувань
            // безпеки у форму ніколи не підставляється — лише ознака, чи він уже заданий.
            'form' => [
                'host' => EnvFile::get('AD_HOST') ?? '',
                'bind_dn' => EnvFile::get('AD_BIND_DN') ?? '',
                'base_dn' => EnvFile::get('AD_BASE_DN') ?? '',
                'has_password' => (EnvFile::get('AD_BIND_PASSWORD') ?? '') !== '',
                'encrypted' => (EnvFile::get('AD_ENCRYPTION') ?? 'none') !== 'none',
                'verify_cert' => (EnvFile::get('AD_VERIFY_CERT') ?? 'true') !== 'false',
            ],
            'syncEnabled' => Setting::get('ad_sync_enabled', '0') === '1',
            'lastSyncAt' => Setting::get('ad_last_sync_at'),
            'lastSyncSummary' => Setting::get('ad_last_sync_summary'),
            'mappings' => AdGroupMapping::all(),
            'roles' => User::roles(),
            'adUsersCount' => count(User::allAdSourced()),
            'scriptPath' => dirname(__DIR__, 2) . '/bin/sync-ad-users.php',
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    /** Записує параметри підключення до AD прямо в .env — щоб не редагувати файл вручну через SSH. */
    public function saveConnection(): void
    {
        $host = trim((string) ($_POST['ad_host'] ?? ''));
        $bindDn = trim((string) ($_POST['ad_bind_dn'] ?? ''));
        $baseDn = self::normalizeBaseDn(trim((string) ($_POST['ad_base_dn'] ?? '')));
        $password = (string) ($_POST['ad_bind_password'] ?? ''); // не trim() — пароль може легітимно починатись/закінчуватись пробілом
        $encrypted = !empty($_POST['ad_encrypted']);
        $verifyCert = !empty($_POST['ad_verify_cert']);

        if ($host === '' || $bindDn === '' || $baseDn === '') {
            $this->redirect('error', "Заповніть сервер, службовий обліковий запис і базовий DN — без них підключення неможливе.");
        }

        $values = [
            'AD_HOST' => $host,
            'AD_BIND_DN' => $bindDn,
            'AD_BASE_DN' => $baseDn,
            'AD_ENCRYPTION' => $encrypted ? 'starttls' : 'none',
            'AD_VERIFY_CERT' => $verifyCert ? 'true' : 'false',
        ];
        // Порожнє поле пароля = "не змінювати" (щоб не затерти вже задане значення щоразу,
        // коли адміністратор лише поправляє хост чи галочку шифрування) — лише непорожнє значення записується.
        if ($password !== '') {
            $values['AD_BIND_PASSWORD'] = $password;
        }

        try {
            EnvFile::set($values);
        } catch (\RuntimeException $e) {
            $this->redirect('error', $e->getMessage());
        }

        Audit::log('app_settings', 0, 'ad_connection_saved', Auth::id(), ['ad_host' => $host, 'ad_encrypted' => $encrypted ? 'так' : 'ні']);
        $this->redirect('success', 'Параметри збережено в .env і одразу діють — перезапускати PHP-FPM/Apache не потрібно.');
    }

    public function save(): void
    {
        $enabled = !empty($_POST['sync_enabled']);
        if ($enabled && !AdAuth::isConfigured()) {
            $this->redirect('error', 'Спершу налаштуйте підключення до Active Directory — заповніть AD_HOST, AD_BASE_DN, AD_BIND_DN, AD_BIND_PASSWORD у .env.');
        }

        Setting::set('ad_sync_enabled', $enabled ? '1' : '0');
        Audit::log('app_settings', 0, 'ad_settings_changed', Auth::id(), ['ad_sync_enabled' => $enabled ? 'так' : 'ні']);

        $this->redirect('success', 'Налаштування збережено');
    }

    public function test(): void
    {
        $result = AdAuth::testConnection();
        $this->redirect($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function syncNow(): void
    {
        @set_time_limit(120);
        $result = AdSyncService::sync(true);
        $this->redirect($result['status'] !== 'error' ? 'success' : 'error', $result['message']);
    }

    public function saveMapping(): void
    {
        $adGroup = trim((string) ($_POST['ad_group'] ?? ''));
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $rank = (int) ($_POST['rank'] ?? 100);

        if ($adGroup === '') {
            $this->redirect('error', 'Вкажіть групу AD (значення memberOf, зазвичай повний DN)');
        }
        if (!in_array($roleId, array_map(fn($r) => (int) $r['id'], User::roles()), true)) {
            $this->redirect('error', 'Роль не знайдено');
        }

        try {
            AdGroupMapping::create($adGroup, $roleId, $rank);
        } catch (\Throwable) {
            $this->redirect('error', 'Ця група вже має відповідність — видаліть стару, якщо хочете призначити іншу роль.');
        }

        Audit::log('ad_group_role_mapping', 0, 'mapping_added', Auth::id(), ['ad_group' => $adGroup, 'role_id' => $roleId]);
        $this->redirect('success', 'Відповідність додано');
    }

    public function deleteMapping(array $params): void
    {
        AdGroupMapping::delete((int) $params['id']);
        Audit::log('ad_group_role_mapping', (int) $params['id'], 'mapping_deleted', Auth::id());
        $this->redirect('success', 'Відповідність видалено');
    }

    /**
     * Дозволяє ввести просто назву домену ("hest.org.ua") замість синтаксису
     * LDAP DN ("dc=hest,dc=org,dc=ua") — для пошуку в каталозі потрібен саме
     * другий варіант, але адміністратор здебільшого знає назву домену, а не
     * те, як вона виглядає в DN-нотації. Якщо введене вже схоже на DN
     * (містить «атрибут=значення») — лишається без змін.
     */
    private static function normalizeBaseDn(string $input): string
    {
        if ($input === '' || preg_match('/^[a-zA-Z]+=/', $input)) {
            return $input;
        }
        $labels = array_filter(explode('.', $input), fn($label) => $label !== '');
        if (count($labels) < 2) {
            return $input; // не схоже на доменну назву — лишаємо як є, «Перевірити з'єднання» підкаже, якщо не спрацює
        }
        return implode(',', array_map(fn($label) => 'dc=' . $label, $labels));
    }

    private function redirect(string $key, string $message): never
    {
        header('Location: /admin/ad?' . $key . '=' . urlencode($message));
        exit;
    }
}
