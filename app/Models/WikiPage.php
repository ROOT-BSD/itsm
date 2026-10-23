<?php

namespace App\Models;

use App\Core\Database;
use App\Services\WikiStore;

/**
 * Сторінки вікі (таблиці wiki_pages, wiki_revisions). Права визначаються ЛИШЕ тут і в контролері через
 * visibleTo()/canEdit()/canDelete() — шаблони й запити не вигадують власних правил.
 */
class WikiPage
{
    public const VISIBILITIES = ['all' => 'Усі, хто увійшов', 'staff' => 'Персонал (без заявників)', 'admin' => 'Лише адміністратор'];
    /** Лише для вікі: «публічна» сторінка («База знань») видима всім, у т.ч. анонімно через портал /support/kb. */
    public const PORTAL = 'portal';
    public const MAX_CONTENT_CHARS = 300000;
    /** Максимальна глибина вкладеності (кореневі сторінки — рівень 1). */
    public const MAX_DEPTH = 6;
    /** Адреси, що збігаються з маршрутами вікі, — для сторінок заборонені. */
    /** Скільки останніх версій сторінки зберігається в історії (разом з поточною). */
    public const MAX_REVISIONS = 5;

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

    /** Публічні сторінки («База знань») для порталу: slug і назва. @return array<int, array{slug: string, title: string}> */
    public static function publicList(): array
    {
        try {
            $stmt = Database::connection()->prepare('SELECT slug, title FROM wiki_pages WHERE visibility = ? ORDER BY sort_order ASC, title ASC LIMIT 500');
            $stmt->execute([self::PORTAL]);
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Значення visibility вікі: загальні (як у форумі/бібліотеці) + публічна сторінка порталу. */
    public static function visibilities(): array
    {
        return self::VISIBILITIES + [self::PORTAL => 'Публічна — «База знань» (видима на порталі без входу)'];
    }

    /** Які значення visibility вікі бачить роль; null (анонім) — лише публічні сторінки. @return string[] */
    public static function wikiVisibilities(?string $role): array
    {
        return $role === null ? [self::PORTAL] : array_merge(self::allowedVisibilities($role), [self::PORTAL]);
    }

    public static function visibleTo(array $page, ?string $role): bool
    {
        return in_array($page['visibility'], self::wikiVisibilities($role), true);
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
        return self::withContent($stmt->fetch() ?: null);
    }

    /** Підставляє текст з диска (якщо сторінку вже перенесено), інакше лишає той, що в БД. */
    private static function withContent(?array $page): ?array
    {
        if ($page !== null) {
            $disk = WikiStore::readCurrent((int) $page['id']);
            if ($disk !== null) {
                $page['content'] = $disk;
            }
        }
        return $page;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM wiki_pages WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return self::withContent($stmt->fetch() ?: null);
    }

    /** Список сторінок, видимих ролі (без вмісту — він може бути великим). @return array<int, array<string, mixed>> */
    public static function listVisible(?string $role): array
    {
        $allowed = self::wikiVisibilities($role);
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
        $allowed = self::wikiVisibilities($role);
        $in = implode(',', array_fill(0, count($allowed), '?'));
        // Текст лежить на диску, тож LIKE по БД неможливий: беремо видимі сторінки й шукаємо в PHP (без урахування регістру).
        $stmt = Database::connection()->prepare(
            "SELECT p.id, p.slug, p.title, p.content, p.updated_at
             FROM wiki_pages p WHERE p.visibility IN ($in)
             ORDER BY p.sort_order ASC, p.title ASC"
        );
        $stmt->execute($allowed);
        $inTitle = [];
        $inText = [];
        foreach ($stmt->fetchAll() as $row) {
            $titleHit = mb_stripos((string) $row['title'], $query) !== false;
            $row = self::withContent($row);
            $textHit = !$titleHit && mb_stripos((string) $row['content'], $query) !== false;
            if ($titleHit) {
                $row['in_title'] = 1;
                $inTitle[] = $row;
            } elseif ($textHit) {
                $row['in_title'] = 0;
                $inText[] = $row;
            }
            if (count($inTitle) + count($inText) >= $limit * 3) {
                break;
            }
        }
        return array_slice(array_merge($inTitle, $inText), 0, $limit);
    }

    /**
     * Статті, схожі на текст звернення (тема + опис): «База знань». $role = null — лише публічні сторінки (портал).
     * Слова ≥ 3 літер (крім службових) зводяться до основи (відкидається закінчення), збіг у назві важить більше, ніж у тексті.
     *
     * @return array<int, array{slug: string, title: string, excerpt: string, score: int}>
     */
    public static function suggest(string $text, ?string $role, int $limit = 5): array
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $len = mb_strlen($w);
            // Слова з 3 літер (VPN, USB, RDP) теж важливі, але службові («для», «що», «при»…) пропускаємо.
            if ($len >= 4 || ($len === 3 && !in_array($w, ['для', 'що', 'як', 'або', 'але', 'чим', 'при', 'над', 'під', 'про', 'між', 'без', 'бо', 'чи', 'нам', 'вам', 'мене', 'цей', 'ця', 'це', 'той', 'вже', 'ще', 'так', 'ні', 'мій', 'моя'], true))) {
                $words[mb_substr($w, 0, $len >= 7 ? $len - 2 : ($len >= 5 ? $len - 1 : $len))] = true;
            }
        }
        $words = array_slice(array_keys($words), 0, 12);
        if (!$words) {
            return [];
        }
        $allowed = self::wikiVisibilities($role);
        $in = implode(',', array_fill(0, count($allowed), '?'));
        $stmt = Database::connection()->prepare("SELECT p.id, p.slug, p.title FROM wiki_pages p WHERE p.visibility IN ($in) ORDER BY p.sort_order ASC, p.title ASC LIMIT 400");
        $stmt->execute($allowed);
        $found = [];
        foreach ($stmt->fetchAll() as $row) {
            $title = mb_strtolower((string) $row['title']);
            $content = (string) (WikiStore::readCurrent((int) $row['id']) ?? '');
            $body = mb_strtolower(mb_substr($content, 0, 20000));
            $score = 0;
            foreach ($words as $w) {
                if (mb_strpos($title, $w) !== false) {
                    $score += 5;
                }
                if (mb_strpos($body, $w) !== false) {
                    $score += 1;
                }
            }
            if ($score > 0) {
                $plain = trim((string) preg_replace('/\s+/u', ' ', \App\Core\Markdown::plainText($content)));
                $found[] = ['slug' => $row['slug'], 'title' => $row['title'], 'excerpt' => mb_strlen($plain) > 140 ? mb_substr($plain, 0, 140) . '…' : $plain, 'score' => $score];
            }
        }
        usort($found, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($found, 0, $limit);
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
                 VALUES (:slug, :title, \'\', :visibility, :sort_order, 1, :source, :hash, :user, :user2)'
            );
            $stmt->execute([
                'slug' => $slug, 'title' => $title, 'visibility' => $visibility,
                'sort_order' => $sortOrder, 'source' => $source, 'hash' => $importedHash, 'user' => $userId, 'user2' => $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            self::addRevision($id, 1, $title, $content, $userId);
            WikiStore::write($id, 1, $content);
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
            $sql = 'UPDATE wiki_pages SET title = :title, content = \'\', visibility = :visibility, sort_order = :sort_order,
                           version = version + 1, updated_by = :user' . ($setHash ? ', imported_hash = :hash' : '') . '
                    WHERE id = :id AND version = :version';
            $params = ['title' => $title, 'visibility' => $visibility, 'sort_order' => $sortOrder,
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
            WikiStore::write($id, $expectedVersion + 1, $content);
            if ($own) {
                $pdo->commit();
            }
            self::pruneRevisions($id); // після коміту: файли видаляються лише для вже збереженої версії
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
            WikiStore::deletePage($id);
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
            'INSERT INTO wiki_revisions (page_id, version, title, content, edited_by) VALUES (:page, :version, :title, \'\', :user)'
        )->execute(['page' => $pageId, 'version' => $version, 'title' => $title, 'user' => $userId]);
    }

    /**
     * Лишає в історії сторінки лише MAX_REVISIONS найновіших версій (разом з поточною): старіші записи
     * і їхні файли rev-N.md видаляються. Повертає кількість видалених версій.
     */
    public static function pruneRevisions(int $pageId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, version FROM wiki_revisions WHERE page_id = :page ORDER BY version DESC LIMIT 1000000 OFFSET ' . self::MAX_REVISIONS
        );
        $stmt->execute(['page' => $pageId]);
        $old = $stmt->fetchAll();
        foreach ($old as $row) {
            $pdo->prepare('DELETE FROM wiki_revisions WHERE id = :id')->execute(['id' => (int) $row['id']]);
            WikiStore::deleteRevision($pageId, (int) $row['version']);
        }
        return count($old);
    }

    /** @return array<int, array<string, mixed>> від найновішої до найстарішої (без вмісту) */
    public static function revisions(int $pageId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.id, r.version, r.title, r.created_at, r.edited_by, u.full_name AS edited_by_name, r.content AS db_content
             FROM wiki_revisions r LEFT JOIN users u ON u.id = r.edited_by
             WHERE r.page_id = :page ORDER BY r.version DESC'
        );
        $stmt->execute(['page' => $pageId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $text = WikiStore::readRevision($pageId, (int) $row['version']) ?? (string) $row['db_content'];
            $row['size_chars'] = mb_strlen($text);
            unset($row['db_content']);
        }
        return $rows;
    }

    /** Версія саме ЦІЄЇ сторінки (id версії з чужої сторінки не підійде). */
    public static function revision(int $pageId, int $revisionId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, u.full_name AS edited_by_name FROM wiki_revisions r LEFT JOIN users u ON u.id = r.edited_by
             WHERE r.id = :id AND r.page_id = :page'
        );
        $stmt->execute(['id' => $revisionId, 'page' => $pageId]);
        $row = $stmt->fetch() ?: null;
        if ($row !== null) {
            $disk = WikiStore::readRevision($pageId, (int) $row['version']);
            if ($disk !== null) {
                $row['content'] = $disk;
            }
        }
        return $row;
    }
}
