<?php
// Звіти за розкладом: надсилає на пошту звіти обліку часу, час яких настав («Звіти → Звіти за розкладом»).
//
// Запуск (cron, щогодини; від імені користувача веб-сервера):
//
//   5 * * * *  www-data  php /var/www/itsm-system/bin/send-reports.php >> /var/log/itsm-reports.log 2>&1
//
// Кожен розклад спрацьовує раз на період (тиждень/місяць) у вказаний день і не раніше вказаної години; пропущений запуск
// (cron не працював) наздоганяється. Невдала відправка повторюється наступним запуском. Потребує налаштованого SMTP.
// Код виходу: 0 — успіх (навіть без розкладів), 1 — були помилки надсилання.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

$stamp = static fn(string $msg): string => '[' . date('Y-m-d H:i:s') . '] [itsm-reports] ' . $msg . "\n";

try {
    \App\Models\Setting::set('reports_last_run', date('Y-m-d H:i:s')); // «Стан системи» показує, чи cron працює
    if (!\App\Services\MailerService::isConfigured()) {
        echo $stamp('Пошту не налаштовано — пропущено.');
        exit(0);
    }
    $r = \App\Services\ReportScheduleService::runDue();
    echo $stamp("Настав час: {$r['due']}; надіслано: {$r['sent']}; помилок: {$r['failed']}");
    exit($r['failed'] > 0 ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, $stamp('Неочікувана помилка: ' . get_class($e) . ': ' . $e->getMessage()));
    exit(1);
}
