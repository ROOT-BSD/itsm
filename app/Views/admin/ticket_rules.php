<?php
$e = static fn($v) => \App\Core\View::e((string) $v);
$f = $edit ?? [];
$val = static fn(string $k, $d = '') => $f[$k] ?? $d;
?>
<div class="mb-3">
    <?php if (!empty($unitMode)) { require __DIR__ . '/../unit/_nav.php'; } elseif (!empty($readOnly)) { echo '<a href="/tickets" class="text-decoration-none">&larr; Тікети</a>'; } else { require __DIR__ . '/_back.php'; } ?>
</div>

<h3 class="mb-1">Автоматичні правила черги тікетів</h3>
<p class="text-muted">
    Правило спрацьовує на <strong>подію</strong>: створення тікета (форма, портал, пошта, API), зміну його статусу або новий коментар — якщо виконуються всі задані умови: ключове слово (в темі, описі чи коментарі), заявник (адреса чи домен), джерело, поточна черга, вкладення (є/немає, кількість, розмір).
    Дії: перенести в іншу чергу, призначити оператора, встановити пріоритет, надіслати сповіщення (листом і/або «в системі» — у центр сповіщень 🔔). Для кожної події правила перевіряються за порядком (менше число — раніше);
    <strong>перше, що збіглося, виконується</strong>, і перевірка зупиняється.
    <?php if (!empty($readOnly)): ?><br><strong>Лише перегляд:</strong> правила створює й змінює тільки адміністратор системи. Нижче — правила, налаштовані на вас<?= !empty($unitMode) ? ' та працівників вашого підрозділу' : '' ?>.<?php endif; ?>
</p>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>
<?php if (empty($ready)): ?>
    <div class="alert alert-warning">Таблицю правил ще не створено — виконайте <code>sudo bash update.sh</code> (міграція 046).</div>
<?php else: ?>

<table class="table table-bordered bg-white table-sm align-middle">
    <thead><tr><th>№</th><th>Правило</th><th>Подія й умова</th><th>Дії</th><th>Спрацювань</th><th>Стан</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rules): ?>
        <tr><td colspan="7" class="text-center text-muted"><?= !empty($readOnly) ? 'На вас правил не налаштовано' : 'Правил ще немає' ?></td></tr>
    <?php else: foreach ($rules as $r):
        $kw = \App\Services\TicketRuleService::parseKeywords((string) $r['keywords']);
        $where = ['subject' => 'у темі', 'description' => 'в описі', 'both' => 'у темі чи описі', 'comment' => 'в коментарі'][$r['match_in']] ?? '';
        $ev = $r['trigger_event'] ?? 'created';
        ?>
        <tr class="<?= (int) $r['is_active'] ? '' : 'text-muted' ?>">
            <td><?= (int) $r['sort_order'] ?></td>
            <td>
                <strong><?= $e($r['name']) ?></strong>
                <?php if (empty($unitMode)): ?><div class="small text-muted"><?= $r['scope_user_id'] === null ? 'Глобальне' : 'Підрозділ: ' . $e($r['scope_name']) ?></div><?php endif; ?>
            </td>
            <td class="small">
                <div><span class="badge text-bg-info"><?= $e($events[$ev] ?? $ev) ?></span>
                    <?php if ($ev === 'status' && !empty($r['when_status'])): ?> → «<?= $e(\App\Models\Ticket::statusLabel($r['when_status'])) ?>»<?php endif; ?>
                    <?php if ($ev === 'comment' && ($r['comment_by'] ?? 'any') !== 'any'): ?> від: <?= $e($commentBy[$r['comment_by']] ?? '') ?><?php endif; ?></div>
                <?php if ($kw): ?><?= $where ?>: <?php foreach ($kw as $k): ?><span class="badge text-bg-light border"><?= $e($k) ?></span> <?php endforeach; ?><?php endif; ?>
                <?php if (!empty($r['requester_match'])): ?><div>заявник: <?= $e($r['requester_match']) ?></div><?php endif; ?>
                <?php if (!empty($r['source'])): ?><div>джерело: <?= $e($sources[$r['source']] ?? $r['source']) ?></div><?php endif; ?>
                <?php if (($r['att_mode'] ?? 'any') !== 'any' || !empty($r['att_min_count']) || $r['att_min_mb'] !== null || $r['att_max_mb'] !== null): ?>
                    <div>вкладення: <?= $e(implode(', ', array_filter([
                        ($r['att_mode'] ?? 'any') !== 'any' ? $attModes[$r['att_mode']] : '',
                        !empty($r['att_min_count']) ? 'не менше ' . (int) $r['att_min_count'] . ' шт.' : '',
                        $r['att_min_mb'] !== null ? 'від ' . rtrim(rtrim((string) $r['att_min_mb'], '0'), '.') . ' МБ' : '',
                        $r['att_max_mb'] !== null ? 'до ' . rtrim(rtrim((string) $r['att_max_mb'], '0'), '.') . ' МБ' : '',
                    ]))) ?></div>
                <?php endif; ?>
                <?php if (($r['cond_operator'] ?? 'any') !== 'any'): ?><div>оператор: <?= $r['cond_operator'] === 'unassigned' ? 'не призначений' : ($r['cond_operator_name'] ? $e($r['cond_operator_name']) : 'призначений') ?></div><?php endif; ?>
                <?php if ($r['from_queue_name']): ?><div>лише в черзі «<?= $e($r['from_queue_name']) ?>»</div><?php endif; ?>
            </td>
            <td class="small">
                <?php $acts = [];
                if ($r['action_queue_name']) { $acts[] = 'у чергу «' . $r['action_queue_name'] . '»'; }
                if ($r['action_operator_name']) { $acts[] = 'оператор: ' . $r['action_operator_name']; }
                if (!empty($r['action_label_name'])) { $acts[] = 'мітка: ' . $r['action_label_name']; }
                if (!empty($r['action_comment'])) { $acts[] = 'додати коментар'; }
                if (!empty($r['action_priority'])) { $acts[] = 'пріоритет: ' . \App\Models\Ticket::priorityLabel($r['action_priority']); }
                if ($r['notify_emails']) { $acts[] = 'сповістити листом: ' . $r['notify_emails']; }
                if ((int) $r['notify_operator']) { $acts[] = 'сповістити листом оператора'; }
                if ((int) ($r['notify_system_operator'] ?? 0)) { $acts[] = 'в системі: оператору'; }
                if (!empty($r['notify_system_users'])) { $acts[] = 'в системі: працівників ' . count(array_filter(explode(',', $r['notify_system_users']))); }
                echo $e(implode('; ', $acts)); ?>
            </td>
            <td class="small"><?= (int) $r['hits'] ?><?= $r['last_hit_at'] ? '<div class="text-muted">' . $e(date('d.m.Y H:i', strtotime($r['last_hit_at']))) . '</div>' : '' ?></td>
            <td><?= (int) $r['is_active'] ? '<span class="badge text-bg-success">увімкнено</span>' : '<span class="badge text-bg-secondary">вимкнено</span>' ?></td>
            <td class="text-nowrap">
                <?php if (empty($readOnly)): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= $e($base) ?>?edit=<?= (int) $r['id'] ?>">Змінити</a>
                <form method="post" action="<?= $e($base) ?>/<?= (int) $r['id'] ?>/toggle" class="d-inline"><?= \App\Core\Csrf::field() ?>
                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= (int) $r['is_active'] ? 'Вимкнути' : 'Увімкнути' ?></button></form>
                <form method="post" action="<?= $e($base) ?>/<?= (int) $r['id'] ?>/delete" class="d-inline" data-confirm="Видалити правило «<?= $e($r['name']) ?>»?"><?= \App\Core\Csrf::field() ?>
                    <button class="btn btn-sm btn-outline-danger" type="submit">Видалити</button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

<?php if (empty($readOnly)): ?>
<div class="card shadow-sm mt-4 form-card-md">
    <div class="card-header"><?= $edit ? 'Зміна правила' : 'Нове правило' ?></div>
    <div class="card-body">
        <form method="post" action="<?= $e($base) ?>">
            <?= \App\Core\Csrf::field() ?>
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Назва</label>
                    <input type="text" name="name" maxlength="150" class="form-control" value="<?= $e($val('name')) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Порядок</label>
                    <input type="number" name="sort_order" min="1" max="9999" class="form-control" value="<?= (int) $val('sort_order', 100) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Коли спрацьовує (подія)</label>
                    <select name="trigger_event" id="rule-event" class="form-select">
                        <?php foreach ($events as $k => $l): ?><option value="<?= $e($k) ?>" <?= $val('trigger_event', 'created') === $k ? 'selected' : '' ?>><?= $e(ucfirst($l)) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4" data-rule-ev="status">
                    <label class="form-label">Новий статус <span class="text-muted">(необов'язково)</span></label>
                    <select name="when_status" class="form-select">
                        <option value="">— будь-який —</option>
                        <?php foreach (\App\Models\Ticket::STATUSES as $st): ?><option value="<?= $e($st) ?>" <?= $val('when_status', '') === $st ? 'selected' : '' ?>><?= $e(\App\Models\Ticket::statusLabel($st)) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4" data-rule-ev="comment">
                    <label class="form-label">Коментар залишив</label>
                    <select name="comment_by" class="form-select">
                        <?php foreach ($commentBy as $k => $l): ?><option value="<?= $e($k) ?>" <?= $val('comment_by', 'any') === $k ? 'selected' : '' ?>><?= $e(ucfirst($l)) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Ключові слова</label>
                    <textarea name="keywords" rows="3" class="form-control" placeholder="принтер, друк, картридж"><?= $e(implode("\n", \App\Services\TicketRuleService::parseKeywords((string) $val('keywords')))) ?></textarea>
                    <div class="form-text">Через кому чи з нового рядка; регістр не враховується; достатньо збігу з будь-яким словом (частина слова теж збігається). Можна залишити порожнім, якщо задано іншу умову нижче.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Де шукати</label>
                    <select name="match_in" class="form-select">
                        <?php foreach (['both' => 'У темі та описі (для коментаря — ще й у тексті коментаря)', 'subject' => 'Лише в темі', 'description' => 'Лише в описі', 'comment' => 'Лише в тексті коментаря (подія «новий коментар»)'] as $k => $l): ?>
                            <option value="<?= $k ?>" <?= $val('match_in', 'both') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Лише для тікетів у черзі <span class="text-muted">(необов'язково)</span></label>
                    <select name="from_queue_id" class="form-select">
                        <option value="">— будь-яка —</option>
                        <?php foreach ($queues as $q): ?><option value="<?= (int) $q['id'] ?>" <?= (int) $val('from_queue_id', 0) === (int) $q['id'] ? 'selected' : '' ?>><?= $e($q['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Заявник: адреси чи домени <span class="text-muted">(необов'язково)</span></label>
                    <input type="text" name="requester_match" maxlength="500" class="form-control" placeholder="@firma.ua, boss@example.com" value="<?= $e($val('requester_match')) ?>">
                    <div class="form-text">Через кому. «@firma.ua» — будь-яка адреса домену (і його піддоменів), «boss@example.com» — точна адреса.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Джерело тікета <span class="text-muted">(необов'язково)</span></label>
                    <select name="source" class="form-select">
                        <option value="">— будь-яке —</option>
                        <?php foreach ($sources as $k => $l): ?><option value="<?= $e($k) ?>" <?= $val('source', '') === $k ? 'selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?>
                    </select>
                    <div class="form-text">Для тікетів, створених до версії 0.9.00, джерело невідоме.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Вкладення тікета</label>
                    <select name="att_mode" class="form-select">
                        <?php foreach ($attModes as $k => $l): ?><option value="<?= $e($k) ?>" <?= $val('att_mode', 'any') === $k ? 'selected' : '' ?>><?= $e(ucfirst($l)) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label">Файлів, від</label>
                    <input type="number" name="att_min_count" min="1" max="1000" class="form-control" value="<?= $val('att_min_count') ? (int) $val('att_min_count') : '' ?>"></div>
                <div class="col-md-3"><label class="form-label">Загальний розмір від, МБ</label>
                    <input type="text" name="att_min_mb" inputmode="decimal" class="form-control" value="<?= $val('att_min_mb') !== '' && $val('att_min_mb') !== null ? $e(rtrim(rtrim((string) $val('att_min_mb'), '0'), '.')) : '' ?>"></div>
                <div class="col-md-3"><label class="form-label">Загальний розмір до, МБ</label>
                    <input type="text" name="att_max_mb" inputmode="decimal" class="form-control" value="<?= $val('att_max_mb') !== '' && $val('att_max_mb') !== null ? $e(rtrim(rtrim((string) $val('att_max_mb'), '0'), '.')) : '' ?>"></div>
                <div class="col-md-5">
                    <label class="form-label">Призначений оператор</label>
                    <select name="cond_operator" class="form-select">
                        <?php foreach (['any' => 'Не важливо', 'unassigned' => 'Не призначений', 'assigned' => 'Призначений (будь-хто або конкретний нижче)'] as $k => $l): ?>
                            <option value="<?= $k ?>" <?= $val('cond_operator', 'any') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Конкретний оператор <span class="text-muted">(для «Призначений»)</span></label>
                    <select name="cond_operator_id" class="form-select">
                        <option value="">— будь-який —</option>
                        <?php foreach ($operators as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $val('cond_operator_id', 0) === (int) $u['id'] ? 'selected' : '' ?>><?= $e($u['full_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"></div>
                <div class="col-12 form-text mt-0">Враховуються всі вкладення тікета (разом із доданими до коментарів). Для події «створення» перевіряється після збереження вкладень.</div>
                <div class="col-12"><hr class="my-1"><strong>Дії</strong> <span class="text-muted small">(потрібна хоча б одна)</span></div>
                <div class="col-md-6">
                    <label class="form-label">Перенести в чергу</label>
                    <select name="action_queue_id" class="form-select">
                        <option value="">— не змінювати —</option>
                        <?php foreach ($queues as $q): ?><option value="<?= (int) $q['id'] ?>" <?= (int) $val('action_queue_id', 0) === (int) $q['id'] ? 'selected' : '' ?>><?= $e($q['name']) ?></option><?php endforeach; ?>
                    </select>
                    <div class="form-text">Якщо оператора нижче не задано, призначається оператор за замовчуванням нової черги.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Призначити оператора</label>
                    <select name="action_operator_id" class="form-select">
                        <option value="">— не змінювати —</option>
                        <?php foreach ($operators as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $val('action_operator_id', 0) === (int) $u['id'] ? 'selected' : '' ?>><?= $e($u['full_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Встановити пріоритет</label>
                    <select name="action_priority" class="form-select">
                        <option value="">— не змінювати —</option>
                        <?php foreach (\App\Models\Ticket::PRIORITIES as $k => $l): ?><option value="<?= $e($k) ?>" <?= $val('action_priority', '') === $k ? 'selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Додати мітку</label>
                    <select name="action_label_id" class="form-select">
                        <option value="">— не додавати —</option>
                        <?php foreach ($labels as $lb): ?><option value="<?= (int) $lb['id'] ?>" <?= (int) $val('action_label_id', 0) === (int) $lb['id'] ? 'selected' : '' ?>><?= $e($lb['name']) ?></option><?php endforeach; ?>
                    </select>
                    <div class="form-text">Мітки створює адміністратор: Адмін-панель → Мітки тікетів.</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Додати коментар у тікет <span class="text-muted">(необов'язково)</span></label>
                    <textarea name="action_comment" rows="2" maxlength="1000" class="form-control"><?= $e($val('action_comment')) ?></textarea>
                    <div class="form-text">З'явиться в тікеті від імені «оператор» із позначкою правила; заявник побачить його як звичайну відповідь. Коментар правила не запускає інших правил.</div>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Сповістити листом на адреси</label>
                    <input type="text" name="notify_emails" maxlength="500" class="form-control" placeholder="it-lead@example.com, duty@example.com" value="<?= $e($val('notify_emails')) ?>">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="notify_operator" id="notify_operator" value="1" <?= (int) $val('notify_operator', 0) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="notify_operator">і призначеному оператору</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Сповістити «в системі» (центр сповіщень 🔔, без пошти)</label>
                    <select name="notify_system_users[]" multiple size="5" class="form-select">
                        <?php $sel = array_filter(explode(',', (string) $val('notify_system_users', ''))); foreach ($operators as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= in_array((string) $u['id'], $sel, true) ? 'selected' : '' ?>><?= $e($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Ctrl/Cmd + клік — кілька працівників.</div>
                </div>
                <div class="col-md-6 d-flex align-items-start pt-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="notify_system_operator" id="notify_system_operator" value="1" <?= (int) $val('notify_system_operator', 0) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="notify_system_operator">і призначеному оператору — в системі</label>
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?= (int) $val('is_active', 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Правило увімкнено</label>
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Зберегти</button>
                <?php if ($edit): ?><a class="btn btn-outline-secondary" href="<?= $e($base) ?>">Скасувати</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mt-4 form-card-md">
    <div class="card-header">Перевірка на прикладі</div>
    <div class="card-body">
        <p class="small text-muted">Вкажіть умовний тікет — система покаже, яке правило спрацює і що воно зробить. Нічого не створюється й не надсилається.</p>
        <form method="post" action="<?= $e($base) ?>/test">
            <?= \App\Core\Csrf::field() ?>
            <div class="row g-3">
                <div class="col-md-8"><label class="form-label">Тема</label>
                    <input type="text" name="t_subject" class="form-control" value="<?= $e($testInput['subject'] ?? '') ?>"></div>
                <div class="col-md-4"><label class="form-label">Email заявника</label>
                    <input type="text" name="t_email" class="form-control" placeholder="user@firma.ua" value="<?= $e($testInput['email'] ?? '') ?>"></div>
                <div class="col-12"><label class="form-label">Опис</label>
                    <textarea name="t_description" rows="2" class="form-control"><?= $e($testInput['description'] ?? '') ?></textarea></div>
                <div class="col-md-4"><label class="form-label">Подія</label>
                    <select name="t_event" class="form-select"><?php foreach ($events as $k => $l): ?><option value="<?= $e($k) ?>" <?= ($testInput['event'] ?? 'created') === $k ? 'selected' : '' ?>><?= $e(ucfirst($l)) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label">Статус (для зміни статусу)</label>
                    <select name="t_status" class="form-select"><option value="">—</option><?php foreach (\App\Models\Ticket::STATUSES as $st): ?><option value="<?= $e($st) ?>" <?= ($testInput['status'] ?? '') === $st ? 'selected' : '' ?>><?= $e(\App\Models\Ticket::statusLabel($st)) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label">Коментар від</label>
                    <select name="t_comment_by" class="form-select"><?php foreach (['staff' => 'працівника підтримки', 'requester' => 'заявника'] as $k => $l): ?><option value="<?= $k ?>" <?= ($testInput['comment_by'] ?? 'staff') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><label class="form-label">Текст коментаря</label>
                    <input type="text" name="t_comment" class="form-control" value="<?= $e($testInput['comment'] ?? '') ?>"></div>
                <div class="col-md-3"><label class="form-label">Вкладень, шт.</label>
                    <input type="number" name="t_att_count" min="0" class="form-control" value="<?= (int) ($testInput['att_count'] ?? 0) ?>"></div>
                <div class="col-md-3"><label class="form-label">Загальний розмір, МБ</label>
                    <input type="text" name="t_att_mb" class="form-control" value="<?= $e($testInput['att_mb'] ?? 0) ?>"></div>
                <div class="col-md-6"><label class="form-label">Призначений оператор</label>
                    <select name="t_operator_id" class="form-select"><option value="">— не призначений —</option>
                        <?php foreach ($operators as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) ($testInput['operator_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= $e($u['full_name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-6"><label class="form-label">Джерело</label>
                    <select name="t_source" class="form-select"><option value="">— невідоме —</option>
                        <?php foreach ($sources as $k => $l): ?><option value="<?= $e($k) ?>" <?= ($testInput['source'] ?? '') === $k ? 'selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-6"><label class="form-label">Черга тікета</label>
                    <select name="t_queue_id" class="form-select">
                        <?php foreach ($queues as $q): ?><option value="<?= (int) $q['id'] ?>" <?= (int) ($testInput['queue_id'] ?? 0) === (int) $q['id'] ? 'selected' : '' ?>><?= $e($q['name']) ?></option><?php endforeach; ?>
                    </select></div>
            </div>
            <button class="btn btn-outline-primary mt-3" type="submit">Перевірити</button>
        </form>
        <?php if (isset($testResult)): ?>
            <div class="alert <?= $testResult['rule'] ? 'alert-success' : 'alert-secondary' ?> mt-3 mb-0" id="rule-test-result">
                <?php if ($testResult['rule']): ?>
                    Спрацює правило <strong>«<?= $e($testResult['rule']['name']) ?>»</strong> (№ <?= (int) $testResult['rule']['sort_order'] ?>)<?= $testResult['actions'] ? ': ' . $e(implode('; ', $testResult['actions'])) : ' — без змін у черзі й операторі' ?>.
                <?php else: ?>
                    Правила не спрацюють: <?= $e($testResult['note']) ?>.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm mt-4 form-card-md">
    <div class="card-header">Застосувати до наявних тікетів</div>
    <div class="card-body">
        <p class="small text-muted mb-2">Перевіряє активні правила для відкритих тікетів (до 500 найновіших, крім вирішених і закритих) і виконує дії першого правила події «створення», що збіглося: черга, оператор, пріоритет (враховуються і вкладення).
            Сповіщення (листи й у системі) <strong>не надсилаються</strong>. Тікети, створені до версії 0.9.00, не мають джерела — правила з умовою за джерелом на них не спрацюють. Спершу перевірте правила «на прикладі».</p>
        <form method="post" action="<?= $e($base) ?>/apply-existing" data-confirm="Застосувати активні правила до відкритих тікетів? Черги й оператори можуть змінитися.">
            <?= \App\Core\Csrf::field() ?>
            <button class="btn btn-outline-danger" type="submit">Застосувати до відкритих тікетів</button>
        </form>
    </div>
</div>
<script nonce="<?= \App\Core\Csp::nonce() ?>">
(function () {
    var sel = document.getElementById('rule-event');
    if (!sel) { return; }
    function sync() {
        document.querySelectorAll('[data-rule-ev]').forEach(function (el) { el.classList.toggle('d-none', el.getAttribute('data-rule-ev') !== sel.value); });
    }
    sel.addEventListener('change', sync); sync();
})();
</script>
<?php endif; ?>
<?php endif; ?>
