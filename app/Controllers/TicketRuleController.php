<?php

namespace App\Controllers;

use App\Core\Access;
use App\Core\Auth;
use App\Core\Unit;
use App\Core\View;
use App\Models\Audit;
use App\Models\Ticket;
use App\Models\TicketRule;
use App\Services\TicketRuleService;

/**
 * Автоматичні правила черги тікетів: створювати, змінювати й видаляти їх може ЛИШЕ адміністратор системи.
 * Адміністратор підрозділу, менеджер ІТ і оператори бачать (тільки читання) правила, налаштовані на них:
 * де вони вказані оператором, а адміністратор підрозділу — ще й правила своїх працівників та власні (раніше створені).
 */
class TicketRuleController
{
    private const OPERATOR_ROLES = ['admin', 'unit_admin', 'it_manager', 'support_operator'];

    private bool $unit;
    /** Режим «лише перегляд» (усі, крім адміністратора системи). */
    private bool $readOnly;

    /** @param bool $unit розділ «Мій підрозділ»; $mine — загальна сторінка перегляду /ticket-rules для співробітників */
    public function __construct(bool $unit, bool $mine = false)
    {
        Auth::requireLogin();
        $this->unit = $unit;
        $this->readOnly = $unit || $mine;
        if ($unit && !Access::isUnitAdmin(Auth::role())) {
            http_response_code(403);
            echo 'Ця сторінка доступна лише ролі «Адміністратор Підрозділу».';
            exit;
        }
        if ($mine && !Auth::hasRole(['admin', 'unit_admin', 'it_manager', 'support_operator'])) {
            http_response_code(403);
            echo 'Недостатньо прав.';
            exit;
        }
        if (!$this->readOnly && !Auth::hasRole(['admin'])) {
            http_response_code(403);
            echo 'Доступно лише ролі «Адміністратор системи».';
            exit;
        }
    }

    private function forbidWrite(): void
    {
        if ($this->readOnly) {
            http_response_code(403);
            echo 'Правила черги створює й змінює лише адміністратор системи.';
            exit;
        }
    }

    private function base(): string
    {
        return $this->unit ? '/unit/ticket-rules' : ($this->readOnly ? '/ticket-rules' : '/admin/ticket-rules');
    }

    /** Правила, налаштовані «на» поточного користувача (для режиму перегляду). */
    private function visibleRules(): array
    {
        $me = (int) Auth::id();
        $unitAdmin = Access::isUnitAdmin(Auth::role());
        return array_values(array_filter(TicketRule::all(null), static function ($r) use ($me, $unitAdmin) {
            if ((int) ($r['scope_user_id'] ?? 0) === $me || (int) ($r['action_operator_id'] ?? 0) === $me) {
                return true;
            }
            return $unitAdmin && !empty($r['action_operator_id']) && Unit::containsUser($me, (int) $r['action_operator_id']);
        }));
    }

    public function index(): void
    {
        $this->renderIndex();
    }

    private function renderIndex(array $extra = []): void
    {
        $edit = !$this->readOnly && !empty($_GET['edit']) ? $this->ownRule((int) $_GET['edit']) : null;
        View::render('admin/ticket_rules', $extra + [
            'sources' => TicketRuleService::SOURCES,
            'unitMode' => $this->unit,
            'readOnly' => $this->readOnly,
            'base' => $this->base(),
            'rules' => $this->readOnly ? $this->visibleRules() : TicketRule::all(null),
            'ready' => TicketRule::ready(),
            'queues' => Ticket::queues(),
            'operators' => $this->operators(),
            'edit' => $edit,
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    /** Працівники, яких можна призначати правилом (для адміністратора підрозділу — лише його підрозділу). */
    private function operators(): array
    {
        $in = implode(',', array_fill(0, count(self::OPERATOR_ROLES), '?'));
        $stmt = \App\Core\Database::connection()->prepare(
            "SELECT u.id, u.full_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.code IN ({$in}) ORDER BY u.full_name"
        );
        $stmt->execute(self::OPERATOR_ROLES);
        $all = $stmt->fetchAll();
        if (!$this->unit) {
            return $all;
        }
        return array_values(array_filter($all, fn($u) => Unit::containsUser((int) Auth::id(), (int) $u['id'])));
    }

    /** Правило, яке поточний користувач вправі змінювати, або null. */
    private function ownRule(int $id): ?array
    {
        $r = TicketRule::find($id);
        if (!$r) {
            return null;
        }
        if ($this->unit && (int) ($r['scope_user_id'] ?? 0) !== (int) Auth::id()) {
            return null;
        }
        return $r;
    }

    public function save(): void
    {
        $this->forbidWrite();
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $existing = $id !== null ? $this->ownRule($id) : null;
        if ($id !== null && !$existing) {
            $this->back('error', 'Правило не знайдено.');
        }
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
        $keywords = implode("\n", TicketRuleService::parseKeywords((string) ($_POST['keywords'] ?? '')));
        $matchIn = in_array($_POST['match_in'] ?? '', ['subject', 'description', 'both'], true) ? $_POST['match_in'] : 'both';
        $queueIds = array_map(fn($q) => (int) $q['id'], Ticket::queues());
        $fromQ = (int) ($_POST['from_queue_id'] ?? 0) ?: null;
        $source = array_key_exists($_POST['source'] ?? '', TicketRuleService::SOURCES) ? $_POST['source'] : null;
        $reqTokens = TicketRuleService::parseRequesterMatch((string) ($_POST['requester_match'] ?? ''));
        foreach ($reqTokens as $t) {
            if (!preg_match('/^(@?[a-z0-9._-]+\.[a-z0-9-]{2,}|[^@\s]+@[^@\s]+\.[^@\s]{2,})$/iu', $t)) {
                $this->back('error', 'Некоректна умова за заявником: «' . mb_substr($t, 0, 60) . '». Вкажіть адресу (user@firma.ua) або домен (@firma.ua).', $id);
            }
        }
        $reqMatch = $reqTokens ? implode(', ', $reqTokens) : null;
        if (mb_strlen((string) $reqMatch) > 500) {
            $this->back('error', 'Забагато адрес/доменів у умові за заявником (до 500 символів).', $id);
        }
        $toQ = (int) ($_POST['action_queue_id'] ?? 0) ?: null;
        $op = (int) ($_POST['action_operator_id'] ?? 0) ?: null;
        $notifyOp = !empty($_POST['notify_operator']);
        $emails = [];
        foreach (preg_split('/[\s,;]+/', (string) ($_POST['notify_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $e) {
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $this->back('error', 'Некоректна адреса для сповіщення: ' . $e, $id);
            }
            $emails[strtolower($e)] = strtolower($e);
        }
        $emailStr = implode(', ', $emails);

        if ($name === '') {
            $this->back('error', 'Вкажіть назву правила.', $id);
        }
        if ($keywords === '' && $reqMatch === null && $source === null) {
            $this->back('error', 'Вкажіть хоча б одну умову: ключові слова, заявника (адреса/домен) або джерело тікета.', $id);
        }
        if (($fromQ && !in_array($fromQ, $queueIds, true)) || ($toQ && !in_array($toQ, $queueIds, true))) {
            $this->back('error', 'Чергу не знайдено.', $id);
        }
        if ($op !== null && !in_array($op, array_map(fn($u) => (int) $u['id'], $this->operators()), true)) {
            $this->back('error', 'Цього працівника не можна призначити (оберіть зі списку' . ($this->unit ? ' — лише працівники вашого підрозділу' : '') . ').', $id);
        }
        if ($toQ === null && $op === null && $emailStr === '' && !$notifyOp) {
            $this->back('error', 'Оберіть хоча б одну дію: нова черга, оператор або сповіщення.', $id);
        }
        if (mb_strlen($emailStr) > 500) {
            $this->back('error', 'Забагато адрес для сповіщення (до 500 символів).', $id);
        }

        $rid = TicketRule::save($id, [
            'name' => $name, 'scope_user_id' => $existing['scope_user_id'] ?? null, 'sort_order' => max(1, min(9999, (int) ($_POST['sort_order'] ?? 100))),
            'from_queue_id' => $fromQ, 'requester_match' => $reqMatch, 'source' => $source, 'keywords' => $keywords, 'match_in' => $matchIn, 'action_queue_id' => $toQ,
            'action_operator_id' => $op, 'notify_emails' => $emailStr ?: null, 'notify_operator' => $notifyOp,
            'is_active' => !empty($_POST['is_active']), 'created_by' => (int) Auth::id(),
        ]);
        Audit::log('ticket_rule', $rid, $id === null ? 'created' : 'updated', (int) Auth::id(), ['name' => $name]);
        $this->back('success', 'Правило «' . $name . '» збережено.');
    }

    /** «Перевірка на прикладі»: яке правило спрацює для умовного тікета (нічого не зберігається й не надсилається). */
    public function test(): void
    {
        $this->forbidWrite();
        $subject = trim((string) ($_POST['t_subject'] ?? ''));
        $email = trim((string) ($_POST['t_email'] ?? ''));
        $source = array_key_exists($_POST['t_source'] ?? '', TicketRuleService::SOURCES) ? $_POST['t_source'] : null;
        $queueId = (int) ($_POST['t_queue_id'] ?? 0);
        $user = $email !== '' ? \App\Models\User::findByEmail($email) : null;
        $input = ['subject' => $subject, 'description' => trim((string) ($_POST['t_description'] ?? '')), 'email' => $email, 'source' => $source, 'queue_id' => $queueId];
        $result = TicketRuleService::simulate([
            'subject' => $subject, 'description' => $input['description'], 'queue_id' => $queueId, 'requester_email' => $email,
            'source' => $source, 'requester_user_id' => $user ? (int) $user['id'] : null,
        ]);
        $queueName = '';
        foreach (Ticket::queues() as $q) {
            if ((int) $q['id'] === $queueId) { $queueName = $q['name']; }
        }
        $this->renderIndex(['testResult' => $result, 'testInput' => $input + ['queue_name' => $queueName]]);
    }

    /** Застосовує активні правила до наявних відкритих тікетів (без листів). */
    public function applyExisting(): void
    {
        $this->forbidWrite();
        @set_time_limit(120);
        $r = TicketRuleService::applyToOpen(500);
        Audit::log('ticket_rule', 0, 'applied_to_open', (int) Auth::id(), $r);
        $this->back('success', "Перевірено відкритих тікетів: {$r['checked']}; правило спрацювало для {$r['matched']}; змінено чергу чи оператора: {$r['changed']}. Листи не надсилалися.");
    }

    public function toggle(array $params): void
    {
        $this->forbidWrite();
        $r = $this->ownRule((int) $params['id']);
        if (!$r) {
            $this->back('error', 'Правило не знайдено.');
        }
        TicketRule::setActive((int) $r['id'], !(int) $r['is_active']);
        Audit::log('ticket_rule', (int) $r['id'], (int) $r['is_active'] ? 'disabled' : 'enabled', (int) Auth::id(), ['name' => $r['name']]);
        $this->back('success', 'Правило «' . $r['name'] . '» ' . ((int) $r['is_active'] ? 'вимкнено.' : 'увімкнено.'));
    }

    public function delete(array $params): void
    {
        $this->forbidWrite();
        $r = $this->ownRule((int) $params['id']);
        if (!$r) {
            $this->back('error', 'Правило не знайдено.');
        }
        TicketRule::delete((int) $r['id']);
        Audit::log('ticket_rule', (int) $r['id'], 'deleted', (int) Auth::id(), ['name' => $r['name']]);
        $this->back('success', 'Правило «' . $r['name'] . '» видалено.');
    }

    private function back(string $key, string $message, ?int $edit = null): never
    {
        header('Location: ' . $this->base() . '?' . $key . '=' . urlencode($message) . ($key === 'error' && $edit ? '&edit=' . $edit : ''));
        exit;
    }
}
