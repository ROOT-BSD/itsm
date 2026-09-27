<div class="mb-3">
    <a href="/admin/users" class="text-decoration-none">&larr; Користувачі</a>
</div>

<h3 class="mb-4">Новий користувач</h3>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>

<form method="post" action="/admin/users" class="card p-4 shadow-sm form-card-sm">
    <?= \App\Core\Csrf::field() ?>
    <div class="mb-3">
        <label class="form-label">Повне ім'я</label>
        <input type="text" name="full_name" class="form-control" required autofocus>
    </div>
    <div class="mb-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required>
    </div>
    <div class="mb-3">
        <label class="form-label">Пароль</label>
        <input type="password" name="password" class="form-control" minlength="8" required>
        <div class="form-text">Мінімум 8 символів. Обліковий запис створюється як локальний.</div>
    </div>
    <div class="mb-3">
        <label class="form-label">Роль</label>
        <select name="role_id" class="form-select" required>
            <option value="">— оберіть роль —</option>
            <?php foreach ($roles as $role): ?>
                <option value="<?= (int)$role['id'] ?>"><?= \App\Core\View::e($role['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Створити користувача</button>
</form>
