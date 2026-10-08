<?php

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

/**
 * Бібліотека документів (таблиці library_categories, library_documents, library_versions). Права визначаються
 * ЛИШЕ тут і в контролері через visibleTo()/canEdit()/canDelete() — шаблони й запити не вигадують власних правил.
 * Видимість документа — ті самі три рівні, що й у вікі (WikiPage::VISIBILITIES), щоб правила не розходились.
 */
class LibraryDocument
{
    public const MAX_TITLE = 200;
    public const MAX_DESCRIPTION = 5000;

    // ------------------------------------------------------------------ права

    /** @return array<string, string> */
    public static function visibilities(): array
    {
        return WikiPage::VISIBILITIES;
    }

    /** @return string[] */
    public static function allowedVisibilities(?string $role): array
    {
        return WikiPage::allowedVisibilities($role);
    }

    public static function visibleTo(array $document, ?string $role): bool
    {
        return in_array($document['visibility'], self::allowedVisibilities($role), true);
    }

    /** Завантажувати документи й нові версії, правити описи, керувати розділами. */
    public static function canEdit(?string $role): bool
    {
        return in_array($role, ['admin', 'it_manager'], true);
    }

    /** Видаляти документ чи окрему версію: адміністратор або редактор, який документ створив. */
    public static function canDelete(?string $role, ?int $userId, array $document): bool
    {
        return $role === 'admin' || (self::canEdit($role) && $userId !== null && (int) $document['created_by'] === $userId);
    }

    public static function tablesExist(): bool
    {
        return Database::tableExists('library_documents') && Database::tableExists('library_versions') && Database::tableExists('library_categories');
    }

    // ------------------------------------------------------------------ читання

    private const LATEST_JOIN = 'JOIN library_versions v ON v.document_id = d.id
         AND v.version_no = (SELECT MAX(version_no) FROM library_versions WHERE document_id = d.id)';

    /** Умови відбору (видимість, розділ, пошук) і параметри для списку й підрахунку. @return array{0: string, 1: array<int, mixed>} */
    private static function filter(?string $role, string|int|null $category, string $query): array
    {
        $allowed = self::allowedVisibilities($role);
        $where = ['d.visibility IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')'];
        $params = $allowed;

        if ($category === 'none') {
            $where[] = 'd.category_id IS NULL';
        } elseif ($category !== null && $category !== '') {
            $where[] = 'd.category_id = ?';
            $params[] = (int) $category;
        }
        if ($query !== '') {
            $like = '%' . addcslashes($query, '\\%_') . '%';
            $where[] = '(d.title LIKE ? OR d.description LIKE ? OR v.original_name LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Сторінка списку документів, видимих ролі, з даними поточної версії.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function search(?string $role, string|int|null $category, string $query, int $page, int $perPage): array
    {
        [$where, $params] = self::filter($role, $category, $query);
        $pdo = Database::connection();

        $count = $pdo->prepare('SELECT COUNT(*) FROM library_documents d ' . self::LATEST_JOIN . ' WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $pdo->prepare(
            'SELECT d.*, c.name AS category_name, v.id AS version_id, v.version_no, v.original_name, v.mime_type,
                    v.size_bytes, v.created_at AS version_at, u.full_name AS uploader_name
             FROM library_documents d
             ' . self::LATEST_JOIN . '
             LEFT JOIN library_categories c ON c.id = d.category_id
             LEFT JOIN users u ON u.id = v.uploaded_by
             WHERE ' . $where . '
             ORDER BY d.title ASC, d.id ASC
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.*, c.name AS category_name, cu.full_name AS created_by_name, uu.full_name AS updated_by_name
             FROM library_documents d
             LEFT JOIN library_categories c ON c.id = d.category_id
             LEFT JOIN users cu ON cu.id = d.created_by
             LEFT JOIN users uu ON uu.id = d.updated_by
             WHERE d.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Усі версії документа, найновіші першими. @return array<int, array<string, mixed>> */
    public static function versions(int $documentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT v.*, u.full_name AS uploader_name FROM library_versions v
             LEFT JOIN users u ON u.id = v.uploaded_by
             WHERE v.document_id = :id ORDER BY v.version_no DESC'
        );
        $stmt->execute(['id' => $documentId]);
        return $stmt->fetchAll();
    }

    public static function latestVersion(int $documentId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM library_versions WHERE document_id = :id ORDER BY version_no DESC LIMIT 1'
        );
        $stmt->execute(['id' => $documentId]);
        return $stmt->fetch() ?: null;
    }

    public static function findVersion(int $versionId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM library_versions WHERE id = :id');
        $stmt->execute(['id' => $versionId]);
        return $stmt->fetch() ?: null;
    }

    public static function versionCount(int $documentId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM library_versions WHERE document_id = :id');
        $stmt->execute(['id' => $documentId]);
        return (int) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------ розділи

    /**
     * Розділи з кількістю ВИДИМИХ ролі документів (щоб лічильник не видавав існування прихованих).
     *
     * @return array{categories: array<int, array<string, mixed>>, uncategorized: int, total: int}
     */
    public static function categoriesWithCounts(?string $role): array
    {
        $allowed = self::allowedVisibilities($role);
        $in = implode(',', array_fill(0, count($allowed), '?'));
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT c.id, c.name, c.sort_order, COUNT(d.id) AS documents
             FROM library_categories c
             LEFT JOIN library_documents d ON d.category_id = c.id AND d.visibility IN ({$in})
             GROUP BY c.id, c.name, c.sort_order
             ORDER BY c.sort_order, c.name"
        );
        $stmt->execute($allowed);
        $categories = $stmt->fetchAll();

        $none = $pdo->prepare("SELECT COUNT(*) FROM library_documents WHERE category_id IS NULL AND visibility IN ({$in})");
        $none->execute($allowed);
        $uncategorized = (int) $none->fetchColumn();

        return [
            'categories' => $categories,
            'uncategorized' => $uncategorized,
            'total' => $uncategorized + array_sum(array_map(static fn(array $c): int => (int) $c['documents'], $categories)),
        ];
    }

    public static function categoryExists(int $id): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM library_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return int|null id нового розділу або null, якщо така назва вже є */
    public static function createCategory(string $name, int $sortOrder): ?int
    {
        try {
            Database::connection()->prepare('INSERT INTO library_categories (name, sort_order) VALUES (:n, :s)')
                ->execute(['n' => $name, 's' => $sortOrder]);
            return (int) Database::connection()->lastInsertId();
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $e;
        }
    }

    /** @return bool false — назва зайнята іншим розділом */
    public static function updateCategory(int $id, string $name, int $sortOrder): bool
    {
        try {
            Database::connection()->prepare('UPDATE library_categories SET name = :n, sort_order = :s WHERE id = :id')
                ->execute(['n' => $name, 's' => $sortOrder, 'id' => $id]);
            return true;
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }
    }

    public static function findCategory(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM library_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Документи розділу не видаляються — FK ставить їм category_id = NULL. */
    public static function deleteCategory(int $id): void
    {
        Database::connection()->prepare('DELETE FROM library_categories WHERE id = :id')->execute(['id' => $id]);
    }

    // ------------------------------------------------------------------ запис

    /**
     * Створює документ разом із першою версією в одній транзакції.
     *
     * @param array{name: string, stored_name: string, mime: string, size: int} $file
     * @return int id документа
     */
    public static function create(string $title, ?string $description, ?int $categoryId, string $visibility, array $file, ?string $comment, ?int $userId): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO library_documents (category_id, title, description, visibility, created_by, updated_by)
                 VALUES (:c, :t, :d, :v, :u, :u2)'
            )->execute(['c' => $categoryId, 't' => $title, 'd' => $description, 'v' => $visibility, 'u' => $userId, 'u2' => $userId]);
            $id = (int) $pdo->lastInsertId();
            self::insertVersion($id, 1, $file, $comment, $userId);
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Додає нову версію. Номер визначається під блокуванням рядка документа, тож два одночасні завантаження
     * не отримають однаковий номер.
     *
     * @param array{name: string, stored_name: string, mime: string, size: int} $file
     * @return int номер створеної версії
     */
    public static function addVersion(int $documentId, array $file, ?string $comment, ?int $userId): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM library_documents WHERE id = :id FOR UPDATE')->execute(['id' => $documentId]);
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM library_versions WHERE document_id = :id');
            $stmt->execute(['id' => $documentId]);
            $number = (int) $stmt->fetchColumn();

            self::insertVersion($documentId, $number, $file, $comment, $userId);
            $pdo->prepare('UPDATE library_documents SET updated_by = :u WHERE id = :id')->execute(['u' => $userId, 'id' => $documentId]);
            $pdo->commit();
            return $number;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function insertVersion(int $documentId, int $number, array $file, ?string $comment, ?int $userId): void
    {
        Database::connection()->prepare(
            'INSERT INTO library_versions (document_id, version_no, original_name, stored_name, mime_type, size_bytes, comment, uploaded_by)
             VALUES (:d, :n, :name, :stored, :mime, :size, :comment, :u)'
        )->execute([
            'd' => $documentId, 'n' => $number, 'name' => $file['name'], 'stored' => $file['stored_name'],
            'mime' => $file['mime'], 'size' => $file['size'], 'comment' => $comment, 'u' => $userId,
        ]);
    }

    public static function update(int $id, string $title, ?string $description, ?int $categoryId, string $visibility, ?int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE library_documents SET title = :t, description = :d, category_id = :c, visibility = :v, updated_by = :u WHERE id = :id'
        )->execute(['t' => $title, 'd' => $description, 'c' => $categoryId, 'v' => $visibility, 'u' => $userId, 'id' => $id]);
    }

    /** Імена файлів на диску для видалення разом із документом — брати ДО delete(): каскад прибере рядки, а не файли. @return string[] */
    public static function storedNames(int $documentId): array
    {
        $stmt = Database::connection()->prepare('SELECT stored_name FROM library_versions WHERE document_id = :id');
        $stmt->execute(['id' => $documentId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM library_documents WHERE id = :id')->execute(['id' => $id]);
    }

    public static function deleteVersion(int $versionId): void
    {
        Database::connection()->prepare('DELETE FROM library_versions WHERE id = :id')->execute(['id' => $versionId]);
    }

    public static function maxVersions(): int
    {
        return (int) Config::get('library.max_versions', 50);
    }
}
