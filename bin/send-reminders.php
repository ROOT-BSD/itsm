<?php
// Email-сповіщення: нагадування про наближення/настання терміну виконання задачі.
//
// Запуск (cron, раз на день зранку достатньо — пороги рахуються за датою, не часом):
//
//   0 8 * * *  www-data  php /var/www/itsm-system/bin/send-reminders.php >> /var/log/itsm-reminders.log 2>&1
//
// Кожне нагадування (2 дні / 1 день / настав термін) надсилається рівно один
// раз на задачу — повторні запуски того самого дня нічого не дублюють.
// Налаштовується разом з рештою email-сповіщень: Адмін-панель → Налаштування → Пошта →
// тікети → «Надсилати email-сповіщення». Потребує налаштованого SMTP.
// Код виходу: 0 — успіх (навіть якщо сповіщення вимкнені), 1 — помилка.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

use App\Services\NotificationService;

$stamp = static fn(string $msg): string => '[' . date('Y-m-d H:i:s') . '] [itsm-reminders] ' . $msg . "\n";

try {
    $result = NotificationService::sendDueDateReminders();
    if ($result['skipped_reason'] !== null) {
        echo $stamp($result['skipped_reason']);
        exit(0);
    }
    echo $stamp("Надіслано нагадувань: {$result['sent']}");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, $stamp('Неочікувана помилка: ' . get_class($e) . ': ' . $e->getMessage()));
    exit(1);
}
