<?php
use App\Core\View;
use App\Models\LoginLog;
?>
<div class="mb-3"><a href="/profile" class="text-decoration-none">&larr; Мій профіль</a></div>
<h3 class="mb-4">Активні сесії та історія входів</h3>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= View::e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= View::e($success) ?></div><?php endif; ?>

<?php if (!$ready): ?>
    <div class="alert alert-warning">Функція ще не активована: адміністратору потрібно застосувати міграцію 040 (<code>sudo bash update.sh</code>).</div>
<?php else: ?>
    <p class="text-muted small">Тут показано пристрої, з яких виконано вхід у ваш обліковий запис. Якщо бачите незнайому сесію — завершіть її й змініть пароль
        (зміна пароля автоматично завершує всі інші сесії).</p>
    <?php $admin = false; $revokeBase = '/profile/sessions/'; require __DIR__ . '/_sessions_table.php'; ?>
    <?php if (count($sessions) > 1): ?>
        <form method="post" action="/profile/sessions/revoke-others" class="mb-4" data-confirm="Завершити всі сесії, крім поточної?">
            <?= \App\Core\Csrf::field() ?>
            <button class="btn btn-outline-danger btn-sm">Завершити всі інші сесії</button>
        </form>
    <?php endif; ?>

    <h5 class="mt-4">Останні спроби входу</h5>
    <table class="table table-sm bg-white">
        <thead><tr><th>Коли</th><th>Результат</th><th>Спосіб</th><th>IP</th><th>Пристрій</th></tr></thead>
        <tbody>
        <?php if (empty($history)): ?>
            <tr><td colspan="5" class="text-center text-muted">Записів немає</td></tr>
        <?php else: foreach ($history as $h): ?>
            <tr class="<?= $h['success'] ? '' : 'table-warning' ?>">
                <td><?= View::e(date('d.m.Y H:i:s', strtotime($h['created_at']))) ?></td>
                <td><?= $h['success'] ? '✔ вхід' : '✖ ' . View::e(LoginLog::REASONS[$h['reason']] ?? 'відмова') ?></td>
                <td><?= View::e(LoginLog::METHODS[$h['method']] ?? $h['method']) ?></td>
                <td><code><?= View::e($h['ip'] ?? '—') ?></code></td>
                <td title="<?= View::e($h['user_agent'] ?? '') ?>"><?= View::e(\App\Models\UserSession::describe($h['user_agent'])) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
<?php endif; ?>
