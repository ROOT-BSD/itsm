<?php

namespace App\Core;

class Router
{
    private array $routes = ['GET' => [], 'POST' => [], 'PATCH' => [], 'DELETE' => []];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    /** PATCH і DELETE використовує лише REST API (/api/…); веб-форми надсилають тільки GET і POST. */
    public function patch(string $path, callable $handler): void
    {
        $this->routes['PATCH'][$path] = $handler;
    }

    public function delete(string $path, callable $handler): void
    {
        $this->routes['DELETE'][$path] = $handler;
    }

    /**
     * Шукає обробник маршруту: спершу точний збіг, потім маршрути з параметрами виду /tasks/{id}.
     *
     * @return array{0: callable, 1: array<string, string>|null}|null [обробник, параметри (null — маршрут без параметрів)]
     */
    private function match(string $method, string $path): ?array
    {
        if (isset($this->routes[$method][$path])) {
            return [$this->routes[$method][$path], null];
        }
        foreach ($this->routes[$method] ?? [] as $route => $handler) {
            $pattern = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $route) . '$#';
            if (preg_match($pattern, $path, $matches)) {
                return [$handler, array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
        }
        return null;
    }

    /**
     * REST API: окремий потік без сесій і CSRF (автентифікація — токеном у заголовку Authorization, який браузер
     * сам до міжсайтового запиту не додасть), з відповідями 404/405 у форматі JSON.
     */
    private function dispatchApi(string $method, string $path): void
    {
        $lookup = $method === 'HEAD' ? 'GET' : $method;
        $found = isset($this->routes[$lookup]) ? $this->match($lookup, $path) : null;
        if ($found === null) {
            $allowed = [];
            foreach (array_keys($this->routes) as $other) {
                if ($other !== $lookup && $this->match($other, $path) !== null) {
                    $allowed[] = $other;
                }
            }
            if ($allowed) {
                header('Allow: ' . implode(', ', $allowed));
                ApiResponse::error(405, 'method_not_allowed', "Метод {$method} для цього маршруту не підтримується. Дозволено: " . implode(', ', $allowed) . '.');
            }
            ApiResponse::error(404, 'not_found', 'Такого маршруту API немає.');
        }
        [$handler, $params] = $found;
        $params === null ? $handler() : $handler($params);
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/') ?: '/';

        if ($path === '/api' || str_starts_with($path, '/api/')) {
            $this->dispatchApi($method, $path);
            return;
        }
        // Веб-інтерфейс знає лише GET і POST (HEAD — як GET: його шлють перевірки доступності).
        if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) {
            http_response_code(405);
            header('Allow: GET, POST');
            echo 'Метод не підтримується.';
            return;
        }
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        // Запит завеликий для post_max_size: PHP мовчки викидає все тіло ($_POST і $_FILES порожні),
        // і CSRF-перевірка нижче видала б оманливе «Сесія застаріла». Тому розпізнаємо це окремо й
        // для завантаження вкладень повертаємо користувача на його сторінку з поясненням.
        if ($method === 'POST' && UploadLimits::postBodyTruncated()) {
            $message = 'Файли завеликі: сумарний розмір запиту перевищує ліміт сервера ('
                . UploadLimits::human(UploadLimits::postMax()) . ', параметр PHP post_max_size). '
                . 'Прикріпіть менше файлів за раз або менші за розміром.';
            if (preg_match('#^/(tickets|tasks)/(\d+)/attachments$#', $path, $m)) {
                header('Location: /' . $m[1] . '/' . $m[2] . '?error=' . urlencode($message));
                return;
            }
            // Бібліотека документів: форма нового документа або нова версія на сторінці документа.
            if ($path === '/library') {
                header('Location: /library/new?error=' . urlencode($message . ' Введені поля не збережено — заповніть форму ще раз.'));
                return;
            }
            if (preg_match('#^/library/(\d+)/versions$#', $path, $m)) {
                header('Location: /library/' . $m[1] . '?error=' . urlencode($message));
                return;
            }
            // Вікі: файл до сторінки. Редактор шле його через fetch і чекає JSON; звичайна форма — повертаємо на редагування.
            if (preg_match('#^/wiki/([a-z0-9-]+)/attachments$#', $path, $m)) {
                if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
                    http_response_code(413);
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
                    return;
                }
                header('Location: /wiki/' . $m[1] . '/edit?error=' . urlencode($message));
                return;
            }
            // Форми СТВОРЕННЯ тікета (із зображеннями): усе тіло запиту, разом із текстом, втрачено — повертаємо на форму.
            if ($path === '/tickets' || $path === '/support') {
                header('Location: ' . ($path === '/tickets' ? '/tickets/create' : '/support') . '?error='
                    . urlencode($message . ' Введені поля не збережено — заповніть форму ще раз.'));
                return;
            }
            http_response_code(413);
            echo $message;
            return;
        }

        // Централізована CSRF-перевірка для КОЖНОГО POST-запиту — так її
        // неможливо випадково забути додати в новий маршрут чи контролер.
        if ($method === 'POST' && !Csrf::verify($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            echo 'Сесія застаріла або форму відкрито занадто давно. Оновіть сторінку і спробуйте ще раз.';
            return;
        }

        $found = $this->match($method, $path);
        if ($found !== null) {
            [$handler, $params] = $found;
            $params === null ? $handler() : $handler($params);
            return;
        }

        http_response_code(404);
        echo '404 — сторінку не знайдено.';
    }
}
