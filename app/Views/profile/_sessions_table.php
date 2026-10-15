<?php
/** Таблиця активних сесій. Змінні: $sessions, $currentHash, $admin (bool), $revokeBase. */
use App\Core\View;
use App\Models\UserSession;
?>
<table class="table table-sm align-middle bg-white">
    <thead>
        <tr><?php if ($admin): ?><th>Користувач</th><?php endif; ?><th>Пристрій</th><th>IP</th><th>Вхід</th><th>Остання активність</th><th></th></tr>
    </thead>
    <tbody>
    <?php if (empty($sessions)): ?>
        <tr><td colspan="<?= $admin ? 6 : 5 ?>" class="text-center text-muted">Активних сесій немає</td></tr>
    <?php else: foreach ($sessions as $s): $current = hash_equals($s['session_hash'], $currentHash); ?>
        <tr class="<?= $current ? 'table-success' : '' ?>">
            <?php if ($admin): ?>
                <td><?= View::e($s['full_name']) ?><div class="small text-muted"><?= View::e($s['email']) ?></div></td>
            <?php endif; ?>
            <td title="<?= View::e($s['user_agent'] ?? '') ?>"><?= View::e(UserSession::describe($s['user_agent'])) ?>
                <?php if ($current): ?><span class="badge bg-success">ця сесія</span><?php endif; ?></td>
            <td><code><?= View::e($s['ip'] ?? '—') ?></code></td>
            <td><?= View::e(date('d.m.Y H:i', strtotime($s['created_at']))) ?></td>
            <td><?= View::e(date('d.m.Y H:i', strtotime($s['last_seen_at']))) ?></td>
            <td class="text-end">
                <?php if (!$current): ?>
                    <form method="post" action="<?= View::e($revokeBase . (int) $s['id'] . '/revoke') ?>" data-confirm="Завершити цю сесію? Користувача буде виведено з системи.">
                        <?= \App\Core\Csrf::field() ?>
                        <button class="btn btn-sm btn-outline-danger">Завершити</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
