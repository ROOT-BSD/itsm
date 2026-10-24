<?php
$e = static fn($v) => \App\Core\View::e((string) $v);
$f = $edit ?? [];
$val = static fn(string $k, $d = '') => $f[$k] ?? $d;
$prio = \App\Models\Ticket::PRIORITIES;
?>
<div class="mb-3"><?php require __DIR__ . '/_back.php'; ?></div>
<h3 class="mb-1">Автоматичні правила для задач</h3>
<p class="text-muted">
    Правило спрацьовує на <strong>створення задачі</strong> (будь-яким шляхом, зокрема за шаблоном) або <strong>зміну її статусу</strong>, якщо виконуються всі задані умови: ключові слова в назві чи описі, проєкт, пріоритет, виконавець.
    Дії: призначити виконавця, встановити пріоритет, сповістити «в системі» (центр сповіщень 🔔). Правила перевіряються за порядком (менше число — раніше); <strong>перше, що збіглося, виконується</strong>.
</p>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>
<?php if (empty($ready)): ?>
    <div class="alert alert-warning">Таблицю правил ще не створено — виконайте <code>sudo bash update.sh</code> (міграція 055).</div>
<?php else: ?>
<table class="table table-bordered bg-white table-sm align-middle">
    <thead><tr><th>№</th><th>Правило</th><th>Подія й умова</th><th>Дії</th><th>Спрацювань</th><th>Стан</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rules): ?><tr><td colspan="7" class="text-center text-muted">Правил ще немає</td></tr>
    <?php else: foreach ($rules as $r):
        $kw = \App\Services\TicketRuleService::parseKeywords((string) $r['keywords']); ?>
        <tr class="<?= (int) $r['is_active'] ? '' : 'text-muted' ?>">
            <td><?= (int) $r['sort_order'] ?></td>
            <td><strong><?= $e($r['name']) ?></strong></td>
            <td class="small">
                <span class="badge text-bg-info"><?= $e($events[$r['trigger_event']] ?? $r['trigger_event']) ?></span><?= $r['when_status_name'] ? ' → «' . $e($r['when_status_name']) . '»' : '' ?>
                <?php if ($kw): ?><div><?= ['title' => 'у назві', 'description' => 'в описі', 'both' => 'у назві чи описі'][$r['match_in']] ?>: <?php foreach ($kw as $k): ?><span class="badge text-bg-light border"><?= $e($k) ?></span> <?php endforeach; ?></div><?php endif; ?>
                <?php if ($r['project_name']): ?><div>проєкт: <?= $e($r['project_name']) ?></div><?php endif; ?>
                <?php if ($r['cond_priority']): ?><div>пріоритет: <?= $e($prio[$r['cond_priority']]) ?></div><?php endif; ?>
                <?php if ($r['cond_assignee'] !== 'any'): ?><div>виконавець: <?= $r['cond_assignee'] === 'assigned' ? 'призначений' : 'не призначений' ?></div><?php endif; ?>
            </td>
            <td class="small"><?php $acts = [];
                if ($r['action_assignee_name']) { $acts[] = 'виконавець: ' . $r['action_assignee_name']; }
                if ($r['action_priority']) { $acts[] = 'пріоритет: ' . $prio[$r['action_priority']]; }
                if ((int) $r['notify_system_assignee']) { $acts[] = 'в системі: виконавцю'; }
                if ($r['notify_system_users']) { $acts[] = 'в системі: працівників ' . count(array_filter(explode(',', $r['notify_system_users']))); }
                echo $e(implode('; ', $acts)); ?></td>
            <td class="small"><?= (int) $r['hits'] ?><?= $r['last_hit_at'] ? '<div class="text-muted">' . $e(date('d.m.Y H:i', strtotime($r['last_hit_at']))) . '</div>' : '' ?></td>
            <td><?= (int) $r['is_active'] ? '<span class="badge text-bg-success">увімкнено</span>' : '<span class="badge text-bg-secondary">вимкнено</span>' ?></td>
            <td class="text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="/admin/task-rules?edit=<?= (int) $r['id'] ?>">Змінити</a>
                <form method="post" action="/admin/task-rules/<?= (int) $r['id'] ?>/toggle" class="d-inline"><?= \App\Core\Csrf::field() ?>
                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= (int) $r['is_active'] ? 'Вимкнути' : 'Увімкнути' ?></button></form>
                <form method="post" action="/admin/task-rules/<?= (int) $r['id'] ?>/delete" class="d-inline" data-confirm="Видалити правило «<?= $e($r['name']) ?>»?"><?= \App\Core\Csrf::field() ?>
                    <button class="btn btn-sm btn-outline-danger" type="submit">Видалити</button></form>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

<div class="card shadow-sm mt-4 form-card-md">
    <div class="card-header"><?= $edit ? 'Зміна правила' : 'Нове правило' ?></div>
    <div class="card-body">
        <form method="post" action="/admin/task-rules">
            <?= \App\Core\Csrf::field() ?>
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-md-8"><label class="form-label">Назва</label>
                    <input type="text" name="name" maxlength="150" class="form-control" value="<?= $e($val('name')) ?>" required></div>
                <div class="col-md-4"><label class="form-label">Порядок</label>
                    <input type="number" name="sort_order" min="1" max="9999" class="form-control" value="<?= (int) $val('sort_order', 100) ?>"></div>
                <div class="col-md-6"><label class="form-label">Коли спрацьовує (подія)</label>
                    <select name="trigger_event" class="form-select"><?php foreach ($events as $k => $l): ?><option value="<?= $k ?>" <?= $val('trigger_event', 'created') === $k ? 'selected' : '' ?>><?= $e(ucfirst($l)) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Новий статус <span class="text-muted">(для зміни статусу; необов'язково)</span></label>
                    <select name="when_status_id" class="form-select"><option value="">— будь-який —</option>
                        <?php foreach ($statuses as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $val('when_status_id', 0) === (int) $s['id'] ? 'selected' : '' ?>><?= $e($s['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><label class="form-label">Ключові слова</label>
                    <textarea name="keywords" rows="2" class="form-control" placeholder="сервер, резервне копіювання"><?= $e(implode("\n", \App\Services\TicketRuleService::parseKeywords((string) $val('keywords')))) ?></textarea>
                    <div class="form-text">Через кому чи з нового рядка; регістр не враховується.</div></div>
                <div class="col-md-6"><label class="form-label">Де шукати</label>
                    <select name="match_in" class="form-select"><?php foreach (['both' => 'У назві та описі', 'title' => 'Лише в назві', 'description' => 'Лише в описі'] as $k => $l): ?><option value="<?= $k ?>" <?= $val('match_in', 'both') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Проєкт <span class="text-muted">(необов'язково)</span></label>
                    <select name="project_id" class="form-select"><option value="">— будь-який —</option>
                        <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $val('project_id', 0) === (int) $p['id'] ? 'selected' : '' ?>><?= $e($p['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Пріоритет задачі <span class="text-muted">(умова)</span></label>
                    <select name="cond_priority" class="form-select"><option value="">— будь-який —</option>
                        <?php foreach ($prio as $k => $l): ?><option value="<?= $k ?>" <?= $val('cond_priority', '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Виконавець <span class="text-muted">(умова)</span></label>
                    <select name="cond_assignee" class="form-select"><?php foreach (['any' => 'Не важливо', 'unassigned' => 'Не призначений', 'assigned' => 'Призначений'] as $k => $l): ?><option value="<?= $k ?>" <?= $val('cond_assignee', 'any') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><hr class="my-1"><strong>Дії</strong> <span class="text-muted small">(потрібна хоча б одна)</span></div>
                <div class="col-md-6"><label class="form-label">Призначити виконавця</label>
                    <select name="action_assignee_id" class="form-select"><option value="">— не змінювати —</option>
                        <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $val('action_assignee_id', 0) === (int) $u['id'] ? 'selected' : '' ?>><?= $e($u['full_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Встановити пріоритет</label>
                    <select name="action_priority" class="form-select"><option value="">— не змінювати —</option>
                        <?php foreach ($prio as $k => $l): ?><option value="<?= $k ?>" <?= $val('action_priority', '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Сповістити «в системі» (🔔)</label>
                    <select name="notify_system_users[]" multiple size="5" class="form-select">
                        <?php $sel = array_filter(explode(',', (string) $val('notify_system_users', ''))); foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= in_array((string) $u['id'], $sel, true) ? 'selected' : '' ?>><?= $e($u['full_name']) ?></option><?php endforeach; ?></select>
                    <div class="form-text">Ctrl/Cmd + клік — кілька працівників.</div></div>
                <div class="col-md-6 pt-4"><div class="form-check">
                    <input class="form-check-input" type="checkbox" name="notify_system_assignee" id="nsa" value="1" <?= (int) $val('notify_system_assignee', 0) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="nsa">і виконавцю задачі — в системі</label></div></div>
                <div class="col-12"><div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_active" id="ia" value="1" <?= (int) $val('is_active', 1) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ia">Правило увімкнено</label></div></div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Зберегти</button>
                <?php if ($edit): ?><a class="btn btn-outline-secondary" href="/admin/task-rules">Скасувати</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
