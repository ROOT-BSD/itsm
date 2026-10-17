<?php
// Резервна копія: дамп БД + storage/uploads + storage/wiki в один архів tar.gz (storage/backups або BACKUP_DIR).
//
// Запуск вручну або з cron (рекомендовано щоночі; для великих сховищ це надійніше за кнопку в адмін-панелі):
//
//   30 2 * * *  www-data  php /var/www/itsm-system/bin/backup.php >> /var/log/itsm-backup.log 2>&1
//
// Після створення залишається стільки найновіших копій, скільки задано в Адмін-панель → Налаштування → Резервні копії
// (типово 7). Файл .env до копії не входить. Запускайте від імені користувача веб-сервера (www-data), щоб файли
// архіву були доступні адмін-панелі для скачування.
// Шифрування: задайте BACKUP_PASSWORD у .env — нові копії зберігатимуться як .tar.gz.enc (AES-256).
// Перенесення: BACKUP_REMOTE_DIR (змонтований мережевий диск) і/або BACKUP_REMOTE_SCP=користувач@хост:/каталог/
// (+ BACKUP_SCP_KEY, BACKUP_SCP_PORT). Після створення копія перевіряється на цілісність.
// Код виходу: 0 — успіх, 1 — помилка або не пройдена перевірка/перенесення.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

use App\Models\Audit;
use App\Models\Setting;
use App\Services\BackupService;

$stamp = static fn(string $msg): string => '[' . date('Y-m-d H:i:s') . '] [itsm-backup] ' . $msg . "\n";

try {
    $r = BackupService::create();
    $removed = BackupService::prune();
    $post = BackupService::afterCreate($r); // перевірка цілісності й перенесення на інший носій/сервер
    $summary = BackupService::summary($r, $post);
    Setting::set('backup_last_run', date('Y-m-d H:i:s'));
    Setting::set('backup_last_summary', mb_substr($summary, 0, 250));
    Audit::log('backup', 0, 'created', null, ['file' => $r['file'], 'size_kb' => (int) round($r['size'] / 1024), 'via' => 'cron']);
    echo $stamp('Готово: ' . $summary);
    if ($removed) {
        echo $stamp('Видалено старі копії (' . count($removed) . '): ' . implode(', ', $removed));
    }
    $bad = !$post['verify']['ok'] || array_filter($post['transfer'], fn($t) => !$t['ok']);
    if ($bad) {
        fwrite(STDERR, $stamp('Увага: ' . $summary));
    }
    exit($bad ? 1 : 0);
} catch (\Throwable $e) {
    Setting::set('backup_last_summary', mb_substr('Помилка: ' . $e->getMessage(), 0, 250));
    fwrite(STDERR, $stamp('Помилка: ' . $e->getMessage()));
    exit(1);
}
