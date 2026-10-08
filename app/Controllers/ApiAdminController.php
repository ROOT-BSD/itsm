<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\ApiToken;
use App\Models\Audit;
use App\Models\Setting;
use App\Models\User;

/**
 * Адмін-сторінка REST API (Адмін-панель → Налаштування → API): вмикання, ліміт запитів, перегляд усіх токенів,
 * створення токена для будь-якого користувача (службового облікового запису інтеграції) і відкликання.
 * Звичайні користувачі створюють собі токени на сторінці профілю (ProfileController).
 */
class ApiAdminController
{
    public function __construct()
    {
        Auth::requireLogin();
        if (!Auth::hasRole(['admin'])) {
            http_response_code(403);
            echo 'Налаштування API дозволено лише ролі "Адміністратор системи".';
            exit;
        }
    }

    public function index(): void
    {
        $available = ApiToken::available();
        // Щойно створений токен показується один раз: беремо з сесії й одразу забуваємо.
        $newToken = $_SESSION['new_api_token'] ?? null;
        unset($_SESSION['new_api_token']);

        View::render('admin/api', [
            'available' => $available,
            'enabled' => $available && ApiToken::enabled(),
            'rate' => $available ? ApiToken::ratePerMinute() : 120,
            'tokens' => $available ? ApiToken::all() : [],
            'users' => User::allActive(),
            'newToken' => $newToken,
            'appUrl' => Setting::appUrl(),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function saveSettings(): void
    {
        $this->requireAvailable();
        $enabled = isset($_POST['api_enabled']) ? '1' : '0';
        $rate = (int) ($_POST['api_rate_limit'] ?? 120);
        if ($rate < 10 || $rate > 10000) {
            $this->redirect('error', 'Ліміт запитів — від 10 до 10 000 на хвилину.');
        }
        $was = ApiToken::enabled();
        Setting::set('api_enabled', $enabled);
        Setting::set('api_rate_limit', (string) $rate);
        if ($was !== ($enabled === '1')) {
            Audit::log('app_settings', 0, $enabled === '1' ? 'api_enabled' : 'api_disabled', Auth::id());
        }
        $this->redirect('success', $enabled === '1' ? 'API увімкнено.' : 'API вимкнено — усі токени тимчасово не діють.');
    }

    /** Створює токен для ОБРАНОГО користувача (службовий обліковий запис інтеграції). */
    public function createToken(): void
    {
        $this->requireAvailable();
        $userId = (int) ($_POST['user_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $scope = (string) ($_POST['scope'] ?? 'read');
        $days = (int) ($_POST['expires_days'] ?? 0);

        $user = User::findById($userId);
        if (!$user || !(int) $user['is_active']) {
            $this->redirect('error', 'Оберіть активного користувача, від імені якого діятиме токен.');
        }
        if ($name === '' || mb_strlen($name) > 100) {
            $this->redirect('error', 'Вкажіть назву токена (до 100 символів) — наприклад, «Моніторинг Zabbix».');
        }
        if (ApiToken::countActiveForUser($userId) >= ApiToken::MAX_PER_USER) {
            $this->redirect('error', 'У користувача вже ' . ApiToken::MAX_PER_USER . ' діючих токенів — відкличте зайві.');
        }

        $created = ApiToken::create($userId, $name, $scope, $days > 0 ? min($days, 3650) : null, Auth::id());
        $_SESSION['new_api_token'] = ['token' => $created['token'], 'name' => $name, 'user' => $user['full_name'], 'scope' => $scope];
        $this->redirect('success', 'Токен створено — скопіюйте його зараз: більше його показати неможливо.');
    }

    public function revokeToken(array $params): void
    {
        $this->requireAvailable();
        $ok = ApiToken::revoke((int) $params['id'], null, Auth::id());
        $this->redirect($ok ? 'success' : 'error', $ok ? 'Токен відкликано.' : 'Токен не знайдено або вже відкликаний.');
    }

    private function requireAvailable(): void
    {
        if (!ApiToken::available()) {
            $this->redirect('error', 'Таблиці API ще не створено: запустіть update.sh.');
        }
    }

    private function redirect(string $key, string $message): never
    {
        header('Location: /admin/api?' . $key . '=' . urlencode($message));
        exit;
    }
}
