<?php
// Email-to-ticket: забирає нові листи з поштової скриньки й перетворює їх на тікети.
//
// Запуск (cron, від імені користувача веб-сервера; тут — кожні 5 хвилин):
//
//   */5 * * * *  www-data  php /var/www/itsm-system/bin/fetch-mail.php >> /var/log/itsm-mail.log 2>&1
//
// Параметри:
//   --test    лише перевірити підключення до скриньки (нічого не обробляє)
//   --force   обробити пошту, навіть якщо в адмін-панелі обробку вимкнено
//
// Підключення до скриньки налаштовується в .env (MAIL_IMAP_*), решта — в
// Адмін-панель → Налаштування → Пошта → тікети. Код виходу: 0 — успіх, 1 — помилка, 2 — не налаштовано.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

use App\Models\Setting;
use App\Services\EmailTicketService;

$args = array_slice($argv ?? [], 1);
$stamp = static fn(string $msg): string => '[' . date('Y-m-d H:i:s') . '] [itsm-mail] ' . $msg . "\n";

try {
    if (!EmailTicketService::isConfigured()) {
        fwrite(STDERR, $stamp('Підключення не налаштовано: заповніть MAIL_IMAP_HOST, MAIL_IMAP_USERNAME, MAIL_IMAP_PASSWORD у .env'));
        exit(2);
    }

    if (($problem = EmailTicketService::configProblem()) !== null) {
        fwrite(STDERR, $stamp('Помилка в налаштуваннях .env: ' . $problem));
        exit(2);
    }

    if (in_array('--test', $args, true)) {
        $check = EmailTicketService::testConnection();
        echo $stamp($check['message']);
        exit($check['ok'] ? 0 : 1);
    }

    if (!in_array('--force', $args, true) && Setting::get('email_ticket_enabled', '0') !== '1') {
        echo $stamp('Обробку пошти вимкнено (Адмін-панель → Налаштування → Пошта → тікети). Для разового запуску: --force');
        exit(0);
    }

    $result = EmailTicketService::run();
    echo $stamp($result['message']);
    // Системна проблема (напр., немає черги) дає однакову помилку для кожного з до 50 листів —
    // у лог cron пишемо кожну унікальну причину один раз із кількістю, а не 50 однакових рядків.
    $grouped = [];
    foreach ($result['errors'] as $error) {
        $reason = preg_replace('/^UID \d+: /', '', $error);
        $grouped[$reason] = ($grouped[$reason] ?? 0) + 1;
    }
    foreach ($grouped as $reason => $count) {
        fwrite(STDERR, $stamp('  ' . ($count > 1 ? "×{$count}: " : '') . $reason));
    }
    exit(in_array($result['status'], ['ok', 'busy'], true) ? 0 : 1);
} catch (\Throwable $e) {
    // Найчастіше — недоступна БД. Один зрозумілий рядок у лог cron замість сирого стектрейсу.
    fwrite(STDERR, $stamp('Неочікувана помилка: ' . get_class($e) . ': ' . $e->getMessage()));
    exit(1);
}
