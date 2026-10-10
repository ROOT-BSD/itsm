<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Models\Attachment;
use App\Models\Audit;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Services\AttachmentService;

/**
 * Вкладення до тікетів і задач. Права успадковуються від власника: хто бачить тікет чи
 * задачу — той бачить і завантажує вкладення; видаляє лише автор вкладення або адміністратор.
 * Файли віддаються виключно через download() — прямого URL на файл у веб-корені немає.
 */
class AttachmentController
{
    public function uploadToTicket(array $params): void
    {
        $this->upload('ticket', (int) $params['id']);
    }

    public function uploadToTask(array $params): void
    {
        $this->upload('task', (int) $params['id']);
    }

    private function upload(string $ownerType, int $ownerId): void
    {
        Auth::requireLogin();
        $this->requireAccess($ownerType, $ownerId);

        $files = array_values(array_filter(
            AttachmentService::normalizeFiles($_FILES['files'] ?? []),
            fn(array $f): bool => $f['error'] !== UPLOAD_ERR_NO_FILE
        ));
        if (!$files) {
            $this->redirect($ownerType, $ownerId, 'error', 'Оберіть файл для прикріплення (JPG, PNG або PDF).');
        }

        $maxPerRequest = (int) Config::get('attachments.max_per_request', 10);
        if (count($files) > $maxPerRequest) {
            $this->redirect($ownerType, $ownerId, 'error', "За один раз можна прикріпити не більше {$maxPerRequest} файлів.");
        }
        $maxPerOwner = (int) Config::get('attachments.max_per_entity', 30);
        if (Attachment::countForOwner($ownerType, $ownerId) + count($files) > $maxPerOwner) {
            $this->redirect($ownerType, $ownerId, 'error', "До одного запису можна прикріпити не більше {$maxPerOwner} файлів.");
        }

        $result = AttachmentService::attachUploads($ownerType, $ownerId, $files, Auth::id(), 'web');
        $added = $result['added'];
        $errors = $result['errors'];

        $this->redirect(
            $ownerType,
            $ownerId,
            null,
            null,
            $added > 0 ? 'Прикріплено файлів: ' . $added . '.' : null,
            $errors ? implode(' ', $errors) : null
        );
    }

    public function download(array $params): void
    {
        Auth::requireLogin();
        $attachment = $this->findOrFail((int) $params['id']);
        [$ownerType, $ownerId] = $this->owner($attachment);
        $this->requireAccess($ownerType, $ownerId);

        self::send($attachment);
    }

    /**
     * Віддає файл вкладення (спільне для співробітників і для заявника порталу): ?thumb=1 — зменшена копія, ?download=1 —
     * примусове завантаження. Права перевіряє той, хто викликає, — тут лише віддача.
     */
    public static function send(array $attachment): never
    {
        $path = AttachmentService::path($attachment['stored_name']);
        if ($path === null || !is_file($path)) {
            http_response_code(404);
            echo 'Файл не знайдено на сервері (можливо, його видалено з диска). Зверніться до адміністратора.';
            exit;
        }

        // ?thumb=1 — зменшена копія для списків (створена при завантаженні або, для старих вкладень, при першому запиті).
        // Якщо мініатюри немає (мале зображення, PDF, немає GD) — віддаємо оригінал. Повний файл — без ?thumb.
        $servePath = $path;
        if (isset($_GET['thumb']) && !isset($_GET['download']) && str_starts_with($attachment['mime_type'], 'image/')) {
            $servePath = AttachmentService::thumbnailFor($attachment['stored_name'], $attachment['mime_type']) ?? $path;
        }

        // Зображення та PDF показуємо у браузері; ?download=1 — примусове завантаження.
        $disposition = isset($_GET['download']) ? 'attachment' : 'inline';
        $name = $attachment['original_name'];
        $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';

        // Звільняємо сесійний замок перед довгою віддачею файлу, щоб не блокувати решту
        // запитів цього користувача, поки триває завантаження.
        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Length: ' . filesize($servePath));
        header('Content-Disposition: ' . $disposition . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        readfile($servePath);
        exit;
    }

    public function delete(array $params): void
    {
        Auth::requireLogin();
        $attachment = $this->findOrFail((int) $params['id']);
        [$ownerType, $ownerId] = $this->owner($attachment);
        $this->requireAccess($ownerType, $ownerId);

        if ((int) $attachment['uploaded_by'] !== Auth::id() && !Auth::hasRole(['admin'])) {
            http_response_code(403);
            echo 'Видалити вкладення може лише той, хто його додав, або адміністратор системи.';
            return;
        }

        Attachment::delete((int) $attachment['id']);
        AttachmentService::deleteFiles([$attachment['stored_name']]);
        Audit::log($ownerType, $ownerId, 'attachment_deleted', Auth::id(), ['file' => $attachment['original_name']]);

        $this->redirect($ownerType, $ownerId, 'success', 'Вкладення «' . $attachment['original_name'] . '» видалено.');
    }

    private function findOrFail(int $id): array
    {
        $attachment = Attachment::find($id);
        if (!$attachment) {
            http_response_code(404);
            echo 'Вкладення не знайдено.';
            exit;
        }
        return $attachment;
    }

    /** @return array{0: string, 1: int} */
    private function owner(array $attachment): array
    {
        return $attachment['ticket_id'] !== null
            ? ['ticket', (int) $attachment['ticket_id']]
            : ['task', (int) $attachment['task_id']];
    }

    /**
     * Ті самі правила видимості, що й у TicketController/TaskController: вкладення бачить і
     * додає той, хто бачить сам тікет чи задачу (для задачі — ту, чий проєкт йому доступний).
     */
    private function requireAccess(string $ownerType, int $ownerId): void
    {
        $isAdmin = Auth::hasRole(['admin']);

        if ($ownerType === 'ticket') {
            $ticket = Ticket::find($ownerId);
            if (!$ticket) {
                http_response_code(404);
                echo 'Тікет не знайдено.';
                exit;
            }
            $canSeeUnassigned = Auth::hasRole(['it_manager', 'support_operator']);
            if (!Ticket::isVisibleTo($ticket, Auth::id(), $isAdmin, $canSeeUnassigned)) {
                http_response_code(403);
                echo 'Доступ до цього тікета обмежено.';
                exit;
            }
            return;
        }

        $task = Task::find($ownerId);
        $project = $task ? Project::find((int) $task['project_id']) : null;
        if (!$task || !$project) {
            http_response_code(404);
            echo 'Задачу не знайдено.';
            exit;
        }
        if (!Project::isVisibleTo($project, Auth::id(), $isAdmin)) {
            http_response_code(403);
            echo 'Доступ до цього проєкту обмежено.';
            exit;
        }
    }

    /**
     * Повернення на сторінку власника з повідомленням. Підтримує одночасно успіх і помилку
     * (частина файлів прийнялась, частина — ні); перші два аргументи — зручний шлях для одного.
     */
    private function redirect(string $ownerType, int $ownerId, ?string $key, ?string $message, ?string $success = null, ?string $error = null): never
    {
        if ($key === 'error') {
            $error = $message;
        } elseif ($key === 'success') {
            $success = $message;
        }
        $query = [];
        if ($success) {
            $query['success'] = $success;
        }
        if ($error) {
            $query['error'] = $error;
        }
        $base = ($ownerType === 'ticket' ? '/tickets/' : '/tasks/') . $ownerId;
        header('Location: ' . $base . ($query ? '?' . http_build_query($query) : ''));
        exit;
    }
}
