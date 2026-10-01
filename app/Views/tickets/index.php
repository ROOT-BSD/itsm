<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">Тікети</h3>
    <a href="/tickets/create" class="btn btn-primary">+ Нове звернення</a>
</div>

<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr><th>#</th><th>Тема</th><th>Черга</th><th>Проєкт</th><th>Заявник</th><th>Оператор</th><th>Статус</th><th>SLA</th></tr>
    </thead>
    <tbody>
    <?php if (empty($tickets)): ?>
        <tr><td colspan="8" class="text-center text-muted">Звернень ще немає</td></tr>
    <?php else: foreach ($tickets as $t): ?>
        <tr>
            <td><a href="/tickets/<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
            <td><?= \App\Core\View::e($t['subject']) ?></td>
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
            <td><span class="badge bg-secondary"><?= \App\Core\View::e($t['status']) ?></span></td>
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
