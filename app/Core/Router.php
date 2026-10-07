<?php

namespace App\Core;

class Router
{
    private array $routes = ['GET' => [], 'POST' => []];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/') ?: '/';

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

        // Пряме співпадіння
        if (isset($this->routes[$method][$path])) {
            ($this->routes[$method][$path])();
            return;
        }

        // Маршрути з параметром виду /tasks/{id}
        foreach ($this->routes[$method] as $route => $handler) {
            $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $route);
            $pattern = '#^' . $pattern . '$#';
            if (preg_match($pattern, $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $handler($params);
                return;
            }
        }

        http_response_code(404);
        echo '404 — сторінку не знайдено.';
    }
}
