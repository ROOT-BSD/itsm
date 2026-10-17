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
    public const CONFIRM_WORD = 'ВІДНОВИТИ';

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
            'lastCheck' => Setting::get('backup_last_check'),
            'encryptionOn' => BackupService::password() !== '',
            'remoteDir' => (string) \App\Core\Config::get('backup.remote_dir', ''),
            'remoteScp' => (string) \App\Core\Config::get('backup.remote_scp', ''),
            'root' => dirname(__DIR__, 2),
            'uploadMax' => \App\Core\UploadLimits::human(\App\Core\UploadLimits::effectiveFileMax(PHP_INT_MAX)),
            'restoreWord' => self::CONFIRM_WORD,
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
        $post = BackupService::afterCreate($r);
        $summary = BackupService::summary($r, $post);
        Setting::set('backup_last_run', date('Y-m-d H:i:s'));
        Setting::set('backup_last_summary', mb_substr($summary, 0, 250));
        Audit::log('backup', 0, 'created', (int) Auth::id(), ['file' => $r['file'], 'size_kb' => (int) round($r['size'] / 1024)]);
        $bad = !$post['verify']['ok'] || array_filter($post['transfer'], fn($t) => !$t['ok']);
        $this->redirect($bad ? 'error' : 'success', 'Копію створено: ' . $summary . ($removed ? '. Видалено старі: ' . count($removed) : '') . ($r['skipped'] ? '. Деякі файли пропущено (нечитні або задовгі імена).' : ''));
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
        header('Content-Type: ' . (BackupService::isEncrypted($name) ? 'application/octet-stream' : 'application/gzip'));
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

    /** «Перевірити копію»: цілісність архіву без розпакування на диск (зашифровані — паролем з .env). */
    public function verify(array $params): void
    {
        $name = BackupService::nameForStamp((string) ($params['stamp'] ?? '')) ?? '';
        @set_time_limit(0);
        $r = BackupService::verify($name);
        Audit::log('backup', 0, 'verified', (int) Auth::id(), ['file' => $name, 'ok' => $r['ok'] ? 'так' : 'ні']);
        $this->redirect($r['ok'] ? 'success' : 'error', $name . ': ' . $r['message']);
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

    /**
     * «Відновити з копії»: джерело — наявна копія зі сховища (source=existing, stamp) або файл з ПК (source=upload).
     * ПЕРЕЗАПИСУЄ базу даних і файли. Потрібно ввести слово-підтвердження; перед відновленням створюється страхувальна копія.
     */
    public function restore(): void
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        if (trim((string) ($_POST['confirm'] ?? '')) !== self::CONFIRM_WORD) {
            $this->redirect('error', 'Відновлення не виконано: введіть слово «' . self::CONFIRM_WORD . '» для підтвердження.');
        }
        $source = (string) ($_POST['source'] ?? '');
        $tmp = null;
        $label = '';
        if ($source === 'existing') {
            $name = BackupService::nameForStamp((string) ($_POST['stamp'] ?? '')) ?? '';
            $file = BackupService::path($name);
            if ($file === null) {
                $this->redirect('error', 'Оберіть копію зі списку.');
            }
            $label = $name;
        } elseif ($source === 'upload') {
            $f = $_FILES['backup_file'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
                $this->redirect('error', match ($code) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл завеликий для завантаження (ліміт сервера: ' . \App\Core\UploadLimits::human(\App\Core\UploadLimits::effectiveFileMax(PHP_INT_MAX)) . '). Покладіть його в каталог копій на сервері або скористайтесь bin/restore.php.',
                    UPLOAD_ERR_NO_FILE => 'Оберіть файл копії на комп’ютері.',
                    default => 'Файл не завантажився (код ' . $code . ').',
                });
            }
            $orig = basename((string) ($f['name'] ?? ''));
            if (!preg_match('/\.tar\.gz(\.enc)?$/i', $orig)) {
                $this->redirect('error', 'Потрібен файл копії: .tar.gz або .tar.gz.enc.');
            }
            $dir = BackupService::dir();
            $tmp = (is_dir($dir) && is_writable($dir) ? $dir : sys_get_temp_dir()) . '/.upload-' . bin2hex(random_bytes(6));
            if (!move_uploaded_file($f['tmp_name'], $tmp)) {
                $this->redirect('error', 'Не вдалося прийняти завантажений файл.');
            }
            $file = $tmp;
            $label = $orig . ' (з комп’ютера)';
        } else {
            $this->redirect('error', 'Оберіть джерело копії.');
        }

        $safety = !empty($_POST['safety']);
        $uid = (int) Auth::id();
        Audit::log('backup', 0, 'restore_started', $uid, ['source' => $label, 'safety' => $safety ? 'так' : 'ні']);
        $lines = [];
        try {
            $r = BackupService::restore($file, ['apply' => true, 'safety' => $safety, 'password' => (string) ($_POST['password'] ?? '')], static function (string $m) use (&$lines): void { $lines[] = $m; });
        } catch (\RuntimeException $e) {
            $this->finishRestore($tmp);
            $this->redirect('error', 'Відновлення не виконано: ' . $e->getMessage());
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
        // База щойно замінена: користувача з поточної сесії в ній може не бути — журнал не повинен ламати відповідь.
        try {
            Audit::log('backup', 0, 'restored', $uid, ['source' => $label, 'safety_copy' => $r['safety'] ?? '—']);
        } catch (\Throwable) {
        }
        $this->redirect('success', 'Відновлено з ' . $label . '. ' . ($r['safety'] ? 'Страхувальна копія попереднього стану: ' . $r['safety'] . '. ' : '') . 'Виконайте sudo bash update.sh (права, схема) і перевірте «Стан системи». Якщо вас розлогінило — увійдіть знову.');
    }

    private function finishRestore(?string $tmp): void
    {
        if ($tmp !== null) {
            @unlink($tmp);
        }
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
