<?php

namespace App\Controllers\Api;

use App\Core\Access;
use App\Core\ApiResponse;
use App\Models\ApiToken;
use App\Models\Audit;

/**
 * Основа всіх контролерів REST API. Конструктор САМ автентифікує запит, тож неавтентифікований виклик не може дійти
 * до жодної дії: створення контролера = перевірка токена. Порядок перевірок: чи API доступне й увімкнене → токен →
 * обмеження частоти → фіксація використання.
 *
 * Принципи:
 *  - API бачить і може рівно те саме, що власник токена у веб-інтерфейсі (ролі й видимість — ті самі моделі й Access);
 *  - cookie й сесії ігноруються цілком (ні читання, ні Set-Cookie), тож міжсайтова підробка запиту неможлива;
 *  - запис вимагає токена зі scope=write; інакше 403;
 *  - запис, до якого немає доступу, відповідає 404 (а не 403): не видно навіть того, що запис існує.
 */
abstract class ApiController
{
    protected const MAX_BODY_BYTES = 1048576;

    /** @var array<string, mixed> */
    protected array $token;
    protected int $userId;
    protected string $role;
    protected bool $isAdmin;
    /** @var array{id: int, full_name: string, email: string, role_code: string} */
    protected array $user;

    public function __construct()
    {
        if (!ApiToken::available()) {
            ApiResponse::error(503, 'api_unavailable', 'API недоступне: на сервері не виконано оновлення бази даних (адміністратору: запустити update.sh).');
        }
        if (!ApiToken::enabled()) {
            ApiResponse::error(503, 'api_disabled', 'API вимкнено адміністратором системи.');
        }

        $plain = self::bearerToken();
        $token = $plain !== null ? ApiToken::findActive($plain) : null;
        if ($token === null) {
            header('WWW-Authenticate: Bearer realm="ITSM API"');
            ApiResponse::error(401, 'unauthenticated', 'Потрібен чинний токен доступу: заголовок «Authorization: Bearer <токен>».');
        }

        $rate = ApiToken::hit((int) $token['id']);
        ApiResponse::addHeader('X-RateLimit-Limit', (string) $rate['limit']);
        ApiResponse::addHeader('X-RateLimit-Remaining', (string) $rate['remaining']);
        ApiResponse::addHeader('X-RateLimit-Reset', (string) $rate['reset']);
        if (!$rate['allowed']) {
            ApiResponse::addHeader('Retry-After', (string) max(1, $rate['reset']));
            ApiResponse::error(429, 'rate_limited', "Забагато запитів: ліміт {$rate['limit']} на хвилину. Повторіть через {$rate['reset']} с.");
        }

        ApiToken::touch($token, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        $this->token = $token;
        $this->userId = (int) $token['user_id'];
        $this->role = (string) $token['role_code'];
        $this->isAdmin = Access::isAdmin($this->role);
        $this->user = ['id' => $this->userId, 'full_name' => $token['full_name'], 'email' => $token['email'], 'role_code' => $this->role];
        Audit::setVia('api');
    }

    /** Токен із заголовка. Адреса запиту (?token=) свідомо НЕ підтримується: вона потрапляє в логи й історію. */
    private static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                }
            }
        }
        if (preg_match('/^Bearer\s+(\S+)\s*$/i', $header, $m)) {
            return $m[1];
        }
        // Запасний заголовок для проксі, що вирізають Authorization.
        $alt = trim((string) ($_SERVER['HTTP_X_API_TOKEN'] ?? ''));
        return $alt !== '' ? $alt : null;
    }

    protected function requireWrite(): void
    {
        if (($this->token['scope'] ?? 'read') !== 'write') {
            ApiResponse::error(403, 'insufficient_scope', 'Цей токен лише для читання. Для створення й зміни потрібен токен із правом запису.');
        }
    }

    /** Тіло запиту як асоціативний масив. Лише application/json, не більше 1 МБ. @return array<string, mixed> */
    protected function body(): array
    {
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_starts_with($type, 'application/json')) {
            ApiResponse::error(415, 'unsupported_media_type', 'Тіло запиту має бути JSON із заголовком «Content-Type: application/json».');
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            ApiResponse::error(413, 'payload_too_large', 'Тіло запиту завелике (максимум 1 МБ).');
        }
        if (trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            ApiResponse::error(400, 'invalid_json', 'Тіло запиту має бути коректним JSON-об\'єктом.');
        }
        return $data;
    }

    /** @return array{0: int, 1: int} [сторінка, розмір сторінки (1–100, за замовчуванням 25)] */
    protected function pagination(): array
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = (int) ($_GET['per_page'] ?? 25);
        return [$page, max(1, min(100, $perPage ?: 25))];
    }

    /**
     * Віддає сторінку з уже відфільтрованого повного набору. $present перетворює елемент на JSON (його викликають лише
     * для елементів поточної сторінки — тож важкі додаткові запити не виконуються для всього набору).
     *
     * @param array<int, mixed> $all
     */
    protected function paginate(array $all, callable $present): never
    {
        [$page, $perPage] = $this->pagination();
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);
        ApiResponse::paginated(array_values(array_filter(array_map($present, $slice), static fn($x) => $x !== null)), count($all), $page, $perPage);
    }

    /** @param array<string, string> $errors */
    protected function validationFailed(array $errors): never
    {
        ApiResponse::error(422, 'validation_failed', 'Некоректні дані запиту.', $errors);
    }

    protected function notFound(string $what): never
    {
        ApiResponse::error(404, 'not_found', "{$what} не знайдено.");
    }

    // ------------------------------------------------------------------ перевірка полів

    /** Рядок із тіла: обрізається, перевіряється довжина. null — поле відсутнє/порожнє. */
    protected function stringField(array $data, string $key, int $max, array &$errors, bool $required = false): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                $errors[$key] = 'Обов\'язкове поле.';
            }
            return null;
        }
        if (!is_string($value)) {
            $errors[$key] = 'Має бути рядком.';
            return null;
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            $errors[$key] = "Задовге (максимум {$max} символів).";
            return null;
        }
        return $value;
    }

    /** Ціле число з тіла (приймає число або рядок із цифр). null — немає або некоректне (тоді додається помилка, якщо поле задано). */
    protected function intField(array $data, string $key, array &$errors, bool $required = false): ?int
    {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            if ($required) {
                $errors[$key] = 'Обов\'язкове поле.';
            }
            return null;
        }
        $value = $data[$key];
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value))) {
            return (int) $value;
        }
        $errors[$key] = 'Має бути цілим числом.';
        return null;
    }

    protected function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
