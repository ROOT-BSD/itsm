<div class="mb-3">
    <a href="/admin" class="text-decoration-none">&larr; Адмін-панель</a>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">Користувачі</h3>
    <a href="/admin/users/create" class="btn btn-primary">+ Новий користувач</a>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= \App\Core\View::e($success) ?></div>
<?php endif; ?>

<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr>
            <th>Ім'я</th><th>Email</th><th>Роль</th><th>Джерело</th><th>Статус</th>
            <th style="min-width:280px">Змінити пароль</th><th>Дії</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><?= \App\Core\View::e($u['full_name']) ?></td>
            <td><?= \App\Core\View::e($u['email']) ?></td>
            <td><?= \App\Core\View::e($u['role_name']) ?></td>
            <td><?= \App\Core\View::e($u['auth_source']) ?></td>
            <td>
                <?php if ((int)$u['is_active'] === 1): ?>
                    <span class="badge bg-success">Активний</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Деактивовано</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($u['auth_source'] === 'local'): ?>
                <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/password" class="d-flex gap-1">
                    <input type="password" name="new_password" class="form-control form-control-sm" placeholder="Новий пароль" minlength="8" required>
                    <input type="password" name="confirm_password" class="form-control form-control-sm" placeholder="Повтор" minlength="8" required>
                    <button type="submit" class="btn btn-sm btn-primary text-nowrap"
                            onclick="return confirm('Змінити пароль для <?= \App\Core\View::e($u['email']) ?>?');">
                        Змінити
                    </button>
                </form>
                <?php else: ?>
                    <span class="text-muted small">Керується через AD</span>
                <?php endif; ?>
            </td>
            <td class="text-nowrap">
                <a href="/admin/users/<?= (int)$u['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">Редагувати</a>

                <?php if ((int)$u['id'] !== \App\Core\Auth::id()): ?>
                <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/active" class="d-inline">
                    <?php if ((int)$u['is_active'] === 1): ?>
                        <input type="hidden" name="active" value="0">
                        <button type="submit" class="btn btn-sm btn-outline-secondary"
                                onclick="return confirm('Деактивувати обліковий запис?');">Деактивувати</button>
                    <?php else: ?>
                        <input type="hidden" name="active" value="1">
                        <button type="submit" class="btn btn-sm btn-outline-success">Активувати</button>
                    <?php endif; ?>
                </form>
                <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/delete" class="d-inline">
                    <button type="submit" class="btn btn-sm btn-outline-danger"
                            onclick="return confirm('Видалити користувача «<?= \App\Core\View::e($u['full_name']) ?>» назавжди? Це можливо лише якщо з ним не пов’язані жодні дані.');">
                        Видалити
                    </button>
                </form>
                <?php else: ?>
                    <span class="text-muted small">(ви)</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
