<?php
// Відправка черги вебхуків: усі доставки, термін яких настав (нові, що не встигли піти одразу, і повтори після невдач).
//
// Запускати з cron щохвилини (від імені користувача веб-сервера):
//
//   * * * * *  www-data  php /шлях/до/itsm-system/bin/deliver-webhooks.php >> /var/log/itsm-webhooks.log 2>&1
//
// Із PHP-FPM нові події відправляються самі одразу після відповіді користувачу, а cron потрібен для повторів (невдалі
// доставки повторюються через 1 хв, 5 хв, 30 хв, 2 год, 12 год). З Apache mod_php нові події ЛИШАЄТЬСЯ в черзі до
// запуску цього скрипта, тож без cron вебхуки з mod_php не працюватимуть.
//
// Паралельні запуски безпечні: другий одразу завершується, поки працює перший. Код виходу завжди 0 (збій одержувача —
// не збій скрипта); 1 — лише помилка самого скрипта (немає БД тощо).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

use App\Models\Setting;
use App\Models\WebhookEndpoint;
use App\Services\WebhookService;

$say = static fn(string $msg) => print('[' . date('Y-m-d H:i:s') . '] [itsm-webhooks] ' . $msg . "\n");

// Блокуємо сам файл скрипта (для цього не потрібні права запису в каталоги).
$lock = fopen(__FILE__, 'r');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $say('Попередній запуск ще працює — пропускаю.');
    exit(0);
}

try {
    if (!WebhookEndpoint::available()) {
        $say('Таблиць вебхуків немає (не виконано update.sh) — нічого робити.');
        exit(0);
    }
    Setting::set('webhooks_worker_last_run', date('Y-m-d H:i:s')); // адмін-панель показує, чи cron справді працює
    $result = WebhookService::processDue(100);
    WebhookService::purge();
    if ($result['sent'] > 0) {
        $say("Відправлено: {$result['sent']}, успішно: {$result['ok']}, невдало: " . ($result['sent'] - $result['ok']));
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '[itsm-webhooks] Помилка: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
exit(0);
