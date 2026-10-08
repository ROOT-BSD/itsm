<?php

namespace App\Core;

/**
 * Обмеження частоти запитів для публічних (анонімних) сторінок — насамперед порталу /support.
 *
 * Лічильник — фіксоване вікно на пару «дія + IP-адреса» у таблиці rate_limits. Один атомарний
 * INSERT … ON DUPLICATE KEY UPDATE, тож паралельні запити не «губляться». Для однієї дії можна задати
 * кілька правил одночасно (наприклад, 10 за 10 хвилин І 30 за добу): запит дозволений, лише якщо
 * вкладаємось у кожне.
 *
 * Збій самого лічильника (немає таблиці після оновлення без міграції, БД недоступна) НІКОЛИ не блокує
 * користувачів: обмежувач «відкривається» (fail open) і пише причину в журнал помилок.
 */
class RateLimiter
{
    /** Скільки секунд зберігаємо старі вікна (більше за найдовше вікно в конфігурації — добу). */
    private const KEEP_SECONDS = 172800;

    public static function enabled(): bool
    {
        return (bool) Config::get('rate_limits.enabled', true);
    }

    /**
     * $subject — кого рахуємо: за замовчуванням IP клієнта; для дій авторизованих користувачів (форум) — «user:<id>».
     * Враховує запит і повертає null, якщо він дозволений, або кількість секунд до кінця найдовшого
     * вікна, ліміт якого вичерпано (це значення для заголовка Retry-After).
     */
    public static function attempt(string $action, ?string $subject = null): ?int
    {
        return self::evaluate($action, $subject ?? self::clientIp(), true);
    }

    /** Те саме, але нічого не рахує: лише питає, чи ліміт дії вже вичерпано. */
    public static function peek(string $action, ?string $subject = null): ?int
    {
        return self::evaluate($action, $subject ?? self::clientIp(), false);
    }

    private static function evaluate(string $action, string $ip, bool $count): ?int
    {
        if (!self::enabled()) {
            return null;
        }
        $rules = Config::get('rate_limits.portal.' . $action, []);
        if (!is_array($rules) || !$rules) {
            return null;
        }

        try {
            $pdo = Database::connection();
            $now = time();
            $retry = null;

            foreach ($rules as [$limit, $seconds]) {
                $limit = (int) $limit;
                $seconds = max(1, (int) $seconds);
                $window = intdiv($now, $seconds) * $seconds;
                $bucket = 'portal.' . $action . '.' . $seconds;

                if ($count) {
                    $pdo->prepare(
                        'INSERT INTO rate_limits (bucket, subject, window_start, hits) VALUES (:b, :s, :w, 1)
                         ON DUPLICATE KEY UPDATE hits = hits + 1'
                    )->execute(['b' => $bucket, 's' => $ip, 'w' => $window]);
                }
                $stmt = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = :b AND subject = :s AND window_start = :w');
                $stmt->execute(['b' => $bucket, 's' => $ip, 'w' => $window]);
                $hits = (int) $stmt->fetchColumn();

                // Рахуємо й після перевищення, але в журнал пишемо лише один раз за вікно — момент першого відмовлення.
                if ($count && $hits === $limit + 1) {
                    error_log(sprintf('[itsm] rate limit: %s, %s перевищує %d за %d с', $action, $ip, $limit, $seconds));
                }
                $over = $count ? $hits > $limit : $hits >= $limit;
                if ($over) {
                    $left = $window + $seconds - $now;
                    $retry = max($retry ?? 1, $left);
                }
            }

            if ($count && random_int(1, 100) === 1) {
                $pdo->prepare('DELETE FROM rate_limits WHERE window_start < :old')->execute(['old' => $now - self::KEEP_SECONDS]);
            }
            return $retry;
        } catch (\Throwable $e) {
            error_log('[itsm] rate limiter недоступний, запит пропущено: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * IP клієнта. За замовчуванням — REMOTE_ADDR. Заголовку X-Forwarded-For довіряємо ЛИШЕ коли запит прийшов від
     * проксі зі списку TRUSTED_PROXIES: інакше будь-хто міг би підробити заголовок і обійти ліміт. У ланцюжку йдемо
     * справа наліво і беремо першу адресу, що не належить довіреним проксі.
     * IPv6 зводиться до підмережі /64 — одна «адреса» для всіх хостів провайдера, а не мільярди обхідних.
     */
    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ip = $remote;

        $trusted = Config::get('rate_limits.trusted_proxies', []);
        if ($trusted && self::inList($remote, $trusted) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $chain = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));
            foreach ($chain as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                    break; // сміття в ланцюжку — далі йому вірити не можна
                }
                $ip = $candidate;
                if (!self::inList($candidate, $trusted)) {
                    break;
                }
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }
        if (str_contains($ip, ':')) {
            $bin = inet_pton($ip);
            $ip = inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
        }
        return $ip;
    }

    /** @param array<int, string> $list адреси або CIDR */
    private static function inList(string $ip, array $list): bool
    {
        foreach ($list as $entry) {
            if (str_contains($entry, '/')) {
                if (WebhookClient::inCidr($ip, $entry)) {
                    return true;
                }
            } elseif ($entry === $ip) {
                return true;
            }
        }
        return false;
    }
}
