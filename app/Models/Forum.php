<?php

namespace App\Models;

use App\Core\Database;

/**
 * Форум (таблиці forum_boards, forum_topics, forum_posts). Права визначаються ЛИШЕ тут і в контролері — шаблони й запити не
 * вигадують власних правил. Видимість розділу — ті самі три рівні, що й у вікі (WikiPage::VISIBILITIES). Тема й повідомлення
 * успадковують видимість розділу: приховані розділи не потрапляють ні в списки, ні в пошук, ні в лічильники.
 */
class Forum
{
    public const MAX_TITLE = 200;
    public const MAX_BODY = 20000;
    public const MAX_BOARD_NAME = 100;
    public const MAX_BOARD_DESCRIPTION = 500;

    // ------------------------------------------------------------------ права

    public static function visibilities(): array
    {
        return WikiPage::VISIBILITIES;
    }

    /** @return string[] */
    public static function allowedVisibilities(?string $role): array
    {
        return WikiPage::allowedVisibilities($role);
    }

    public static function boardVisibleTo(array $board, ?string $role): bool
    {
        return in_array($board['visibility'], self::allowedVisibilities($role), true);
    }

    /** Керувати розділами, закріплювати/закривати/переносити/видаляти будь-які теми й повідомлення. */
    public static function canModerate(?string $role): bool
    {
        return in_array($role, ['admin', 'it_manager'], true);
    }

    public static function tablesExist(): bool
    {
        return Database::tableExists('forum_boards') && Database::tableExists('forum_topics') && Database::tableExists('forum_posts');
    }

    // ------------------------------------------------------------------ підписки на розділи (міграція 031)

    /** Чи є таблиця підписок. Без неї (update.sh не запускали) кнопка «Підписатися» не показується, сповіщень немає. */
    public static function subscriptionsReady(): bool
    {
        return Database::tableExists('forum_board_subscriptions');
    }

    public static function isSubscribed(int $userId, int $boardId): bool
    {
        if (!self::subscriptionsReady()) {
            return false;
        }
        $stmt = Database::connection()->prepare('SELECT 1 FROM forum_board_subscriptions WHERE user_id = :u AND board_id = :b');
        $stmt->execute(['u' => $userId, 'b' => $boardId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function subscribe(int $userId, int $boardId): void
    {
        Database::connection()
            ->prepare('INSERT IGNORE INTO forum_board_subscriptions (user_id, board_id) VALUES (:u, :b)')
            ->execute(['u' => $userId, 'b' => $boardId]);
    }

    public static function unsubscribe(int $userId, int $boardId): void
    {
        Database::connection()
            ->prepare('DELETE FROM forum_board_subscriptions WHERE user_id = :u AND board_id = :b')
            ->execute(['u' => $userId, 'b' => $boardId]);
    }

    /**
     * Підписники розділу (активні, крім $exceptUserId — автора теми) з кодом ролі: роль потрібна, щоб не слати
     * тему з розділу, якого одержувач більше не бачить (розділ могли закрити для його ролі вже після підписки).
     *
     * @return array<int, array{id: int, email: string, full_name: string, role_code: string}>
     */
    public static function boardSubscribers(int $boardId, int $exceptUserId): array
    {
        if (!self::subscriptionsReady()) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.email, u.full_name, r.code AS role_code
             FROM forum_board_subscriptions s
             JOIN users u ON u.id = s.user_id
             JOIN roles r ON r.id = u.role_id
             WHERE s.board_id = :b AND u.is_active = 1 AND u.id <> :me
             ORDER BY u.id'
        );
        $stmt->execute(['b' => $boardId, 'me' => $exceptUserId]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------ вкладення (міграція 034)

    public const MAX_FILES_PER_POST = 5;

    /** Чи є таблиця вкладень. Без неї (update.sh не запускали) файли до повідомлень прикріпити не можна. */
    public static function attachmentsReady(): bool
    {
        return Database::tableExists('forum_attachments');
    }

    /** Вкладення повідомлень, згруповані за post_id. @param int[] $postIds @return array<int, array<int, array<string, mixed>>> */
    public static function attachmentsForPosts(array $postIds): array
    {
        $postIds = array_values(array_unique(array_map('intval', $postIds)));
        if (!$postIds || !self::attachmentsReady()) {
            return [];
        }
        $in = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = Database::connection()->prepare("SELECT * FROM forum_attachments WHERE post_id IN ({$in}) ORDER BY id");
        $stmt->execute($postIds);
        $out = [];
        foreach ($stmt->fetchAll() as $a) {
            $out[(int) $a['post_id']][] = $a;
        }
        return $out;
    }

    public static function attachmentCount(int $postId): int
    {
        if (!self::attachmentsReady()) {
            return 0;
        }
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM forum_attachments WHERE post_id = :p');
        $stmt->execute(['p' => $postId]);
        return (int) $stmt->fetchColumn();
    }

    /** Вкладення разом з видимістю розділу (для перевірки прав на завантаження). */
    public static function findAttachment(int $id): ?array
    {
        if (!self::attachmentsReady()) {
            return null;
        }
        $stmt = Database::connection()->prepare(
            'SELECT a.*, b.visibility AS board_visibility
             FROM forum_attachments a
             JOIN forum_posts p ON p.id = a.post_id
             JOIN forum_topics t ON t.id = p.topic_id
             JOIN forum_boards b ON b.id = t.board_id
             WHERE a.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function addAttachment(int $postId, string $name, string $storedName, string $mime, int $size, ?int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO forum_attachments (post_id, original_name, stored_name, mime_type, size_bytes, uploaded_by)
             VALUES (:p, :n, :s, :m, :z, :u)'
        )->execute(['p' => $postId, 'n' => $name, 's' => $storedName, 'm' => $mime, 'z' => $size, 'u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    /** Вкладення повідомлення за списком id (чужі id ігноруються) → імена файлів, що видалені з БД. @param int[] $ids @return array<int, array<string, mixed>> */
    public static function removeAttachments(int $postId, array $ids): array
    {
        $removed = [];
        foreach (self::attachmentsForPosts([$postId])[$postId] ?? [] as $a) {
            if (in_array((int) $a['id'], $ids, true)) {
                Database::connection()->prepare('DELETE FROM forum_attachments WHERE id = :id')->execute(['id' => $a['id']]);
                $removed[] = $a;
            }
        }
        return $removed;
    }

    /** Імена файлів вкладень повідомлення / теми / розділу — до видалення, щоб потім прибрати файли з диска. @return string[] */
    public static function storedNames(string $scope, int $id): array
    {
        if (!self::attachmentsReady()) {
            return [];
        }
        $sql = [
            'post' => 'SELECT a.stored_name FROM forum_attachments a WHERE a.post_id = :id',
            'topic' => 'SELECT a.stored_name FROM forum_attachments a JOIN forum_posts p ON p.id = a.post_id WHERE p.topic_id = :id',
            'board' => 'SELECT a.stored_name FROM forum_attachments a JOIN forum_posts p ON p.id = a.post_id JOIN forum_topics t ON t.id = p.topic_id WHERE t.board_id = :id',
        ][$scope];
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);
        return array_column($stmt->fetchAll(), 'stored_name');
    }

    // ------------------------------------------------------------------ «непрочитане» (міграція 033)

    /** Чи є таблиці відміток прочитання. Без них (update.sh не запускали) позначок «нове» просто немає. */
    public static function unreadReady(): bool
    {
        return Database::tableExists('forum_topic_reads') && Database::tableExists('forum_read_marks');
    }

    /**
     * Фрагменти SQL для «непрочитаних» тем користувача над псевдонімом теми `t`: JOIN-и і вираз (0/1).
     * Тема непрочитана, якщо останнє повідомлення новіше за пізнішу з відміток (дочитано тему / «усе прочитано»;
     * для нового користувача — дата створення облікового запису) і написане не самим користувачем.
     * Ідентифікатор вставляється як ціле, тож підстановка безпечна.
     *
     * @return array{join: string, expr: string}
     */
    private static function unreadSql(?int $userId): array
    {
        if ($userId === null || !self::unreadReady()) {
            return ['join' => '', 'expr' => '0'];
        }
        $u = (int) $userId;
        return [
            'join' => " LEFT JOIN forum_topic_reads fr ON fr.topic_id = t.id AND fr.user_id = {$u}"
                    . " LEFT JOIN forum_read_marks fm ON fm.user_id = {$u}"
                    . " LEFT JOIN users me ON me.id = {$u}",
            'expr' => "(t.last_post_at > GREATEST(COALESCE(fr.read_at, me.created_at, '1970-01-01'), COALESCE(fm.read_all_at, me.created_at, '1970-01-01'))"
                    . " AND (t.last_post_by IS NULL OR t.last_post_by <> {$u}))",
        ];
    }

    /** Позначити тему прочитаною (користувач дійшов до її останньої сторінки). */
    public static function markRead(int $topicId, int $userId): void
    {
        if (!self::unreadReady()) {
            return;
        }
        Database::connection()->prepare(
            'INSERT INTO forum_topic_reads (user_id, topic_id, read_at) VALUES (:u, :t, NOW())
             ON DUPLICATE KEY UPDATE read_at = NOW()'
        )->execute(['u' => $userId, 't' => $topicId]);
    }

    /** «Позначити все прочитаним»: усе, що було на форумі до цього моменту. Окремі відмітки тем при цьому не потрібні. */
    public static function markAllRead(int $userId): void
    {
        if (!self::unreadReady()) {
            return;
        }
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO forum_read_marks (user_id, read_all_at) VALUES (:u, NOW())
             ON DUPLICATE KEY UPDATE read_all_at = NOW()'
        )->execute(['u' => $userId]);
        $pdo->prepare('DELETE FROM forum_topic_reads WHERE user_id = :u')->execute(['u' => $userId]);
    }

    /** Кількість непрочитаних тем у розділах, видимих ролі. */
    public static function unreadTotal(?string $role, int $userId): int
    {
        $total = 0;
        foreach (self::boards($role, $userId) as $b) {
            $total += (int) ($b['unread_count'] ?? 0);
        }
        return $total;
    }

    // ------------------------------------------------------------------ розділи

    /**
     * Розділи, видимі ролі, з кількістю тем і повідомлень та останньою активністю.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function boards(?string $role, ?int $userId = null): array
    {
        $un = self::unreadSql($userId);
        $allowed = self::allowedVisibilities($role);
        $in = implode(',', array_fill(0, count($allowed), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT b.*, COUNT(t.id) AS topic_count, COALESCE(SUM(t.reply_count + 1), 0) AS post_count, MAX(t.last_post_at) AS last_activity,
                    COALESCE(SUM({$un['expr']}), 0) AS unread_count
             FROM forum_boards b
             LEFT JOIN forum_topics t ON t.board_id = b.id{$un['join']}
             WHERE b.visibility IN ({$in})
             GROUP BY b.id
             ORDER BY b.sort_order, b.name"
        );
        $stmt->execute($allowed);
        return $stmt->fetchAll();
    }

    public static function findBoard(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM forum_boards WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return int|null id нового розділу, або null, якщо така назва вже є */
    public static function createBoard(string $name, ?string $description, string $visibility, int $sortOrder, bool $locked): ?int
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO forum_boards (name, description, visibility, sort_order, is_locked) VALUES (:n, :d, :v, :s, :l)'
            )->execute(['n' => $name, 'd' => $description, 'v' => $visibility, 's' => $sortOrder, 'l' => (int) $locked]);
            return (int) Database::connection()->lastInsertId();
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $e;
        }
    }

    /** @return bool false — назва зайнята іншим розділом */
    public static function updateBoard(int $id, string $name, ?string $description, string $visibility, int $sortOrder, bool $locked): bool
    {
        try {
            Database::connection()->prepare(
                'UPDATE forum_boards SET name = :n, description = :d, visibility = :v, sort_order = :s, is_locked = :l WHERE id = :id'
            )->execute(['n' => $name, 'd' => $description, 'v' => $visibility, 's' => $sortOrder, 'l' => (int) $locked, 'id' => $id]);
            return true;
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }
    }

    /** Видаляє розділ разом з усіма темами й повідомленнями (каскад). */
    public static function deleteBoard(int $id): void
    {
        Database::connection()->prepare('DELETE FROM forum_boards WHERE id = :id')->execute(['id' => $id]);
    }

    public static function topicCount(int $boardId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM forum_topics WHERE board_id = :id');
        $stmt->execute(['id' => $boardId]);
        return (int) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------ теми

    /** Сторінка тем розділу: закріплені першими, далі за останньою активністю. @return array<int, array<string, mixed>> */
    public static function topics(int $boardId, int $page, int $perPage, ?int $userId = null): array
    {
        $un = self::unreadSql($userId);
        $stmt = Database::connection()->prepare(
            "SELECT t.*, a.full_name AS author_name, l.full_name AS last_post_by_name, {$un['expr']} AS is_unread
             FROM forum_topics t
             LEFT JOIN users a ON a.id = t.author_id
             LEFT JOIN users l ON l.id = t.last_post_by{$un['join']}
             WHERE t.board_id = :b
             ORDER BY t.is_pinned DESC, t.last_post_at DESC, t.id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) max(0, ($page - 1) * $perPage)
        );
        $stmt->execute(['b' => $boardId]);
        return $stmt->fetchAll();
    }

    /** Тема разом з розділом (для перевірки видимості). */
    public static function findTopic(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT t.*, b.name AS board_name, b.visibility AS board_visibility, b.is_locked AS board_locked, a.full_name AS author_name
             FROM forum_topics t
             JOIN forum_boards b ON b.id = t.board_id
             LEFT JOIN users a ON a.id = t.author_id
             WHERE t.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function topicVisibleTo(array $topic, ?string $role): bool
    {
        return in_array($topic['board_visibility'], self::allowedVisibilities($role), true);
    }

    /** @return int id нової теми (з першим повідомленням) */
    public static function createTopic(int $boardId, string $title, string $body, ?int $userId): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO forum_topics (board_id, title, author_id, last_post_by) VALUES (:b, :t, :u, :u2)')
                ->execute(['b' => $boardId, 't' => $title, 'u' => $userId, 'u2' => $userId]);
            $topicId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO forum_posts (topic_id, author_id, body) VALUES (:t, :u, :b)')
                ->execute(['t' => $topicId, 'u' => $userId, 'b' => $body]);
            $pdo->commit();
            return $topicId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function setPinned(int $topicId, bool $pinned): void
    {
        Database::connection()->prepare('UPDATE forum_topics SET is_pinned = :v WHERE id = :id')->execute(['v' => (int) $pinned, 'id' => $topicId]);
    }

    public static function setLocked(int $topicId, bool $locked): void
    {
        Database::connection()->prepare('UPDATE forum_topics SET is_locked = :v WHERE id = :id')->execute(['v' => (int) $locked, 'id' => $topicId]);
    }

    public static function moveTopic(int $topicId, int $boardId): void
    {
        Database::connection()->prepare('UPDATE forum_topics SET board_id = :b WHERE id = :id')->execute(['b' => $boardId, 'id' => $topicId]);
    }

    public static function deleteTopic(int $topicId): void
    {
        Database::connection()->prepare('DELETE FROM forum_topics WHERE id = :id')->execute(['id' => $topicId]);
    }

    public static function updateTitle(int $topicId, string $title): void
    {
        Database::connection()->prepare('UPDATE forum_topics SET title = :t WHERE id = :id')->execute(['t' => $title, 'id' => $topicId]);
    }

    /** Розділи, у які модератор може перенести тему (видимі йому, крім поточного). @return array<int, array<string, mixed>> */
    public static function moveTargets(?string $role, int $exceptBoardId): array
    {
        return array_values(array_filter(self::boards($role), static fn(array $b): bool => (int) $b['id'] !== $exceptBoardId));
    }

    // ------------------------------------------------------------------ повідомлення

    /** @return array<int, array<string, mixed>> */
    public static function posts(int $topicId, int $page, int $perPage): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.*, u.full_name AS author_name, r.code AS author_role, e.full_name AS edited_by_name
             FROM forum_posts p
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN roles r ON r.id = u.role_id
             LEFT JOIN users e ON e.id = p.edited_by
             WHERE p.topic_id = :t
             ORDER BY p.id ASC
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) max(0, ($page - 1) * $perPage)
        );
        $stmt->execute(['t' => $topicId]);
        return $stmt->fetchAll();
    }

    /** Повідомлення разом з темою й видимістю розділу. */
    public static function findPost(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.*, t.title AS topic_title, t.board_id, t.is_locked AS topic_locked, t.author_id AS topic_author_id,
                    b.visibility AS board_visibility
             FROM forum_posts p
             JOIN forum_topics t ON t.id = p.topic_id
             JOIN forum_boards b ON b.id = t.board_id
             WHERE p.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function firstPostId(int $topicId): int
    {
        $stmt = Database::connection()->prepare('SELECT MIN(id) FROM forum_posts WHERE topic_id = :t');
        $stmt->execute(['t' => $topicId]);
        return (int) $stmt->fetchColumn();
    }

    /** На якій за номером сторінці теми лежить повідомлення (для постійних посилань). */
    public static function pageOfPost(int $topicId, int $postId, int $perPage): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM forum_posts WHERE topic_id = :t AND id <= :p');
        $stmt->execute(['t' => $topicId, 'p' => $postId]);
        return max(1, (int) ceil(((int) $stmt->fetchColumn()) / $perPage));
    }

    /** @return int id нового повідомлення */
    public static function addPost(int $topicId, string $body, ?int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO forum_posts (topic_id, author_id, body) VALUES (:t, :u, :b)')
            ->execute(['t' => $topicId, 'u' => $userId, 'b' => $body]);
        $id = (int) $pdo->lastInsertId();
        self::refreshTopic($topicId);
        return $id;
    }

    public static function updatePost(int $id, string $body, ?int $editorId): void
    {
        Database::connection()->prepare('UPDATE forum_posts SET body = :b, edited_at = NOW(), edited_by = :e WHERE id = :id')
            ->execute(['b' => $body, 'e' => $editorId, 'id' => $id]);
    }

    public static function deletePost(int $id, int $topicId): void
    {
        Database::connection()->prepare('DELETE FROM forum_posts WHERE id = :id')->execute(['id' => $id]);
        self::refreshTopic($topicId);
    }

    public static function postCount(int $topicId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM forum_posts WHERE topic_id = :t');
        $stmt->execute(['t' => $topicId]);
        return (int) $stmt->fetchColumn();
    }

    /** Перераховує похідні поля теми (кількість відповідей, час і автор останнього повідомлення). */
    public static function refreshTopic(int $topicId): void
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'UPDATE forum_topics t
             JOIN (SELECT topic_id, COUNT(*) AS c, MAX(id) AS last_id FROM forum_posts WHERE topic_id = :t GROUP BY topic_id) s ON s.topic_id = t.id
             JOIN forum_posts lp ON lp.id = s.last_id
             SET t.reply_count = GREATEST(s.c - 1, 0), t.last_post_at = lp.created_at, t.last_post_by = lp.author_id
             WHERE t.id = :t2'
        )->execute(['t' => $topicId, 't2' => $topicId]);
    }

    /**
     * Учасники теми (автори її повідомлень) з кодом ролі — для сповіщень про відповіді. Лише активні користувачі,
     * крім $exceptUserId (того, хто відповів). Роль потрібна, щоб не слати текст з розділу, якого одержувач не бачить.
     *
     * @return array<int, array{id: int, email: string, full_name: string, role_code: string}>
     */
    public static function participants(int $topicId, int $exceptUserId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT u.id, u.email, u.full_name, r.code AS role_code
             FROM forum_posts p
             JOIN users u ON u.id = p.author_id
             JOIN roles r ON r.id = u.role_id
             WHERE p.topic_id = :t AND u.is_active = 1 AND u.id <> :me
             ORDER BY u.id'
        );
        $stmt->execute(['t' => $topicId, 'me' => $exceptUserId]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------ пошук

    /**
     * Теми, у назві або будь-якому повідомленні яких є запит (лише з розділів, видимих ролі).
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function search(?string $role, string $query, int $page, int $perPage, ?int $userId = null): array
    {
        $un = self::unreadSql($userId);
        $allowed = self::allowedVisibilities($role);
        $in = implode(',', array_fill(0, count($allowed), '?'));
        $like = '%' . addcslashes($query, '\\%_') . '%';
        $where = "b.visibility IN ({$in}) AND (t.title LIKE ? OR EXISTS (SELECT 1 FROM forum_posts p WHERE p.topic_id = t.id AND p.body LIKE ?))";
        $params = array_merge($allowed, [$like, $like]);
        $pdo = Database::connection();

        $count = $pdo->prepare("SELECT COUNT(*) FROM forum_topics t JOIN forum_boards b ON b.id = t.board_id WHERE {$where}");
        $count->execute($params);

        $stmt = $pdo->prepare(
            "SELECT t.*, b.name AS board_name, a.full_name AS author_name, l.full_name AS last_post_by_name, {$un['expr']} AS is_unread
             FROM forum_topics t
             JOIN forum_boards b ON b.id = t.board_id
             LEFT JOIN users a ON a.id = t.author_id
             LEFT JOIN users l ON l.id = t.last_post_by{$un['join']}
             WHERE {$where}
             ORDER BY t.last_post_at DESC, t.id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) max(0, ($page - 1) * $perPage)
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /** Найновіші теми з усіх видимих розділів — для головної сторінки форуму. @return array<int, array<string, mixed>> */
    public static function recentTopics(?string $role, int $limit, ?int $userId = null): array
    {
        $un = self::unreadSql($userId);
        $allowed = self::allowedVisibilities($role);
        $in = implode(',', array_fill(0, count($allowed), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT t.id, t.title, t.reply_count, t.last_post_at, b.name AS board_name, l.full_name AS last_post_by_name, {$un['expr']} AS is_unread
             FROM forum_topics t JOIN forum_boards b ON b.id = t.board_id
             LEFT JOIN users l ON l.id = t.last_post_by{$un['join']}
             WHERE b.visibility IN ({$in})
             ORDER BY t.last_post_at DESC, t.id DESC LIMIT " . (int) $limit
        );
        $stmt->execute($allowed);
        return $stmt->fetchAll();
    }
}
