<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">Тікети</h3>
    <div class="d-flex gap-2">
        <?php $exportUrl = '/tickets/export'; require __DIR__ . '/../_export_button.php'; ?>
        <?php if (\App\Core\Auth::hasRole(['admin', 'unit_admin', 'it_manager', 'support_operator'])): ?><a href="/ticket-rules" class="btn btn-outline-secondary">Правила черги</a><?php endif; ?>
        <a href="/tickets/create" class="btn btn-primary">+ Нове звернення</a>
    </div>
</div>

<form method="get" action="/tickets" class="row g-2 align-items-end mb-3">
    <?php if (!empty($_GET['queue'])): ?><input type="hidden" name="queue" value="<?= (int) $_GET['queue'] ?>"><?php endif; ?>
    <div class="col-auto"><label class="form-label small mb-0">Пріоритет</label>
        <select name="priority" class="form-select form-select-sm"><option value="">— усі —</option>
            <?php foreach (\App\Models\Ticket::PRIORITIES as $k => $l): ?><option value="<?= $k ?>" <?= ($fPrio ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><label class="form-label small mb-0">Статус</label>
        <select name="status" class="form-select form-select-sm"><option value="">— усі —</option>
            <?php foreach (\App\Models\Ticket::STATUS_LABELS as $k => $l): if ($k === 'closed') { continue; } ?><option value="<?= $k ?>" <?= ($fStatus ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <?php if (!empty($allLabels)): ?>
    <div class="col-auto"><label class="form-label small mb-0">Мітка</label>
        <select name="label" class="form-select form-select-sm"><option value="">— усі —</option>
            <?php foreach ($allLabels as $lb): ?><option value="<?= (int) $lb['id'] ?>" <?= (int) ($fLabel ?? 0) === (int) $lb['id'] ? 'selected' : '' ?>><?= \App\Core\View::e($lb['name']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="col-auto"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="sort" value="priority" id="sort-prio" <?= !empty($byPriority) ? 'checked' : '' ?>><label class="form-check-label small" for="sort-prio">спершу за пріоритетом</label></div></div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-primary" type="submit">Застосувати</button>
        <?php if (!empty($fPrio) || !empty($fStatus) || !empty($fLabel) || !empty($byPriority)): ?><a class="btn btn-sm btn-outline-secondary" href="/tickets">Скинути</a><?php endif; ?></div>
</form>

<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr><th>#</th><th>Тема</th><th>Черга</th><th>Проєкт</th><th>Заявник</th><th>Оператор</th><th>Статус</th><th>SLA</th></tr>
    </thead>
    <tbody>
    <?php if (empty($tickets)): ?>
        <tr><td colspan="8" class="text-center text-muted">Звернень ще немає</td></tr>
    <?php else:
        $__ids = array_map(fn($x) => (int) $x['id'], $tickets);
        $__dupOf = \App\Models\TicketLink::originalsFor($__ids);
        $__dupCnt = \App\Models\TicketLink::duplicateCounts($__ids);
        foreach ($tickets as $t): ?>
        <tr>
            <td><a href="/tickets/<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
            <td>
                <?= \App\Core\View::e($t['subject']) ?>
                <?php foreach ($labelMap[(int) $t['id']] ?? [] as $lb): ?><span class="badge text-bg-<?= \App\Core\View::e($lb['color']) ?>"><?= \App\Core\View::e($lb['name']) ?></span> <?php endforeach; ?>
                <?php if (isset($__dupOf[(int)$t['id']])): ?><a href="/tickets/<?= (int)$__dupOf[(int)$t['id']] ?>" class="badge bg-light text-dark border text-decoration-none">дубль #<?= (int)$__dupOf[(int)$t['id']] ?></a><?php endif; ?>
                <?php if (!empty($__dupCnt[(int)$t['id']])): ?><span class="badge bg-light text-dark border">дублів: <?= (int)$__dupCnt[(int)$t['id']] ?></span><?php endif; ?>
            </td>
            <td><?= \App\Core\View::e($t['queue_name']) ?></td>
            <td>
                <?php if (!empty($t['project_id'])): ?>
                    <a href="/projects/<?= (int)$t['project_id'] ?>"><?= \App\Core\View::e($t['project_name']) ?></a>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td><?= \App\Core\View::e($t['requester_name']) ?></td>
            <td>
                <?php if (empty($t['assigned_operator_id'])): ?>
                    <span class="badge bg-warning text-dark">Непризначено</span>
                <?php else: ?>
                    <?= \App\Core\View::e($t['operator_name']) ?>
                <?php endif; ?>
            </td>
            <td><span class="badge bg-secondary"><?= \App\Core\View::e(\App\Models\Ticket::statusLabel((string) $t['status'])) ?></span>
                <?php $pr = (string) ($t['priority'] ?? 'normal'); if ($pr !== 'normal'): ?>
                    <span class="badge <?= ['critical' => 'bg-danger', 'high' => 'bg-warning text-dark', 'low' => 'bg-light text-dark border'][$pr] ?? 'bg-secondary' ?>"><?= \App\Core\View::e(\App\Models\Ticket::priorityLabel($pr)) ?></span>
                <?php endif; ?></td>
            <td>
                <?php if ($t['sla']['response_overdue'] || $t['sla']['resolution_overdue']): ?>
                    <span class="badge bg-danger">⚠️ Прострочено</span>
                <?php elseif ($t['sla']['has_policy']): ?>
                    <span class="badge bg-success">У нормі</span>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
