<?php

namespace App\Models;

use App\Core\Database;

/**
 * Вкладення сторінок вікі (таблиця wiki_attachments). Права на файл = права на його сторінку (WikiPage::visibleTo);
 * сам файл лежить у storage/uploads під випадковим іменем (див. AttachmentService / LibraryService).
 */
class WikiAttachment
{
    public const MAX_PER_PAGE = 100;
    /** Що показується на сторінці як картинка. */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png'];

    public static function isImage(string $mime): bool
    {
        return in_array($mime, self::IMAGE_MIMES, true);
    }

    /** @return array<int, array<string, mixed>> нові — внизу (порядок завантаження) */
    public static function forPage(int $pageId): array
    {
        if (!WikiPage::attachmentsReady()) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT a.*, u.full_name AS uploaded_by_name FROM wiki_attachments a
             LEFT JOIN users u ON u.id = a.uploaded_by WHERE a.page_id = :page ORDER BY a.id ASC'
        );
        $stmt->execute(['page' => $pageId]);
        return $stmt->fetchAll();
    }

    /** Мапа id => [name, mime, image] для Markdown. @return array<int, array{name: string, mime: string, image: bool}> */
    public static function mapForPage(int $pageId): array
    {
        $map = [];
        foreach (self::forPage($pageId) as $a) {
            $map[(int) $a['id']] = ['name' => $a['original_name'], 'mime' => $a['mime_type'], 'image' => self::isImage($a['mime_type'])];
        }
        return $map;
    }

    public static function find(int $id): ?array
    {
        if (!WikiPage::attachmentsReady()) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT * FROM wiki_attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function countForPage(int $pageId): int
    {
        if (!WikiPage::attachmentsReady()) {
            return 0;
        }
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM wiki_attachments WHERE page_id = :page');
        $stmt->execute(['page' => $pageId]);
        return (int) $stmt->fetchColumn();
    }

    public static function create(int $pageId, string $name, string $storedName, string $mime, int $size, ?int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO wiki_attachments (page_id, original_name, stored_name, mime_type, size_bytes, uploaded_by)
             VALUES (:page, :name, :stored, :mime, :size, :user)'
        )->execute(['page' => $pageId, 'name' => $name, 'stored' => $storedName, 'mime' => $mime, 'size' => $size, 'user' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM wiki_attachments WHERE id = :id')->execute(['id' => $id]);
    }

    /** Імена файлів у сховищі для всіх вкладень сторінки (до її видалення). @return string[] */
    public static function storedNamesForPage(int $pageId): array
    {
        return array_column(self::forPage($pageId), 'stored_name');
    }
}
