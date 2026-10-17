<?php

namespace App\Services;

use App\Core\Access;
use App\Core\Database;
use App\Core\Markdown;
use App\Models\Forum;
use App\Models\LibraryDocument;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\WikiPage;

/**
 * Глобальний пошук: проєкти, задачі (з коментарями), тікети (з коментарями), вікі, документи, форум.
 *
 * Права НЕ дублюються й не послаблюються: кожен розділ користується тим самим механізмом видимості, що й власна сторінка
 * (Project::accessCondition для проєктів і задач, Ticket::visibilityCondition для тікетів, вікі / бібліотека / форум — власні методи
 * пошуку з урахуванням ролі). Невидиме користувачу не потрапляє ні в результати, ні в лічильники.
 */
class SearchService
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 100;
    public const PER_SECTION = 10;

    /**
     * @return array<int, array{key: string, title: string, total: int, more: bool, more_url: ?string, items: array<int, array{url: string, title: string, meta: string, snippet: string}>}>
     */
    public static function run(string $query, int $userId, ?string $role): array
    {
        $sections = [];
        foreach (['projects' => 'Проєкти', 'tasks' => 'Задачі', 'tickets' => 'Тікети', 'wiki' => 'Вікі', 'library' => 'Документи', 'forum' => 'Форум'] as $key => $title) {
            try {
                $section = self::$key($query, $userId, $role);
            } catch (\Throwable) {
                continue; // таблиці ще немає (не виконано update.sh) чи збій розділу — решта пошуку працює
            }
            $sections[] = ['key' => $key, 'title' => $title] + $section;
        }
        return $sections;
    }

    // ---------- розділи ----------

    private static function projects(string $q, int $userId, ?string $role): array
    {
        $like = self::like($q);
        $where = '(p.name LIKE :l1 OR p.description LIKE :l2' . (($id = self::idOf($q)) ? ' OR p.id = :pid' : '') . ')';
        $params = ['l1' => $like, 'l2' => $like] + ($id ? ['pid' => $id] : []);
        if (!Access::isAdmin($role)) {
            [$acc, $accParams] = Project::accessCondition('p', 'sa', $userId);
            $where .= ' AND ' . $acc;
            $params += $accParams;
        }
        $total = self::count("SELECT COUNT(*) FROM projects p WHERE {$where}", $params);
        $stmt = Database::connection()->prepare(
            "SELECT p.id, p.name, p.status, p.description, ru.full_name AS responsible
             FROM projects p LEFT JOIN users ru ON ru.id = p.responsible_user_id
             WHERE {$where}
             ORDER BY (p.name LIKE :ord) DESC, p.updated_at DESC LIMIT " . self::PER_SECTION
        );
        $stmt->execute($params + ['ord' => $like]);
        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $items[] = [
                'url' => '/projects/' . (int) $r['id'],
                'title' => self::mark((string) $r['name'], $q),
                'meta' => 'Проєкт #' . (int) $r['id'] . ($r['status'] !== 'active' ? ' · ' . self::projectStatus((string) $r['status']) : '') . ($r['responsible'] ? ' · відповідальний: ' . $r['responsible'] : ''),
                'snippet' => stripos((string) $r['name'], $q) === false ? self::snippet((string) $r['description'], $q) : '',
            ];
        }
        return self::result($total, $items, null);
    }

    private static function tasks(string $q, int $userId, ?string $role): array
    {
        $like = self::like($q);
        $where = '(t.title LIKE :l1 OR t.description LIKE :l2 OR EXISTS (SELECT 1 FROM comments c WHERE c.task_id = t.id AND c.body LIKE :l3)'
            . (($id = self::idOf($q)) ? ' OR t.id = :tid' : '') . ')';
        $params = ['l1' => $like, 'l2' => $like, 'l3' => $like] + ($id ? ['tid' => $id] : []);
        if (!Access::isAdmin($role)) {
            [$acc, $accParams] = Project::accessCondition('p', 'sa', $userId);
            $where .= ' AND ' . $acc;
            $params += $accParams;
        }
        $from = 'FROM tasks t JOIN projects p ON p.id = t.project_id JOIN task_statuses ts ON ts.id = t.status_id';
        $total = self::count("SELECT COUNT(*) {$from} WHERE {$where}", $params);
        $stmt = Database::connection()->prepare(
            "SELECT t.id, t.title, t.description, p.name AS project_name, ts.name AS status_name, ts.is_closed,
                    (SELECT c2.body FROM comments c2 WHERE c2.task_id = t.id AND c2.body LIKE :lc ORDER BY c2.id DESC LIMIT 1) AS comment_hit
             {$from} WHERE {$where}
             ORDER BY (t.title LIKE :ord) DESC, ts.is_closed ASC, t.updated_at DESC LIMIT " . self::PER_SECTION
        );
        $stmt->execute($params + ['ord' => $like, 'lc' => $like]);
        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $snippet = '';
            if (stripos((string) $r['title'], $q) === false) {
                $snippet = stripos((string) $r['description'], $q) !== false
                    ? self::snippet((string) $r['description'], $q)
                    : ($r['comment_hit'] !== null ? 'у коментарі: ' . self::snippet((string) $r['comment_hit'], $q) : '');
            }
            $items[] = [
                'url' => '/tasks/' . (int) $r['id'],
                'title' => self::mark((string) $r['title'], $q),
                'meta' => 'Задача #' . (int) $r['id'] . ' · ' . $r['project_name'] . ' · ' . $r['status_name'] . ((int) $r['is_closed'] ? ' (закрита)' : ''),
                'snippet' => $snippet,
            ];
        }
        return self::result($total, $items, null);
    }

    private static function tickets(string $q, int $userId, ?string $role): array
    {
        $like = self::like($q);
        $where = '(t.subject LIKE :l1 OR t.description LIKE :l2 OR t.requester_name LIKE :l3 OR EXISTS (SELECT 1 FROM ticket_comments c WHERE c.ticket_id = t.id AND c.body LIKE :l4)'
            . (($id = self::idOf($q)) ? ' OR t.id = :tid' : '') . ')';
        $params = ['l1' => $like, 'l2' => $like, 'l3' => $like, 'l4' => $like] + ($id ? ['tid' => $id] : []);
        if (!Access::isAdmin($role)) {
            [$vis, $visParams] = Ticket::visibilityCondition('t', $userId, Access::canSeeUnassignedTickets($role));
            $where .= ' AND ' . $vis;
            $params += $visParams;
        }
        $from = 'FROM tickets t JOIN ticket_queues qu ON qu.id = t.queue_id';
        $total = self::count("SELECT COUNT(*) {$from} WHERE {$where}", $params);
        $stmt = Database::connection()->prepare(
            "SELECT t.id, t.subject, t.description, t.status, t.requester_name, qu.name AS queue_name,
                    (SELECT c2.body FROM ticket_comments c2 WHERE c2.ticket_id = t.id AND c2.body LIKE :lc ORDER BY c2.id DESC LIMIT 1) AS comment_hit
             {$from} WHERE {$where}
             ORDER BY (t.subject LIKE :ord) DESC, (t.status = 'closed') ASC, t.created_at DESC LIMIT " . self::PER_SECTION
        );
        $stmt->execute($params + ['ord' => $like, 'lc' => $like]);
        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $snippet = '';
            if (stripos((string) $r['subject'], $q) === false) {
                $snippet = stripos((string) $r['description'], $q) !== false
                    ? self::snippet((string) $r['description'], $q)
                    : ($r['comment_hit'] !== null ? 'у коментарі: ' . self::snippet((string) $r['comment_hit'], $q) : '');
            }
            $items[] = [
                'url' => '/tickets/' . (int) $r['id'],
                'title' => self::mark((string) $r['subject'], $q),
                'meta' => 'Тікет #' . (int) $r['id'] . ' · ' . $r['queue_name'] . ' · ' . \App\Models\Ticket::statusLabel((string) $r['status']) . ' · заявник: ' . $r['requester_name'],
                'snippet' => $snippet,
            ];
        }
        return self::result($total, $items, null);
    }

    private static function wiki(string $q, int $userId, ?string $role): array
    {
        $rows = WikiPage::search($q, $role, self::PER_SECTION + 1);
        $items = [];
        foreach (array_slice($rows, 0, self::PER_SECTION) as $r) {
            $items[] = [
                'url' => '/wiki/' . rawurlencode((string) $r['slug']),
                'title' => self::mark((string) $r['title'], $q),
                'meta' => 'Вікі',
                'snippet' => !empty($r['in_title']) ? '' : self::snippet((string) ($r['content'] ?? ''), $q),
            ];
        }
        return self::result(count($rows), $items, '/wiki?q=' . rawurlencode($q), count($rows) > self::PER_SECTION);
    }

    private static function library(string $q, int $userId, ?string $role): array
    {
        $res = LibraryDocument::search($role, $userId, null, $q, 1, self::PER_SECTION, '');
        $items = [];
        foreach ($res['rows'] as $r) {
            $items[] = [
                'url' => '/library/' . (int) $r['id'],
                'title' => self::mark((string) $r['title'], $q),
                'meta' => 'Документ' . ($r['category_name'] ? ' · ' . $r['category_name'] : '') . ($r['original_name'] ? ' · ' . $r['original_name'] : ''),
                'snippet' => stripos((string) $r['title'], $q) === false ? self::snippet((string) ($r['description'] ?? ''), $q) : '',
            ];
        }
        return self::result((int) $res['total'], $items, '/library?q=' . rawurlencode($q));
    }

    private static function forum(string $q, int $userId, ?string $role): array
    {
        $res = Forum::search($role, $q, 1, self::PER_SECTION, $userId);
        $items = [];
        foreach ($res['rows'] as $r) {
            $items[] = [
                'url' => '/forum/topics/' . (int) $r['id'],
                'title' => self::mark((string) $r['title'], $q),
                'meta' => 'Форум · ' . $r['board_name'] . ' · відповідей: ' . (int) $r['reply_count'],
                'snippet' => '',
            ];
        }
        return self::result((int) $res['total'], $items, '/forum/search?q=' . rawurlencode($q));
    }

    // ---------- допоміжне ----------

    private static function result(int $total, array $items, ?string $moreUrl, ?bool $more = null): array
    {
        return ['total' => $total, 'more' => $more ?? $total > count($items), 'more_url' => $moreUrl, 'items' => $items];
    }

    private static function count(string $sql, array $params): int
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Шаблон LIKE з екрануванням % _ \ — запит «50%» шукає саме «50%». */
    private static function like(string $q): string
    {
        return '%' . addcslashes($q, '\\%_') . '%';
    }

    /** «#42» чи «42» — ще й пошук за номером. */
    private static function idOf(string $q): int
    {
        return preg_match('/^#?(\d{1,9})$/', $q, $m) ? (int) $m[1] : 0;
    }

    private static function projectStatus(string $s): string
    {
        return ['archived' => 'в архіві', 'closed' => 'закритий'][$s] ?? $s;
    }

    /** HTML із виділеним збігом; кожен шматок екранується окремо. */
    private static function mark(string $text, string $q): string
    {
        $pos = $q === '' ? false : mb_stripos($text, $q);
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        if ($pos === false) {
            return $esc($text);
        }
        return $esc(mb_substr($text, 0, $pos)) . '<mark>' . $esc(mb_substr($text, $pos, mb_strlen($q))) . '</mark>' . $esc(mb_substr($text, $pos + mb_strlen($q)));
    }

    /** Фрагмент навколо першого збігу (готовий безпечний HTML); порожній, якщо збігу в тексті немає. */
    private static function snippet(string $text, string $q): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', Markdown::plainText($text)) ?? '');
        $pos = mb_stripos($plain, $q);
        if ($pos === false) {
            return '';
        }
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $start = max(0, $pos - 60);
        return ($start > 0 ? '…' : '') . $esc(mb_substr($plain, $start, $pos - $start))
            . '<mark>' . $esc(mb_substr($plain, $pos, mb_strlen($q))) . '</mark>'
            . $esc(mb_substr($plain, $pos + mb_strlen($q), 80))
            . (mb_strlen($plain) > $pos + mb_strlen($q) + 80 ? '…' : '');
    }
}
