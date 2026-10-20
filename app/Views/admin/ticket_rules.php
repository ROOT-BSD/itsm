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
    Правило спрацьовує при <strong>створенні</strong> тікета (форма, портал, пошта, API), якщо в темі чи описі є одне з ключових слів.
    Дії: перенести в іншу чергу, призначити оператора, надіслати сповіщення. Правила перевіряються за порядком (менше число — раніше);
    <strong>перше, що збіглося, виконується</strong>, і перевірка зупиняється.
    <?php if (!empty($readOnly)): ?><br><strong>Лише перегляд:</strong> правила створює й змінює тільки адміністратор системи. Нижче — правила, налаштовані на вас<?= !empty($unitMode) ? ' та працівників вашого підрозділу' : '' ?>.<?php endif; ?>
</p>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>
<?php if (empty($ready)): ?>
    <div class="alert alert-warning">Таблицю правил ще не створено — виконайте <code>sudo bash update.sh</code> (міграція 046).</div>
<?php else: ?>

<table class="table table-bordered bg-white table-sm align-middle">
    <thead><tr><th>№</th><th>Правило</th><th>Умова</th><th>Дії</th><th>Спрацювань</th><th>Стан</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rules): ?>
        <tr><td colspan="7" class="text-center text-muted"><?= !empty($readOnly) ? 'На вас правил не налаштовано' : 'Правил ще немає' ?></td></tr>
    <?php else: foreach ($rules as $r):
        $kw = \App\Services\TicketRuleService::parseKeywords((string) $r['keywords']);
        $where = ['subject' => 'у темі', 'description' => 'в описі', 'both' => 'у темі чи описі'][$r['match_in']];
        ?>
        <tr class="<?= (int) $r['is_active'] ? '' : 'text-muted' ?>">
            <td><?= (int) $r['sort_order'] ?></td>
            <td>
                <strong><?= $e($r['name']) ?></strong>
                <?php if (empty($unitMode)): ?><div class="small text-muted"><?= $r['scope_user_id'] === null ? 'Глобальне' : 'Підрозділ: ' . $e($r['scope_name']) ?></div><?php endif; ?>
            </td>
            <td class="small">
                <?= $where ?>: <?php foreach ($kw as $k): ?><span class="badge text-bg-light border"><?= $e($k) ?></span> <?php endforeach; ?>
                <?php if ($r['from_queue_name']): ?><div>лише в черзі «<?= $e($r['from_queue_name']) ?>»</div><?php endif; ?>
            </td>
            <td class="small">
                <?php $acts = [];
                if ($r['action_queue_name']) { $acts[] = 'у чергу «' . $r['action_queue_name'] . '»'; }
                if ($r['action_operator_name']) { $acts[] = 'оператор: ' . $r['action_operator_name']; }
                if ($r['notify_emails']) { $acts[] = 'сповістити: ' . $r['notify_emails']; }
                if ((int) $r['notify_operator']) { $acts[] = 'сповістити оператора'; }
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
                <div class="col-12">
                    <label class="form-label">Ключові слова</label>
                    <textarea name="keywords" rows="3" class="form-control" placeholder="принтер, друк, картридж" required><?= $e(implode("\n", \App\Services\TicketRuleService::parseKeywords((string) $val('keywords')))) ?></textarea>
                    <div class="form-text">Через кому чи з нового рядка; регістр не враховується; достатньо збігу з будь-яким словом (частина слова теж збігається).</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Де шукати</label>
                    <select name="match_in" class="form-select">
                        <?php foreach (['both' => 'У темі та описі', 'subject' => 'Лише в темі', 'description' => 'Лише в описі'] as $k => $l): ?>
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
<?php endif; ?>
<?php endif; ?>
