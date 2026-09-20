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
