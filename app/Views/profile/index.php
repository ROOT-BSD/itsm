<div class="mb-3">
    <a href="/" class="text-decoration-none">&larr; Дашборд</a>
</div>

<h3 class="mb-4">Мій профіль</h3>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= \App\Core\View::e($success) ?></div>
<?php endif; ?>

<div class="card mb-4 form-card-md">
    <div class="card-header">Дані облікового запису</div>
    <table class="table table-sm mb-0">
        <tr><th class="profile-table-label">Ім'я</th><td><?= \App\Core\View::e($user['full_name']) ?></td></tr>
        <tr><th>Email</th><td><?= \App\Core\View::e($user['email']) ?></td></tr>
        <tr><th>Роль</th><td><?= \App\Core\View::e($user['role_name']) ?></td></tr>
        <tr><th>Автентифікація</th><td><?= $user['auth_source'] === 'local' ? 'Локальний обліковий запис' : 'Active Directory' ?></td></tr>
    </table>
</div>

<div class="card form-card-md">
    <div class="card-header">Зміна пароля</div>
    <div class="card-body">
        <?php if ($user['auth_source'] !== 'local'): ?>
            <p class="text-muted mb-0">Цей обліковий запис автентифікується через Active Directory — пароль змінюється там, а не тут.</p>
        <?php else: ?>
            <form method="post" action="/profile/password">
                <?= \App\Core\Csrf::field() ?>
                <div class="mb-3">
                    <label class="form-label">Поточний пароль</label>
                    <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                </div>
                <div class="mb-3">
                    <label class="form-label">Новий пароль</label>
                    <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
                    <div class="form-text">Щонайменше 8 символів.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Підтвердження нового пароля</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary">Змінити пароль</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($apiEnabled)): ?>
<div class="card mt-4" id="api-tokens">
    <div class="card-header">🔌 Токени доступу до API</div>
    <div class="card-body">
        <?php if (!empty($newToken)): ?>
            <div class="alert alert-success border-success">
                <strong>Новий токен «<?= \App\Core\View::e($newToken['name']) ?>» — скопіюйте його зараз.</strong>
                У системі лишається лише його хеш: відновити токен потім неможливо.
                <input type="text" class="form-control font-monospace mt-2" readonly value="<?= \App\Core\View::e($newToken['token']) ?>" onclick="this.select()">
            </div>
        <?php endif; ?>
        <p class="text-muted small">Токен дозволяє програмі працювати в системі <strong>від вашого імені</strong>, з вашими правами й видимістю.
            Не передавайте його нікому й не публікуйте в репозиторіях; якщо токен міг потрапити до сторонніх — відкличте його.</p>
        <?php if (!empty($apiTokens)): ?>
            <table class="table table-sm align-middle">
                <thead><tr><th>Назва</th><th>Токен</th><th>Права</th><th>Востаннє</th><th>Діє до</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($apiTokens as $t): $dead = $t['revoked_at'] || ($t['expires_at'] && strtotime($t['expires_at']) < time()); ?>
                    <tr class="<?= $dead ? 'text-muted' : '' ?>">
                        <td><?= \App\Core\View::e($t['name']) ?></td>
                        <td><code><?= \App\Core\View::e($t['token_prefix']) ?>…</code></td>
                        <td><?= $t['scope'] === 'write' ? 'запис' : 'читання' ?></td>
                        <td><?= $t['last_used_at'] ? \App\Core\View::e(date('d.m.Y H:i', strtotime($t['last_used_at']))) : '—' ?></td>
                        <td><?= $t['revoked_at'] ? 'відкликаний' : ($t['expires_at'] ? \App\Core\View::e(date('d.m.Y', strtotime($t['expires_at']))) . (strtotime($t['expires_at']) < time() ? ' (прострочений)' : '') : 'без обмеження') ?></td>
                        <td>
                            <?php if (!$t['revoked_at']): ?>
                                <form method="post" action="/profile/api-tokens/<?= (int) $t['id'] ?>/revoke" data-confirm="Відкликати токен «<?= \App\Core\View::e($t['name']) ?>»? Програма, що ним користується, перестане працювати.">
                                    <?= \App\Core\Csrf::field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Відкликати</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <form method="post" action="/profile/api-tokens" class="row g-2 align-items-end">
            <?= \App\Core\Csrf::field() ?>
            <div class="col-md-4"><label class="form-label small mb-1">Назва</label><input type="text" name="name" class="form-control" maxlength="100" placeholder="Мій скрипт" required></div>
            <div class="col-md-3"><label class="form-label small mb-1">Права</label><select name="scope" class="form-select"><option value="read">Лише читання</option><option value="write">Читання й запис</option></select></div>
            <div class="col-md-3"><label class="form-label small mb-1">Термін дії</label><select name="expires_days" class="form-select"><option value="30">30 днів</option><option value="90" selected>90 днів</option><option value="365">1 рік</option><option value="0">Без обмеження</option></select></div>
            <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Створити</button></div>
        </form>
        <div class="form-text mt-2">Приклад: <code>curl -H "Authorization: Bearer ТОКЕН" <?= \App\Core\View::e($appUrl) ?>/api/v1/me</code></div>
    </div>
</div>
<?php endif; ?>
