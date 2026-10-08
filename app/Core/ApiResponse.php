<?php

namespace App\Core;

/**
 * Єдиний формат відповідей REST API. Усі відповіді — JSON (UTF-8, кирилиця не екранується), без кешування.
 *
 *   Успіх:   { "data": … }  або  { "data": [ … ], "meta": { "page", "per_page", "total", "pages" } }
 *   Помилка: { "error": { "code": "not_found", "message": "…", "details": { "поле": "пояснення" } } }
 *
 * code — стабільний машинний ідентифікатор (за ним пишуть логіку клієнта); message — для людини (українською) і
 * може змінюватись. Метод завершує запит (exit): після відповіді нічого більше виконуватись не повинно.
 */
final class ApiResponse
{
    /** @var array<string, string> заголовки, що додаються до кожної відповіді (наприклад, X-RateLimit-*) */
    private static array $extraHeaders = [];

    public static function addHeader(string $name, string $value): void
    {
        self::$extraHeaders[$name] = $value;
    }

    /** @param array<string, mixed> $payload */
    public static function json(int $status, array $payload): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            foreach (self::$extraHeaders as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }

    public static function ok(mixed $data, int $status = 200): never
    {
        self::json($status, ['data' => $data]);
    }

    /** @param array<int, mixed> $items уже обрізана до сторінки видача; $total — загальна кількість */
    public static function paginated(array $items, int $total, int $page, int $perPage): never
    {
        self::json(200, [
            'data' => array_values($items),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => (int) max(1, ceil($total / max(1, $perPage)))],
        ]);
    }

    /** @param array<string, string>|null $details пояснення по полях (для 422) */
    public static function error(int $status, string $code, string $message, ?array $details = null): never
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details) {
            $error['details'] = $details;
        }
        self::json($status, ['error' => $error]);
    }
}
