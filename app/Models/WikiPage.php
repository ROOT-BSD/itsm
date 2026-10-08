<?php

namespace App\Models;

use App\Core\Database;

/**
 * Сторінки вікі (таблиці wiki_pages, wiki_revisions). Права визначаються ЛИШЕ тут і в контролері через
 * visibleTo()/canEdit()/canDelete() — шаблони й запити не вигадують власних правил.
 */
class WikiPage
{
    public const VISIBILITIES = ['all' => 'Усі, хто увійшов', 'staff' => 'Персонал (без заявників)', 'admin' => 'Лише адміністратор'];
    public const MAX_CONTENT_CHARS = 300000;
    /** Максимальна глибина вкладеності (кореневі сторінки — рівень 1). */
    public const MAX_DEPTH = 6;
    /** Адреси, що збігаються з маршрутами вікі, — для сторінок заборонені. */
    private const RESERVED_SLUGS = ['new', 'preview', 'search', 'edit', 'history', 'revisions', 'delete', 'files', 'attachments'];

    // ------------------------------------------------------------------ права

    /** Які значення visibility бачить користувач із цією роллю. @return string[] */
    public static function allowedVisibilities(?string $role): array
    {
        $allowed = ['all'];
        if (in_array($role, ['admin', 'unit_admin', 'it_manager', 'sysadmin', 'support_operator', 'observer'], true)) {
            $allowed[] = 'staff';
        }
        if ($role === 'admin') {
            $allowed[] = 'admin';
        }
        return $allowed;
    }

    public static function visibleTo(array $page, ?string $role): bool
    {
        return in_array($page['visibility'], self::allowedVisibilities($role), true);
    }

    public static function canEdit(?string $role): bool
    {
        return in_array($role, ['admin', 'it_manager'], true);
    }

    /** Сторінки з імпорту документації не видаляються (наступний імпорт створив би їх знову). */
    public static function canDelete(?string $role, array $page): bool
    {
        return $role === 'admin' && ($page['source'] ?? null) !== 'docs';
    }

    // ------------------------------------------------------------------ читання

    public static function findBySlug(string $slug): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.*, u.full_name AS updated_by_name, c.full_name AS created_by_name
             FROM wiki_pages p
             LEFT JOIN users u ON u.id = p.updated_by
             LEFT JOIN users c ON c.id = p.created_by
             WHERE p.slug = :slug'
        );
        $stmt->execute(['slug' => $slug]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM wiki_pages WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Список сторінок, видимих ролі (без вмісту — він може бути великим). @return array<int, array<string, mixed>> */
    public static function listVisible(?string $role): array
    {
        $allowed = self::allowedVisibilities($role);
        $in = implode(',', array_fill(0, count($allowed), '?'));
        $parent = self::hierarchyReady() ? 'p.parent_id' : 'NULL AS parent_id';
        $stmt = Database::connection()->prepare(
            "SELECT p.id, p.slug, p.title, p.visibility, p.sort_order, p.version, p.source, p.updated_at, $parent, u.full_name AS updated_by_name
             FROM wiki_pages p LEFT JOIN users u ON u.id = p.updated_by
             WHERE p.visibility IN ($in)
             ORDER BY p.sort_order ASC, p.title ASC"
        );
        $stmt->execute($allowed);
        return $stmt->fetchAll();
    }

    /**
     * Пошук за назвою й текстом серед сторінок, видимих ролі. Спецсимволи LIKE (% _ \) екрануються — запит
     * «50%» шукає саме «50%», а не «усе». Повертає і вміст: з нього контролер робить фрагмент для показу.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $query, ?string $role, int $limit = 30): array
    {
        $allowed = self::allowedVisibilities($role);
        $in = implode(',', array_fill(0, count($allowed), '?'));
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
        $stmt = Database::connection()->prepare(
            "SELECT p.id, p.slug, p.title, p.content, p.updated_at,
                    (p.title LIKE ? ESCAPE '\\\\') AS in_title
             FROM wiki_pages p
             WHERE p.visibility IN ($in) AND (p.title LIKE ? ESCAPE '\\\\' OR p.content LIKE ? ESCAPE '\\\\')
             ORDER BY in_title DESC, p.sort_order ASC, p.title ASC
             LIMIT " . (int) $limit
        );
        $stmt->execute(array_merge([$like], $allowed, [$like, $like]));
        return $stmt->fetchAll();
    }

    /** @return string[] */
    public static function allSlugs(): array
    {
        return Database::connection()->query('SELECT slug FROM wiki_pages')->fetchAll(\PDO::FETCH_COLUMN);
    }

    // ------------------------------------------------------------------ ієрархія

    /** Чи застосовано міграцію 026 (колонка parent_id). Без неї вікі працює плоским списком. */
    public static function hierarchyReady(): bool
    {
        return Database::columnExists('wiki_pages', 'parent_id');
    }

    /** Чи застосовано міграцію 026 (таблиця вкладень). */
    public static function attachmentsReady(): bool
    {
        return Database::tableExists('wiki_attachments');
    }

    /**
     * Розкладає список сторінок у дерево й повертає його ПЛОСКИМ списком у порядку обходу в глибину, кожна
     * сторінка отримує 'depth' (0 — верхній рівень). Сторінка, батька якої немає в переданому списку (його не
     * видно цій ролі), показується на верхньому рівні: так не розкриваємо назви прихованих сторінок і не
     * губимо видимі. Цикли (не мають виникати) не зациклюють обхід.
     *
     * @param array<int, array<string, mixed>> $pages із listVisible()
     * @return array<int, array<string, mixed>>
     */
    public static function tree(array $pages): array
    {
        $ids = [];
        foreach ($pages as $p) {
            $ids[(int) $p['id']] = true;
        }
        $children = [];
        foreach ($pages as $p) {
            $parent = $p['parent_id'] !== null && isset($ids[(int) $p['parent_id']]) && (int) $p['parent_id'] !== (int) $p['id'] ? (int) $p['parent_id'] : 0;
            $children[$parent][] = $p;
        }
        $out = [];
        $seen = [];
        $walk = static function (int $parent, int $depth) use (&$walk, &$children, &$out, &$seen): void {
            foreach ($children[$parent] ?? [] as $p) {
                $id = (int) $p['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $p['depth'] = $depth;
                $p['has_children'] = !empty($children[$id]);
                $out[] = $p;
                $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);
        // Сторінки з циклу (недосяжні від кореня) — наприкінці, на верхньому рівні.
        foreach ($pages as $p) {
            if (!isset($seen[(int) $p['id']])) {
                $p['depth'] = 0;
                $p['has_children'] = false;
                $out[] = $p;
            }
        }
        return $out;
    }

    /** Предки сторінки від кореня до батька, лише ті, що є у списку видимих ($visible із listVisible()). */
    public static function ancestors(array $page, array $visible): array
    {
        $byId = [];
        foreach ($visible as $p) {
            $byId[(int) $p['id']] = $p;
        }
        $chain = [];
        $guard = 0;
        $current = $page['parent_id'] ?? null;
        while ($current !== null && isset($byId[(int) $current]) && $guard++ < 20) {
            $parent = $byId[(int) $current];
            array_unshift($chain, $parent);
            $current = $parent['parent_id'];
        }
        return $chain;
    }

    /** Прямі підсторінки (з видимих), у порядку sort_order, назва. */
    public static function childrenOf(int $pageId, array $visible): array
    {
        return array_values(array_filter($visible, static fn(array $p): bool => $p['parent_id'] !== null && (int) $p['parent_id'] === $pageId));
    }

    /** id усіх нащадків сторінки (будь-якої глибини), включно з невидимими цій ролі. @return int[] */
    public static function descendantIds(int $pageId): array
    {
        if (!self::hierarchyReady()) {
            return [];
        }
        $found = [];
        $frontier = [$pageId];
        for ($level = 0; $level < 50 && $frontier; $level++) {
            $in = implode(',', array_fill(0, count($frontier), '?'));
            $stmt = Database::connection()->prepare("SELECT id FROM wiki_pages WHERE parent_id IN ($in)");
            $stmt->execute($frontier);
            $frontier = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $id = (int) $id;
                if ($id !== $pageId && !isset($found[$id])) {
                    $found[$id] = true;
                    $frontier[] = $id;
                }
            }
        }
        return array_keys($found);
    }

    /** Скільки рівнів над сторінкою (0 — вона сама корінь). */
    private static function depthOf(int $pageId): int
    {
        $depth = 0;
        $current = $pageId;
        while ($depth < 50) {
            $stmt = Database::connection()->prepare('SELECT parent_id FROM wiki_pages WHERE id = :id');
            $stmt->execute(['id' => $current]);
            $parent = $stmt->fetchColumn();
            if ($parent === false || $parent === null) {
                break;
            }
            $current = (int) $parent;
            $depth++;
        }
        return $depth;
    }

    /**
     * Чому не можна зробити $parentId батьком сторінки $pageId ($pageId = null — для нової сторінки);
     * null — можна. Правила: батько існує; не сама сторінка і не її нащадок (інакше утвориться цикл);
     * глибина не перевищує MAX_DEPTH.
     */
    public static function parentProblem(?int $pageId, int $parentId): ?string
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM wiki_pages WHERE id = :id');
        $stmt->execute(['id' => $parentId]);
        if ((int) $stmt->fetchColumn() === 0) {
            return 'Обрана батьківська сторінка не існує.';
        }
        if ($pageId !== null && ($parentId === $pageId || in_array($parentId, self::descendantIds($pageId), true))) {
            return 'Сторінку не можна зробити підсторінкою самої себе чи її власної підсторінки.';
        }
        if (self::depthOf($parentId) + 2 > self::MAX_DEPTH) {
            return 'Забагато рівнів вкладеності (максимум ' . self::MAX_DEPTH . ').';
        }
        return null;
    }

    /** Встановлює батька (null — верхній рівень). Валідацію робить parentProblem(). Нова версія в історії не створюється. */
    public static function setParent(int $id, ?int $parentId): void
    {
        if (!self::hierarchyReady()) {
            return;
        }
        Database::connection()->prepare('UPDATE wiki_pages SET parent_id = :parent WHERE id = :id')
            ->execute(['parent' => $parentId, 'id' => $id]);
    }

    // ------------------------------------------------------------------ slug

    public static function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)
            && strlen($slug) <= 80
            && !in_array($slug, self::RESERVED_SLUGS, true);
    }

    public static function slugExists(string $slug): bool
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM wiki_pages WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Адреса з назви: українська → латиниця (за Постановою КМУ № 55, 2010; без варіантів «на початку слова»
     * — для адреси це зайве), решта символів → «-». Приклад: «Швидкий старт» → «shvydkyi-start».
     */
    public static function slugFromTitle(string $title): string
    {
        static $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ie', 'ж' => 'zh',
            'з' => 'z', 'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ь' => '', 'ю' => 'iu', 'я' => 'ia', '\'' => '', 'ʼ' => '', '’' => '',
            'ы' => 'y', 'э' => 'e', 'ъ' => '', 'ё' => 'e',
        ];
        $lower = mb_strtolower($title);
        $latin = '';
        foreach (mb_str_split($lower) as $ch) {
            $latin .= $map[$ch] ?? $ch;
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $latin), '-');
        $slug = substr($slug, 0, 80);
        $slug = rtrim($slug, '-');
        if ($slug === '' || in_array($slug, self::RESERVED_SLUGS, true)) {
            $slug = 'page' . ($slug !== '' ? '-' . $slug : '');
        }
        return $slug;
    }

    /** Вільна адреса на основі бажаної: «foo» → «foo», «foo-2», «foo-3»… */
    public static function uniqueSlug(string $base): string
    {
        $slug = $base;
        for ($n = 2; self::slugExists($slug); $n++) {
            $suffix = '-' . $n;
            $slug = substr($base, 0, 80 - strlen($suffix)) . $suffix;
        }
        return $slug;
    }

    // ------------------------------------------------------------------ запис

    /** Створює сторінку й першу версію в історії. @return int id */
    public static function create(string $slug, string $title, string $content, string $visibility, int $sortOrder, ?int $userId, ?string $source = null, ?string $importedHash = null): int
    {
        $pdo = Database::connection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO wiki_pages (slug, title, content, visibility, sort_order, version, source, imported_hash, created_by, updated_by)
                 VALUES (:slug, :title, :content, :visibility, :sort_order, 1, :source, :hash, :user, :user2)'
            );
            $stmt->execute([
                'slug' => $slug, 'title' => $title, 'content' => $content, 'visibility' => $visibility,
                'sort_order' => $sortOrder, 'source' => $source, 'hash' => $importedHash, 'user' => $userId, 'user2' => $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            self::addRevision($id, 1, $title, $content, $userId);
            if ($own) {
                $pdo->commit();
            }
            return $id;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Зберігає нову версію сторінки, якщо поточна версія в БД така, як очікує форма ($expectedVersion).
     * Перевірка й запис — одним UPDATE ... WHERE version = ? (атомарно): двоє редакторів, що збережуть
     * одночасно, не затруть один одного — другий отримає false і побачить повідомлення про конфлікт.
     *
     * @return bool false — сторінку тим часом змінили (або видалили)
     */
    public static function update(int $id, int $expectedVersion, string $title, string $content, string $visibility, int $sortOrder, ?int $userId, ?string $importedHash = null, bool $setHash = false): bool
    {
        $pdo = Database::connection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $sql = 'UPDATE wiki_pages SET title = :title, content = :content, visibility = :visibility, sort_order = :sort_order,
                           version = version + 1, updated_by = :user' . ($setHash ? ', imported_hash = :hash' : '') . '
                    WHERE id = :id AND version = :version';
            $params = ['title' => $title, 'content' => $content, 'visibility' => $visibility, 'sort_order' => $sortOrder,
                       'user' => $userId, 'id' => $id, 'version' => $expectedVersion];
            if ($setHash) {
                $params['hash'] = $importedHash;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() !== 1) {
                if ($own) {
                    $pdo->rollBack();
                }
                return false;
            }
            self::addRevision($id, $expectedVersion + 1, $title, $content, $userId);
            if ($own) {
                $pdo->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Видаляє сторінку; її підсторінки піднімаються на рівень вище (до батька видаленої), а не зникають. */
    public static function delete(int $id): void
    {
        $pdo = Database::connection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            if (self::hierarchyReady()) {
                $pdo->prepare('UPDATE wiki_pages SET parent_id = (SELECT pp FROM (SELECT parent_id AS pp FROM wiki_pages WHERE id = :id) t) WHERE parent_id = :id2')
                    ->execute(['id' => $id, 'id2' => $id]);
            }
            $pdo->prepare('DELETE FROM wiki_pages WHERE id = :id')->execute(['id' => $id]);
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------ історія

    private static function addRevision(int $pageId, int $version, string $title, string $content, ?int $userId): void
    {
        Database::connection()->prepare(
            'INSERT INTO wiki_revisions (page_id, version, title, content, edited_by) VALUES (:page, :version, :title, :content, :user)'
        )->execute(['page' => $pageId, 'version' => $version, 'title' => $title, 'content' => $content, 'user' => $userId]);
    }

    /** @return array<int, array<string, mixed>> від найновішої до найстарішої (без вмісту) */
    public static function revisions(int $pageId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.id, r.version, r.title, r.created_at, r.edited_by, u.full_name AS edited_by_name, CHAR_LENGTH(r.content) AS size_chars
             FROM wiki_revisions r LEFT JOIN users u ON u.id = r.edited_by
             WHERE r.page_id = :page ORDER BY r.version DESC'
        );
        $stmt->execute(['page' => $pageId]);
        return $stmt->fetchAll();
    }

    /** Версія саме ЦІЄЇ сторінки (id версії з чужої сторінки не підійде). */
    public static function revision(int $pageId, int $revisionId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, u.full_name AS edited_by_name FROM wiki_revisions r LEFT JOIN users u ON u.id = r.edited_by
             WHERE r.id = :id AND r.page_id = :page'
        );
        $stmt->execute(['id' => $revisionId, 'page' => $pageId]);
        return $stmt->fetch() ?: null;
    }
}
