<?php
// Email-сповіщення адміністраторам про проблеми зі сторінки «Стан системи».
//
// Запуск (cron, щогодини; від імені користувача веб-сервера):
//
//   15 * * * *  www-data  php /var/www/itsm-system/bin/check-status.php >> /var/log/itsm-status.log 2>&1
//
// Лист іде лише коли набір проблем змінився (а якщо проблеми не усунуто — нагадування раз на 7 днів);
// коли все усунуто — один лист «проблем немає». Вмикається в Адмін-панель → Налаштування → Пошта → тікети
// («Сповіщати адміністраторів про проблеми зі „Стану системи“»). Потребує налаштованого SMTP.
// Код виходу: 0 — успіх (навіть якщо сповіщення вимкнені), 1 — помилка.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

use App\Services\NotificationService;

$stamp = static fn(string $msg): string => '[' . date('Y-m-d H:i:s') . '] [itsm-status] ' . $msg . "\n";

try {
    \App\Models\Setting::set('status_alert_last_run', date('Y-m-d H:i:s')); // «Стан системи» показує, чи cron працює
    $r = NotificationService::sendStatusAlerts();
    if ($r['skipped_reason'] !== null) {
        echo $stamp($r['skipped_reason']);
        exit(0);
    }
    echo $stamp("Проблем: {$r['problems']}; надіслано листів: {$r['sent']}");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, $stamp('Неочікувана помилка: ' . get_class($e) . ': ' . $e->getMessage()));
    exit(1);
}
