<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Unit;
use App\Models\Audit;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketRule;

/**
 * Автоматичні правила черги тікетів. Викликається з Ticket::create() для кожного нового тікета (форма, портал, пошта, API).
 *
 * Правила перевіряються за порядком (sort_order, id); перше, що збіглося, виконується, далі перевірка зупиняється.
 * Умови (усі задані — одночасно): ключові слова в темі/описі (достатньо одного, без урахування регістру), поточна черга,
 * заявник (адреса чи домен), джерело тікета (форма, портал, пошта, API).
 * Правило адміністратора підрозділу діє лише на тікети заявників цього підрозділу.
 * Дії: перенести в чергу, призначити оператора, надіслати сповіщення (адреси та/або призначеному оператору).
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
     * Чи збігається правило з тікетом (без перевірки підрозділу). Усі задані умови мають виконуватися одночасно (І);
     * ключові слова — достатньо одного зі списку.
     * @param array{subject: string, description: ?string, queue_id: int, requester_email?: string, source?: ?string} $f
     */
    public static function matches(array $rule, array $f): bool
    {
        if (!empty($rule['from_queue_id']) && (int) $rule['from_queue_id'] !== (int) $f['queue_id']) {
            return false;
        }
        if (!empty($rule['source']) && ($f['source'] ?? null) !== $rule['source']) {
            return false;
        }
        if (!empty($rule['requester_match']) && !self::requesterMatches((string) $rule['requester_match'], (string) ($f['requester_email'] ?? ''))) {
            return false;
        }
        $keywords = self::parseKeywords((string) $rule['keywords']);
        if (!$keywords) {
            return true;
        }
        $haystack = match ($rule['match_in']) {
            'subject' => $f['subject'],
            'description' => (string) $f['description'],
            default => $f['subject'] . "\n" . (string) $f['description'],
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
        if (!empty($rule['notify_emails'])) {
            $acts[] = 'сповістить: ' . $rule['notify_emails'];
        }
        if (!empty($rule['notify_operator'])) {
            $acts[] = 'сповістить призначеного оператора';
        }
        return ['rule' => $rule, 'note' => '', 'actions' => $acts];
    }

    /**
     * Застосовує правила до тікета. Помилки правил не повинні ламати створення тікета.
     * @param bool $notify false — без листів (масове застосування до наявних тікетів)
     * @return ?array{rule: array, queue_changed: bool, operator_changed: bool} застосоване правило або null
     */
    public static function apply(int $ticketId, bool $notify = true): ?array
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
            $r = self::evaluate([
                'subject' => (string) $t['subject'], 'description' => $t['description'] ?? null, 'queue_id' => (int) $t['queue_id'],
                'requester_email' => (string) $t['requester_email'], 'source' => $t['source'] ?? null,
                'requester_user_id' => $t['requester_user_id'] !== null ? (int) $t['requester_user_id'] : null,
            ]);
            if (!$r['rule']) {
                self::$lastNote = $r['note'];
                return null;
            }
            $res = self::execute($r['rule'], $t, $notify);
            self::$lastNote = "правило «{$r['rule']['name']}»" . ($res['queue_changed'] ? ': чергу змінено' : '') . ($res['operator_changed'] ? ', оператора призначено' : '');
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
                if ($res['queue_changed'] || $res['operator_changed']) {
                    $out['changed']++;
                }
            }
        }
        return $out;
    }

    private static function execute(array $rule, array $t, bool $notify = true): array
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

        TicketRule::markHit((int) $rule['id']);
        Audit::log('ticket', $ticketId, 'rule_applied', null, [
            'rule_id' => (int) $rule['id'], 'rule' => $rule['name'],
            'queue_id' => $queueChanged ? $newQueue : null, 'operator_id' => $operatorChanged ? $operator : null,
        ]);
        if ($notify) {
            self::notify($rule, $t, $queueChanged ? $newQueue : (int) $t['queue_id'], $operator);
        }
        return ['rule' => $rule, 'queue_changed' => $queueChanged, 'operator_changed' => $operatorChanged];
    }

    private static function notify(array $rule, array $t, int $queueId, ?int $operatorId): void
    {
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
        $subject = '[ITSM] Тікет №' . $t['id'] . ': спрацювало правило «' . $rule['name'] . '»';
        $body = "Новий тікет №{$t['id']} відповідає правилу «{$rule['name']}».\n\nТема: {$t['subject']}\nЗаявник: {$t['requester_name']} <{$t['requester_email']}>\n"
            . "Посилання: " . rtrim(Setting::appUrl(), '/') . '/tickets/' . $t['id'] . "\n";
        foreach ($to as $email => $name) {
            try {
                MailerService::send($email, $name, $subject, $body);
            } catch (\Throwable $e) {
                error_log('Правила тікетів, сповіщення: ' . $e->getMessage());
            }
        }
    }
}
