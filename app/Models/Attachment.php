<?php

namespace App\Models;

use App\Core\Database;

/**
 * Вкладення до тікетів і задач (рядки таблиці attachments). Сам файл на диску —
 * справа App\Services\AttachmentService; тут лише метадані.
 *
 * Власник — рівно один: тікет або задача. Тип власника передається рядками
 * 'ticket' / 'task' і зіставляється зі СПИСКОМ дозволених стовпців (а не підставляється
 * в SQL напряму), тож у запит нічого стороннього потрапити не може.
 */
class Attachment
{
    private const OWNER_COLUMNS = ['ticket' => 'ticket_id', 'task' => 'task_id'];

    private static function column(string $ownerType): string
    {
        if (!isset(self::OWNER_COLUMNS[$ownerType])) {
            throw new \InvalidArgumentException("Невідомий тип власника вкладення: {$ownerType}");
        }
        return self::OWNER_COLUMNS[$ownerType];
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Вкладення тікета/задачі (найновіші внизу) разом з іменем того, хто завантажив. */
    public static function forOwner(string $ownerType, int $ownerId): array
    {
        $column = self::column($ownerType);
        $stmt = Database::connection()->prepare(
            "SELECT a.*, u.full_name AS uploader_name
             FROM attachments a
             LEFT JOIN users u ON u.id = a.uploaded_by
             WHERE a.{$column} = :owner_id
             ORDER BY a.created_at ASC, a.id ASC"
        );
        $stmt->execute(['owner_id' => $ownerId]);
        return $stmt->fetchAll();
    }

    public static function countForOwner(string $ownerType, int $ownerId): int
    {
        $column = self::column($ownerType);
        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM attachments WHERE {$column} = :owner_id");
        $stmt->execute(['owner_id' => $ownerId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param string $source звідки файл: 'web' (сторінка/форма системи), 'portal' (анонімний портал), 'email' (лист) */
    public static function create(string $ownerType, int $ownerId, string $originalName, string $storedName, string $mimeType, int $sizeBytes, ?int $uploadedBy, string $source = 'web'): int
    {
        $column = self::column($ownerType);
        $stmt = Database::connection()->prepare(
            "INSERT INTO attachments ({$column}, original_name, stored_name, mime_type, size_bytes, uploaded_by, source)
             VALUES (:owner_id, :original_name, :stored_name, :mime_type, :size_bytes, :uploaded_by, :source)"
        );
        $stmt->execute([
            'owner_id' => $ownerId,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'uploaded_by' => $uploadedBy,
            'source' => in_array($source, ['web', 'portal', 'email'], true) ? $source : 'web',
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM attachments WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Імена файлів на диску для видалення разом із задачею — викликати ДО видалення задачі:
     * каскад у БД прибере рядки, але файли на диску лишилися б без жодного посилання.
     *
     * @return string[]
     */
    public static function storedNamesForTask(int $taskId): array
    {
        $stmt = Database::connection()->prepare('SELECT stored_name FROM attachments WHERE task_id = :id');
        $stmt->execute(['id' => $taskId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** Те саме для всіх задач проєкту (видалення проєкту каскадно видаляє його задачі). @return string[] */
    public static function storedNamesForProject(int $projectId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT a.stored_name FROM attachments a JOIN tasks t ON t.id = a.task_id WHERE t.project_id = :id'
        );
        $stmt->execute(['id' => $projectId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
