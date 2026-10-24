<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Unit;
use App\Models\Audit;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketRule;

/**
 * Автоматичні правила черги тікетів. Правило має ПОДІЮ-тригер: created (створення тікета: форма, портал, пошта, API — викликається
 * після збереження вкладень), status (зміна статусу, опційно — на конкретний) або comment (новий коментар; від заявника, працівника чи будь-кого).
 *
 * Для кожної події правила перевіряються за порядком (sort_order, id); перше, що збіглося, виконується, далі перевірка зупиняється.
 * Умови (усі задані — одночасно): ключові слова (в темі/описі/тексті коментаря; достатньо одного, без урахування регістру), поточна черга,
 * заявник (адреса чи домен), джерело тікета (форма, портал, пошта, API), вкладення (є/немає, кількість, загальний розмір).
 * Правило адміністратора підрозділу діє лише на тікети заявників цього підрозділу.
 * Дії: перенести в чергу, призначити оператора, встановити пріоритет, надіслати сповіщення (листом на адреси/оператору та/або
 * «в системі» — у центр сповіщень оператору й вибраним працівникам).
 */
class TicketRuleService
{
    /** Підсумок останнього apply(): для журналу пошти (чому правило спрацювало чи ні). */
    public static string $lastNote = '';

    /** @return string[] ключові слова з тексту (через кому, крапку з комою чи новий рядок), без порожніх і дублів */
    public static function parseKeywords(string $text): array
    {
        $out = [];
        foreach (preg_split('/[,;\r\n]+/u', $text) ?: [] as $k) {
            $k = trim($k);
            if ($k !== '') {
                $out[mb_strtolower($k)] = $k;
            }
        }
        return array_values($out);
    }

    public const SOURCES = ['web' => 'форма в системі', 'portal' => 'портал', 'email' => 'пошта', 'api' => 'API'];
    public const EVENTS = ['created' => 'створення тікета', 'status' => 'зміна статусу', 'comment' => 'новий коментар'];
    public const COMMENT_BY = ['any' => 'будь-хто', 'requester' => 'заявник', 'staff' => 'працівник підтримки'];
    public const ATT_MODES = ['any' => 'не важливо', 'with' => 'є вкладення', 'without' => 'немає вкладень'];

    /** @return string[] адреси/домени заявника з тексту умови (через кому, пробіл, крапку з комою), нижній регістр */
    public static function parseRequesterMatch(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $k) {
            $out[mb_strtolower($k)] = mb_strtolower($k);
        }
        return array_values($out);
    }

    /** Чи підходить адреса заявника під умову: «user@x.ua» — точна адреса; «@x.ua» чи «x.ua» — домен (і його піддомени). */
    public static function requesterMatches(string $condition, string $email): bool
    {
        $tokens = self::parseRequesterMatch($condition);
        if (!$tokens) {
            return true;
        }
        $email = mb_strtolower(trim($email));
        $domain = str_contains($email, '@') ? substr($email, (int) strrpos($email, '@') + 1) : '';
        foreach ($tokens as $t) {
            if (str_contains($t, '@') && !str_starts_with($t, '@')) {
                if ($t === $email) {
                    return true;
                }
                continue;
            }
            $d = ltrim($t, '@');
            if ($domain !== '' && ($domain === $d || str_ends_with($domain, '.' . $d))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Чи збігається правило з подією (без перевірки підрозділу). Усі задані умови мають виконуватися одночасно (І);
     * ключові слова — достатньо одного зі списку.
     * @param array{subject: string, description: ?string, queue_id: int, requester_email?: string, source?: ?string, event?: string, status?: ?string,
     *              comment_by?: ?string, comment?: ?string, att_count?: int, att_bytes?: int} $f
     */
    public static function matches(array $rule, array $f): bool
    {
        $event = $f['event'] ?? 'created';
        if (($rule['trigger_event'] ?? 'created') !== $event) {
            return false;
        }
        if ($event === 'status' && !empty($rule['when_status']) && ($f['status'] ?? null) !== $rule['when_status']) {
            return false;
        }
        if ($event === 'comment' && ($rule['comment_by'] ?? 'any') !== 'any' && ($f['comment_by'] ?? null) !== $rule['comment_by']) {
            return false;
        }
        if (!empty($rule['from_queue_id']) && (int) $rule['from_queue_id'] !== (int) $f['queue_id']) {
            return false;
        }
        if (!empty($rule['source']) && ($f['source'] ?? null) !== $rule['source']) {
            return false;
        }
        if (!empty($rule['requester_match']) && !self::requesterMatches((string) $rule['requester_match'], (string) ($f['requester_email'] ?? ''))) {
            return false;
        }
        // Призначений оператор (на момент події).
        $opId = (int) ($f['operator_id'] ?? 0);
        $copm = $rule['cond_operator'] ?? 'any';
        if (($copm === 'assigned' && $opId === 0) || ($copm === 'unassigned' && $opId !== 0)) {
            return false;
        }
        if ($copm === 'assigned' && !empty($rule['cond_operator_id']) && $opId !== (int) $rule['cond_operator_id']) {
            return false;
        }
        // Вкладення тікета: наявність, кількість, загальний розмір.
        $cnt = (int) ($f['att_count'] ?? 0);
        $bytes = (int) ($f['att_bytes'] ?? 0);
        $mode = $rule['att_mode'] ?? 'any';
        if (($mode === 'with' && $cnt === 0) || ($mode === 'without' && $cnt > 0)) {
            return false;
        }
        if (!empty($rule['att_min_count']) && $cnt < (int) $rule['att_min_count']) {
            return false;
        }
        if ($rule['att_min_mb'] !== null && $rule['att_min_mb'] !== '' && (float) $rule['att_min_mb'] > 0 && $bytes < (int) round((float) $rule['att_min_mb'] * 1048576)) {
            return false;
        }
        if ($rule['att_max_mb'] !== null && $rule['att_max_mb'] !== '' && (float) $rule['att_max_mb'] > 0 && $bytes > (int) round((float) $rule['att_max_mb'] * 1048576)) {
            return false;
        }
        $keywords = self::parseKeywords((string) $rule['keywords']);
        if (!$keywords) {
            return true;
        }
        $comment = (string) ($f['comment'] ?? '');
        $haystack = match ($rule['match_in']) {
            'subject' => $f['subject'],
            'description' => (string) $f['description'],
            'comment' => $comment,
            default => $f['subject'] . "\n" . (string) $f['description'] . ($event === 'comment' ? "\n" . $comment : ''),
        };
        // Листи часто містять нерозривні пробіли, м'які переноси та невидимі символи — з ними слово не знаходилось.
        $haystack = (string) preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{FEFF}]/u', '', str_replace("\u{00A0}", ' ', $haystack));
        $haystack = (string) preg_replace('/\s+/u', ' ', $haystack);
        foreach ($keywords as $kw) {
            if (mb_stripos($haystack, (string) preg_replace('/\s+/u', ' ', $kw)) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Кількість і загальний розмір (байти) вкладень тікета. @return array{0: int, 1: int} */
    public static function attachmentStats(int $ticketId): array
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*), COALESCE(SUM(size_bytes), 0) FROM attachments WHERE ticket_id = :id');
        $stmt->execute(['id' => $ticketId]);
        $r = $stmt->fetch(\PDO::FETCH_NUM) ?: [0, 0];
        return [(int) $r[0], (int) $r[1]];
    }

    /**
     * Знаходить правило, яке спрацює для тікета (нічого не змінює). Спільна логіка для apply() і «перевірки на прикладі».
     * @param array{subject: string, description: ?string, queue_id: int, requester_email?: string, source?: ?string, requester_user_id?: ?int} $f
     * @return array{rule: ?array, note: string}
     */
    public static function evaluate(array $f): array
    {
        $factsMatched = false;
        foreach (TicketRule::active() as $rule) {
            if (!self::matches($rule, $f)) {
                continue;
            }
            $factsMatched = true;
            if ($rule['scope_user_id'] !== null && !Unit::containsUser((int) $rule['scope_user_id'], (int) ($f['requester_user_id'] ?? 0))) {
                $note = "правило «{$rule['name']}» збіглося за умовами, але діє лише на заявників підрозділу (заявник не з підрозділу або без облікового запису)";
                continue;
            }
            return ['rule' => $rule, 'note' => ''];
        }
        return ['rule' => null, 'note' => isset($note) ? $note : ($factsMatched ? '' : 'жодне правило не збіглося за умовами')];
    }

    /** «Перевірка на прикладі»: що зробило б правило для умовного тікета (нічого не зберігається). */
    public static function simulate(array $f): array
    {
        $r = self::evaluate($f);
        $rule = $r['rule'];
        if (!$rule) {
            return ['rule' => null, 'note' => $r['note'] ?: 'жодне правило не збіглося', 'actions' => []];
        }
        $acts = [];
        if (!empty($rule['action_queue_name']) && (int) $rule['action_queue_id'] !== (int) $f['queue_id']) {
            $acts[] = 'перенесе в чергу «' . $rule['action_queue_name'] . '»';
        }
        if (!empty($rule['action_operator_name'])) {
            $acts[] = 'призначить оператора ' . $rule['action_operator_name'];
        }
        if (!empty($rule['action_priority'])) {
            $acts[] = 'встановить пріоритет «' . Ticket::priorityLabel($rule['action_priority']) . '»';
        }
        if (!empty($rule['action_label_name'])) {
            $acts[] = 'додасть мітку «' . $rule['action_label_name'] . '»';
        }
        if (!empty($rule['action_comment'])) {
            $acts[] = 'додасть коментар';
        }
        if (!empty($rule['notify_emails'])) {
            $acts[] = 'сповістить листом: ' . $rule['notify_emails'];
        }
        if (!empty($rule['notify_operator'])) {
            $acts[] = 'сповістить листом призначеного оператора';
        }
        if (!empty($rule['notify_system_operator'])) {
            $acts[] = 'сповістить у системі призначеного оператора';
        }
        $ids = self::systemUserIds($rule);
        if ($ids) {
            $acts[] = 'сповістить у системі працівників: ' . count($ids);
        }
        return ['rule' => $rule, 'note' => '', 'actions' => $acts];
    }

    /**
     * Застосовує правила до тікета для події $event. Помилки правил не повинні ламати створення тікета/зміну статусу/коментар.
     * @param bool $notify false — без сповіщень (масове застосування до наявних тікетів)
     * @param array{status?: string, comment_by?: string, comment?: string} $ctx дані події
     * @return ?array{rule: array, queue_changed: bool, operator_changed: bool, priority_changed: bool} застосоване правило або null
     */
    public static function apply(int $ticketId, bool $notify = true, string $event = 'created', array $ctx = []): ?array
    {
        self::$lastNote = '';
        try {
            if (!TicketRule::ready()) {
                self::$lastNote = 'правила не застосовано: немає таблиці правил (міграція 046)';
                return null;
            }
            $t = Ticket::find($ticketId);
            if (!$t) {
                return null;
            }
            [$attCount, $attBytes] = self::attachmentStats($ticketId);
            $r = self::evaluate([
                'subject' => (string) $t['subject'], 'description' => $t['description'] ?? null, 'queue_id' => (int) $t['queue_id'],
                'requester_email' => (string) $t['requester_email'], 'source' => $t['source'] ?? null,
                'requester_user_id' => $t['requester_user_id'] !== null ? (int) $t['requester_user_id'] : null,
                'event' => $event, 'status' => $ctx['status'] ?? $t['status'], 'comment_by' => $ctx['comment_by'] ?? null, 'comment' => $ctx['comment'] ?? null,
                'att_count' => $attCount, 'att_bytes' => $attBytes, 'operator_id' => $t['assigned_operator_id'] !== null ? (int) $t['assigned_operator_id'] : null,
            ]);
            if (!$r['rule']) {
                self::$lastNote = $r['note'];
                return null;
            }
            $res = self::execute($r['rule'], $t, $notify, $event, $ctx);
            self::$lastNote = "правило «{$r['rule']['name']}»" . ($res['queue_changed'] ? ': чергу змінено' : '') . ($res['operator_changed'] ? ', оператора призначено' : '') . ($res['priority_changed'] ? ', пріоритет змінено' : '');
            return $res;
        } catch (\Throwable $e) {
            error_log('Правила тікетів: ' . $e->getMessage());
            self::$lastNote = 'помилка правил: ' . $e->getMessage();
        }
        return null;
    }

    /**
     * Застосовує правила до наявних відкритих тікетів (без листів). Змінює лише те, що змінює правило; журнал — «rule_applied».
     * @return array{checked: int, matched: int, changed: int}
     */
    public static function applyToOpen(int $limit = 500): array
    {
        $ids = Database::connection()->query(
            "SELECT id FROM tickets WHERE status NOT IN ('resolved','closed') ORDER BY id DESC LIMIT " . (int) $limit
        )->fetchAll(\PDO::FETCH_COLUMN);
        $out = ['checked' => count($ids), 'matched' => 0, 'changed' => 0];
        foreach ($ids as $id) {
            $res = self::apply((int) $id, false);
            if ($res) {
                $out['matched']++;
                if ($res['queue_changed'] || $res['operator_changed'] || $res['priority_changed']) {
                    $out['changed']++;
                }
            }
        }
        return $out;
    }

    private static function execute(array $rule, array $t, bool $notify = true, string $event = 'created', array $ctx = []): array
    {
        $db = Database::connection();
        $ticketId = (int) $t['id'];
        $newQueue = !empty($rule['action_queue_id']) ? (int) $rule['action_queue_id'] : null;
        $queueChanged = $newQueue !== null && $newQueue !== (int) $t['queue_id'];
        $operator = $t['assigned_operator_id'] !== null ? (int) $t['assigned_operator_id'] : null;
        $operatorChanged = false;

        if ($queueChanged) {
            $db->prepare('UPDATE tickets SET queue_id = :q WHERE id = :id')->execute(['q' => $newQueue, 'id' => $ticketId]);
            // Автопризначення нової черги замість оператора старої.
            $operator = Ticket::defaultOperatorForQueue($newQueue);
            $operatorChanged = $operator !== ($t['assigned_operator_id'] !== null ? (int) $t['assigned_operator_id'] : null);
        }
        if (!empty($rule['action_operator_id']) && (int) $rule['action_operator_id'] !== $operator) {
            $operator = (int) $rule['action_operator_id'];
            $operatorChanged = true;
        }
        if ($operatorChanged) {
            $db->prepare('UPDATE tickets SET assigned_operator_id = :o WHERE id = :id')->execute(['o' => $operator, 'id' => $ticketId]);
        }
        $priorityChanged = false;
        if (!empty($rule['action_priority']) && isset(Ticket::PRIORITIES[$rule['action_priority']]) && ($t['priority'] ?? 'normal') !== $rule['action_priority']) {
            $db->prepare('UPDATE tickets SET priority = :p WHERE id = :id')->execute(['p' => $rule['action_priority'], 'id' => $ticketId]);
            $priorityChanged = true;
        }

        $labelAdded = false;
        if (!empty($rule['action_label_id'])) {
            $labelAdded = Ticket::addLabel($ticketId, (int) $rule['action_label_id']);
        }
        $commentAdded = false;
        if (!empty($rule['action_comment'])) {
            // Напряму, без подій: коментар правила не запускає інші правила й не вважається «першою відповіддю».
            $db->prepare("INSERT INTO ticket_comments (ticket_id, author_type, author_id, body) VALUES (:t, 'operator', NULL, :b)")
               ->execute(['t' => $ticketId, 'b' => '[Автоправило «' . $rule['name'] . '»] ' . $rule['action_comment']]);
            $commentAdded = true;
        }

        TicketRule::markHit((int) $rule['id']);
        Audit::log('ticket', $ticketId, 'rule_applied', null, [
            'rule_id' => (int) $rule['id'], 'rule' => $rule['name'], 'event' => $event,
            'queue_id' => $queueChanged ? $newQueue : null, 'operator_id' => $operatorChanged ? $operator : null,
            'priority' => $priorityChanged ? $rule['action_priority'] : null,
            'label_id' => $labelAdded ? (int) $rule['action_label_id'] : null, 'comment' => $commentAdded,
        ]);
        if ($notify) {
            self::notify($rule, $t, $queueChanged ? $newQueue : (int) $t['queue_id'], $operator, $event, $ctx, $priorityChanged);
        }
        return ['rule' => $rule, 'queue_changed' => $queueChanged, 'operator_changed' => $operatorChanged, 'priority_changed' => $priorityChanged || $labelAdded || $commentAdded];
    }

    /** @return int[] ідентифікатори працівників для сповіщення «в системі» (з поля notify_system_users) */
    public static function systemUserIds(array $rule): array
    {
        $ids = [];
        foreach (preg_split('/[\s,;]+/', (string) ($rule['notify_system_users'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $v) {
            if (ctype_digit($v) && (int) $v > 0) {
                $ids[(int) $v] = (int) $v;
            }
        }
        return array_values($ids);
    }

    /** Короткий опис події для сповіщень. */
    private static function eventText(string $event, array $ctx): string
    {
        return match ($event) {
            'status' => 'змінено статус на «' . Ticket::statusLabel((string) ($ctx['status'] ?? '')) . '»',
            'comment' => 'новий коментар (' . (($ctx['comment_by'] ?? '') === 'requester' ? 'від заявника' : 'від працівника') . ')',
            default => 'новий тікет',
        };
    }

    private static function notify(array $rule, array $t, int $queueId, ?int $operatorId, string $event = 'created', array $ctx = [], bool $priorityChanged = false): void
    {
        $what = self::eventText($event, $ctx);
        $url = '/tickets/' . (int) $t['id'];
        $title = 'Тікет №' . $t['id'] . ': спрацювало правило «' . $rule['name'] . '»';
        $text = 'Тікет №' . $t['id'] . ' — ' . $what . '. Тема: ' . $t['subject'] . ($priorityChanged ? '. Пріоритет: ' . Ticket::priorityLabel($rule['action_priority']) : '');

        // Сповіщення «в системі» (центр сповіщень) — не залежать від пошти.
        $sys = [];
        if (!empty($rule['notify_system_operator']) && $operatorId) {
            $sys[$operatorId] = $operatorId;
        }
        foreach (self::systemUserIds($rule) as $uid) {
            $sys[$uid] = $uid;
        }
        foreach ($sys as $uid) {
            try {
                \App\Models\UserNotification::notify($uid, $title, $text, $url);
            } catch (\Throwable $e) {
                error_log('Правила тікетів, сповіщення в системі: ' . $e->getMessage());
            }
        }

        $to = [];
        foreach (preg_split('/[\s,;]+/', (string) ($rule['notify_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $e) {
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $to[strtolower($e)] = '';
            }
        }
        if (!empty($rule['notify_operator']) && $operatorId) {
            $stmt = Database::connection()->prepare('SELECT email, full_name FROM users WHERE id = :id AND is_active = 1');
            $stmt->execute(['id' => $operatorId]);
            if ($u = $stmt->fetch()) {
                $to[strtolower((string) $u['email'])] = (string) $u['full_name'];
            }
        }
        if (!$to || !MailerService::isConfigured()) {
            return;
        }
        $subject = '[ITSM] ' . $title;
        $body = "Тікет №{$t['id']} відповідає правилу «{$rule['name']}»: {$what}.\n\nТема: {$t['subject']}\nЗаявник: {$t['requester_name']} <{$t['requester_email']}>\n"
            . 'Посилання: ' . rtrim(Setting::appUrl(), '/') . $url . "\n";
        foreach ($to as $email => $name) {
            try {
                MailerService::send($email, $name, $subject, $body);
            } catch (\Throwable $e) {
                error_log('Правила тікетів, сповіщення: ' . $e->getMessage());
            }
        }
    }
}
