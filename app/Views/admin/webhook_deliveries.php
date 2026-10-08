<?php
$e = static fn($v) => \App\Core\View::e($v);
$fmt = static fn(?string $d) => $d ? date('d.m.Y H:i:s', strtotime($d)) : '—';
$badge = ['success' => ['успішно', 'success'], 'pending' => ['очікує', 'warning'], 'failed' => ['невдало', 'danger']];
?>
<div class="mb-3"><a href="/admin/webhooks/<?= (int) $endpoint['id'] ?>" class="text-decoration-none">&larr; <?= $e($endpoint['name']) ?></a></div>
<h3 class="mb-1">Журнал доставок</h3>
<p class="text-muted"><code><?= $e($endpoint['url']) ?></code> · показано останні <?= count($deliveries) ?> (старші за 30 днів видаляються автоматично)</p>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>

<?php if (!$deliveries): ?>
    <div class="alert alert-light border">Доставок ще не було.</div>
<?php else: ?>
    <div class="table-responsive">
    <table class="table table-bordered bg-white align-middle small">
        <thead><tr><th>#</th><th>Час</th><th>Подія</th><th>Стан</th><th>Спроб</th><th>Код</th><th>Помилка / наступна спроба</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($deliveries as $d): [$label, $color] = $badge[$d['status']] ?? [$d['status'], 'secondary']; ?>
            <tr>
                <td><?= (int) $d['id'] ?></td>
                <td class="text-nowrap"><?= $e($fmt($d['created_at'])) ?></td>
                <td><code><?= $e($d['event']) ?></code><div class="text-muted" style="font-size:.75em"><?= $e(substr($d['event_id'], 0, 8)) ?>…</div></td>
                <td><span class="badge bg-<?= $color ?>"><?= $e($label) ?></span></td>
                <td><?= (int) $d['attempts'] ?></td>
                <td><?= $d['last_status_code'] ? (int) $d['last_status_code'] : '—' ?></td>
                <td><?= $d['last_error'] ? '<span class="text-danger">' . $e($d['last_error']) . '</span>' : '' ?>
                    <?= ($d['status'] === 'pending' && $d['next_attempt_at']) ? '<div>наступна: ' . $e($fmt($d['next_attempt_at'])) . '</div>' : '' ?></td>
                <td>
                    <?php if ($d['status'] !== 'success'): ?>
                        <form method="post" action="/admin/webhooks/deliveries/<?= (int) $d['id'] ?>/retry"><?= \App\Core\Csrf::field() ?><button type="submit" class="btn btn-sm btn-outline-primary">Повторити</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
