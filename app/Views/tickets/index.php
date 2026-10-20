<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">Тікети</h3>
    <div class="d-flex gap-2">
        <?php $exportUrl = '/tickets/export'; require __DIR__ . '/../_export_button.php'; ?>
        <?php if (\App\Core\Auth::hasRole(['admin', 'unit_admin', 'it_manager', 'support_operator'])): ?><a href="/ticket-rules" class="btn btn-outline-secondary">Правила черги</a><?php endif; ?>
        <a href="/tickets/create" class="btn btn-primary">+ Нове звернення</a>
    </div>
</div>

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
            <td><span class="badge bg-secondary"><?= \App\Core\View::e(\App\Models\Ticket::statusLabel((string) $t['status'])) ?></span></td>
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
