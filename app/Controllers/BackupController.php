<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Audit;
use App\Models\Setting;
use App\Services\BackupService;

/**
 * Адмін-панель → Налаштування → Резервні копії: створити архів (БД + файли), скачати, видалити, задати, скільки копій тримати.
 * Лише адміністратор системи. Архів містить усі дані системи (хеші паролів, токени API, вкладення), тож кожну дію записано в журнал аудиту.
 */
class BackupController
{
    public function __construct()
    {
        Auth::requireLogin();
        if (!Auth::hasRole(['admin'])) {
            http_response_code(403);
            echo 'Резервні копії доступні лише ролі «Адміністратор системи».';
            exit;
        }
    }

    public function index(): void
    {
        $dir = BackupService::dir();
        $free = @disk_free_space(is_dir($dir) ? $dir : dirname($dir));
        View::render('admin/backup', [
            'backups' => BackupService::all(),
            'keep' => BackupService::keep(),
            'dir' => $dir,
            'writable' => is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir)),
            'free' => $free === false ? null : $free,
            'lastRun' => Setting::get('backup_last_run'),
            'lastSummary' => Setting::get('backup_last_summary'),
            'root' => dirname(__DIR__, 2),
            'success' => $_GET['success'] ?? null,
            'error' => $_GET['error'] ?? null,
        ]);
    }

    public function create(): void
    {
        try {
            $r = BackupService::create();
            $removed = BackupService::prune();
        } catch (\Throwable $e) {
            Setting::set('backup_last_summary', mb_substr('Помилка: ' . $e->getMessage(), 0, 250));
            $this->redirect('error', 'Копію не створено: ' . $e->getMessage());
        }
        $summary = sprintf('%s, %s; таблиць: %d, рядків: %d, файлів: %d%s; %d с', $r['file'], BackupService::human((float) $r['size']), $r['tables'], $r['rows'], $r['files'], $r['skipped'] ? ", пропущено: {$r['skipped']}" : '', $r['seconds']);
        Setting::set('backup_last_run', date('Y-m-d H:i:s'));
        Setting::set('backup_last_summary', mb_substr($summary, 0, 250));
        Audit::log('backup', 0, 'created', (int) Auth::id(), ['file' => $r['file'], 'size_kb' => (int) round($r['size'] / 1024)]);
        $this->redirect('success', 'Копію створено: ' . $summary . ($removed ? '. Видалено старі: ' . count($removed) : '') . ($r['skipped'] ? '. Деякі файли пропущено (нечитні або задовгі імена).' : ''));
    }

    public function download(array $params): void
    {
        $name = BackupService::nameForStamp((string) ($params['stamp'] ?? '')) ?? '';
        $path = BackupService::path($name);
        if ($path === null) {
            http_response_code(404);
            echo 'Копію не знайдено.';
            exit;
        }
        Audit::log('backup', 0, 'downloaded', (int) Auth::id(), ['file' => $name, 'size_kb' => (int) round(filesize($path) / 1024)]);
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        $fh = fopen($path, 'rb');
        while ($fh && !feof($fh)) {
            echo fread($fh, 1048576);
            flush();
        }
        exit;
    }

    public function delete(array $params): void
    {
        $name = BackupService::nameForStamp((string) ($params['stamp'] ?? '')) ?? '';
        if (!BackupService::delete($name)) {
            $this->redirect('error', 'Копію не знайдено або її не вдалося видалити.');
        }
        Audit::log('backup', 0, 'deleted', (int) Auth::id(), ['file' => $name]);
        $this->redirect('success', 'Копію ' . $name . ' видалено.');
    }

    public function settings(): void
    {
        $keep = (int) ($_POST['keep'] ?? 0);
        if ($keep < 1 || $keep > 365) {
            $this->redirect('error', 'Кількість копій має бути від 1 до 365.');
        }
        Setting::set('backup_keep', (string) $keep);
        Audit::log('app_settings', 0, 'backup_keep_updated', (int) Auth::id(), ['new' => $keep]);
        $removed = BackupService::prune($keep);
        $this->redirect('success', 'Збережено: тримати останніх копій — ' . $keep . ($removed ? '. Видалено зайві: ' . count($removed) : '') . '.');
    }

    private function redirect(string $key, string $message): never
    {
        header('Location: /admin/backup?' . $key . '=' . urlencode($message));
        exit;
    }
}
