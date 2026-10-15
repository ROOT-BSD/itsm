<?php use App\Core\View; ?>
<div class="mb-3"><?php require __DIR__ . '/_back.php'; ?></div>
<h3 class="mb-2">Активні сесії</h3>
<p class="text-muted small">Сесія вважається активною, якщо вона не завершена й була запит за останні <?= (int) round(\App\Models\UserSession::idleWindow() / 60) ?> хв.
    Завершена сесія перестає діяти протягом ~<?= (int) \App\Models\UserSession::CHECK_INTERVAL ?> с. <a href="/admin/login-log">Журнал входів →</a></p>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= View::e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= View::e($success) ?></div><?php endif; ?>

<?php if (!$ready): ?>
    <div class="alert alert-warning">Потрібна міграція 040: виконайте <code>sudo bash update.sh</code>.</div>
<?php else: ?>
    <?php $admin = true; $revokeBase = '/admin/sessions/'; require __DIR__ . '/../profile/_sessions_table.php'; ?>
    <?php
    $byUser = [];
    foreach ($sessions as $s) { $byUser[(int) $s['user_id']] = $s['full_name']; }
    if (count($byUser) > 0): ?>
        <h6 class="mt-4">Завершити всі сесії користувача</h6>
        <div class="d-flex flex-wrap gap-2">
        <?php foreach ($byUser as $uid => $name): ?>
            <form method="post" action="/admin/sessions/user/<?= $uid ?>/revoke" data-confirm="Завершити всі сесії користувача «<?= View::e($name) ?>»?">
                <?= \App\Core\Csrf::field() ?>
                <button class="btn btn-sm btn-outline-secondary"><?= View::e($name) ?></button>
            </form>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
