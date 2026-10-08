<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Models\Setting;
use App\Models\WebhookEndpoint;
use App\Services\WebhookService;

/**
 * Адмін-сторінки вебхуків (Адмін-панель → Налаштування → Вебхуки): ендпоінти, вибір подій, секрет підпису,
 * тестова подія й журнал доставок із ручним повтором. Лише адміністратор.
 */
class WebhookAdminController
{
    public function __construct()
    {
        Auth::requireLogin();
        if (!Auth::hasRole(['admin'])) {
            http_response_code(403);
            echo 'Налаштування вебхуків дозволено лише ролі "Адміністратор системи".';
            exit;
        }
    }

    public function index(): void
    {
        $available = WebhookEndpoint::available();
        $lastRun = $available ? Setting::get('webhooks_worker_last_run') : null;
        $due = $available ? (int) Database::connection()->query("SELECT COUNT(*) FROM webhook_deliveries WHERE status = 'pending' AND next_attempt_at <= NOW()")->fetchColumn() : 0;

        View::render('admin/webhooks', [
            'available' => $available,
            'endpoints' => $available ? WebhookEndpoint::all() : [],
            'lastRun' => $lastRun,
            'due' => $due,
            'immediate' => function_exists('fastcgi_finish_request'),   // PHP-FPM відправляє нові події сам; інакше потрібен cron
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function create(): void
    {
        $this->requireAvailable();
        View::render('admin/webhook_form', ['endpoint' => null, 'form' => ['name' => '', 'url' => '', 'events' => ['*'], 'verify_tls' => true], 'errors' => [], 'secret' => null]);
    }

    public function store(): void
    {
        $this->requireAvailable();
        $form = $this->readForm();
        $errors = WebhookEndpoint::validate($form['name'], $form['url'], $form['events']);
        if ($errors) {
            View::render('admin/webhook_form', ['endpoint' => null, 'form' => $form, 'errors' => $errors, 'secret' => null]);
            return;
        }
        $created = WebhookEndpoint::create($form['name'], $form['url'], $form['events'], $form['verify_tls'], (int) Auth::id());
        $_SESSION['new_webhook_secret'] = ['id' => $created['id'], 'secret' => $created['secret']];
        $this->redirect('/admin/webhooks/' . $created['id'], 'success', 'Вебхук створено. Скопіюйте секрет — його показано лише зараз.');
    }

    public function show(array $params): void
    {
        $endpoint = $this->find((int) $params['id']);
        $secret = null;
        if (($_SESSION['new_webhook_secret']['id'] ?? null) === (int) $endpoint['id']) {
            $secret = $_SESSION['new_webhook_secret']['secret'];
        }
        unset($_SESSION['new_webhook_secret']); // секрет показується один раз

        View::render('admin/webhook_form', [
            'endpoint' => $endpoint,
            'form' => ['name' => $endpoint['name'], 'url' => $endpoint['url'], 'events' => WebhookEndpoint::decodeEvents($endpoint['events']), 'verify_tls' => (bool) $endpoint['verify_tls']],
            'errors' => [],
            'secret' => $secret,
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function update(array $params): void
    {
        $endpoint = $this->find((int) $params['id']);
        $form = $this->readForm();
        $errors = WebhookEndpoint::validate($form['name'], $form['url'], $form['events']);
        if ($errors) {
            View::render('admin/webhook_form', ['endpoint' => $endpoint, 'form' => $form, 'errors' => $errors, 'secret' => null]);
            return;
        }
        WebhookEndpoint::update((int) $endpoint['id'], $form['name'], $form['url'], $form['events'], $form['verify_tls'], (int) Auth::id());
        $this->redirect('/admin/webhooks/' . $endpoint['id'], 'success', 'Зміни збережено.');
    }

    public function toggle(array $params): void
    {
        $endpoint = $this->find((int) $params['id']);
        $enable = !(int) $endpoint['is_active'];
        WebhookEndpoint::setActive((int) $endpoint['id'], $enable, $enable ? null : 'Вимкнено адміністратором.', (int) Auth::id());
        $this->redirect('/admin/webhooks', 'success', $enable ? 'Вебхук увімкнено.' : 'Вебхук вимкнено — нові події на нього не надсилаються.');
    }

    public function rotateSecret(array $params): void
    {
        $endpoint = $this->find((int) $params['id']);
        $secret = WebhookEndpoint::rotateSecret((int) $endpoint['id'], (int) Auth::id());
        $_SESSION['new_webhook_secret'] = ['id' => (int) $endpoint['id'], 'secret' => $secret];
        $this->redirect('/admin/webhooks/' . $endpoint['id'], 'success', 'Створено новий секрет — старий більше не діє. Оновіть його в одержувача.');
    }

    /** Тестова подія webhook.ping: відправляється одразу, навіть на вимкнений ендпоінт, і результат показується адміністратору. */
    public function test(array $params): void
    {
        $endpoint = $this->find((int) $params['id']);
        $result = WebhookService::ping((int) $endpoint['id'], Auth::id());
        $ok = $result['status'] !== null && $result['status'] >= 200 && $result['status'] < 300;
        $this->redirect(
            '/admin/webhooks/' . $endpoint['id'],
            $ok ? 'success' : 'error',
            $ok ? "Тест успішний: одержувач відповів {$result['status']}." : 'Тест не вдався: ' . ($result['error'] ?? "одержувач відповів {$result['status']}") . ' Журнал — «Доставки».'
        );
    }

    public function delete(array $params): void
    {
        $endpoint = $this->find((int) $params['id']);
        WebhookEndpoint::delete((int) $endpoint['id'], $endpoint['name'], (int) Auth::id());
        $this->redirect('/admin/webhooks', 'success', 'Вебхук «' . $endpoint['name'] . '» видалено разом із журналом його доставок.');
    }

    public function deliveries(array $params): void
    {
        $endpoint = $this->find((int) $params['id']);
        View::render('admin/webhook_deliveries', [
            'endpoint' => $endpoint,
            'deliveries' => WebhookService::recent((int) $endpoint['id'], 100),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function retry(array $params): void
    {
        $this->requireAvailable();
        $delivery = WebhookService::find((int) $params['id']);
        if (!$delivery) {
            http_response_code(404);
            echo 'Доставку не знайдено.';
            exit;
        }
        $result = WebhookService::retry((int) $delivery['id']);
        $path = '/admin/webhooks/' . $delivery['endpoint_id'] . '/deliveries';
        if ($result === null) {
            $this->redirect($path, 'error', 'Цю доставку повторити не можна (вона вже успішна або її забрав інший процес).');
        }
        $ok = $result['status'] !== null && $result['status'] >= 200 && $result['status'] < 300;
        $this->redirect($path, $ok ? 'success' : 'error', $ok ? "Повторну відправку виконано: одержувач відповів {$result['status']}." : 'Повторна відправка не вдалась: ' . ($result['error'] ?? "код {$result['status']}"));
    }

    // ------------------------------------------------------------------ допоміжне

    private function requireAvailable(): void
    {
        if (!WebhookEndpoint::available()) {
            $this->redirect('/admin/webhooks', 'error', 'Таблиці вебхуків ще не створено: запустіть update.sh.');
        }
    }

    private function find(int $id): array
    {
        $this->requireAvailable();
        $endpoint = WebhookEndpoint::find($id);
        if (!$endpoint) {
            http_response_code(404);
            echo 'Вебхук не знайдено.';
            exit;
        }
        return $endpoint;
    }

    /** @return array{name: string, url: string, events: string[], verify_tls: bool} */
    private function readForm(): array
    {
        $events = !empty($_POST['events_all']) ? ['*'] : array_values(array_filter((array) ($_POST['events'] ?? []), 'is_string'));
        return [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'url' => trim((string) ($_POST['url'] ?? '')),
            'events' => $events,
            'verify_tls' => !empty($_POST['verify_tls']),
        ];
    }

    private function redirect(string $path, string $key, string $message): never
    {
        header('Location: ' . $path . '?' . $key . '=' . urlencode($message));
        exit;
    }
}
