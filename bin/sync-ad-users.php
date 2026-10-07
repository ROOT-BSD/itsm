<?php
// Синхронізація користувачів з Active Directory.
//
// Запуск (cron, раз на годину чи рідше — цілком достатньо для довідника користувачів):
//
//   0 * * * *  www-data  php /var/www/itsm-system/bin/sync-ad-users.php >> /var/log/itsm-ad-sync.log 2>&1
//
// Створює нових користувачів, знайдених у AD (за AD_SYNC_FILTER), оновлює вже
// існуючих (ім'я, роль за групою AD), деактивує тих, кого AD більше не
// повертає. Локальні ("local") облікові записи синхронізація НІКОЛИ не чіпає.
// Налаштовується разом з рештою AD-інтеграції: Адмін-панель → Налаштування → Active Directory
// → «Синхронізувати користувачів». Потребує налаштованого підключення в .env.
// Код виходу: 0 — успіх (навіть якщо вимкнено), 1 — помилка з'єднання/каталогу.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

use App\Services\AdSyncService;

$stamp = static fn(string $msg): string => '[' . date('Y-m-d H:i:s') . '] [itsm-ad-sync] ' . $msg . "\n";

try {
    $result = AdSyncService::sync();
    echo $stamp($result['message']);
    exit($result['status'] === 'error' ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, $stamp('Неочікувана помилка: ' . get_class($e) . ': ' . $e->getMessage()));
    exit(1);
}
