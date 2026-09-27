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
