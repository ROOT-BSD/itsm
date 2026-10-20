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
 * Умова: у темі та/або описі є хоча б одне ключове слово (без урахування регістру), і, якщо задано, тікет у потрібній черзі.
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

    /** Чи збігається правило з текстом тікета (лише умови за текстом і чергою, без перевірки підрозділу). */
    public static function matches(array $rule, string $subject, ?string $description, int $queueId): bool
    {
        if (!empty($rule['from_queue_id']) && (int) $rule['from_queue_id'] !== $queueId) {
            return false;
        }
        $haystack = match ($rule['match_in']) {
            'subject' => $subject,
            'description' => (string) $description,
            default => $subject . "\n" . (string) $description,
        };
        // Листи часто містять нерозривні пробіли, м'які переноси та невидимі символи — з ними слово не знаходилось.
        $haystack = (string) preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{FEFF}]/u', '', str_replace("\u{00A0}", ' ', $haystack));
        $haystack = (string) preg_replace('/\s+/u', ' ', $haystack);
        foreach (self::parseKeywords((string) $rule['keywords']) as $kw) {
            if (mb_stripos($haystack, (string) preg_replace('/\s+/u', ' ', $kw)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Застосовує правила до щойно створеного тікета. Помилки правил не повинні ламати створення тікета.
     * @return ?array{rule: array, queue_changed: bool, operator_changed: bool} застосоване правило або null
     */
    public static function apply(int $ticketId): ?array
    {
        self::$lastNote = '';
        try {
            if (!TicketRule::ready()) {
                self::$lastNote = 'правила не застосовано: немає таблиці правил (міграція 046)';
                return null;
            }
            $rules = TicketRule::active();
            if (!$rules) {
                return null;
            }
            $t = Ticket::find($ticketId);
            if (!$t) {
                return null;
            }
            $textMatched = false;
            foreach ($rules as $rule) {
                if (!self::matches($rule, (string) $t['subject'], $t['description'] ?? null, (int) $t['queue_id'])) {
                    continue;
                }
                $textMatched = true;
                if ($rule['scope_user_id'] !== null && !Unit::containsUser((int) $rule['scope_user_id'], (int) ($t['requester_user_id'] ?? 0))) {
                    self::$lastNote = "правило «{$rule['name']}» збіглося за текстом, але діє лише на заявників підрозділу (заявник не з підрозділу або без облікового запису)";
                    continue;
                }
                $res = self::execute($rule, $t);
                self::$lastNote = "правило «{$rule['name']}»" . ($res['queue_changed'] ? ': чергу змінено' : '') . ($res['operator_changed'] ? ', оператора призначено' : '');
                return $res;
            }
            if (!$textMatched) {
                self::$lastNote = 'жодне правило не збіглося за ключовими словами';
            }
        } catch (\Throwable $e) {
            error_log('Правила тікетів: ' . $e->getMessage());
            self::$lastNote = 'помилка правил: ' . $e->getMessage();
        }
        return null;
    }

    private static function execute(array $rule, array $t): array
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
        self::notify($rule, $t, $queueChanged ? $newQueue : (int) $t['queue_id'], $operator);
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
