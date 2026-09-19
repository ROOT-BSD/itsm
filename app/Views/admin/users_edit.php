<div class="mb-3">
    <a href="/admin/users" class="text-decoration-none">&larr; Користувачі</a>
</div>

<h3 class="mb-4">Редагування: <?= \App\Core\View::e($targetUser['full_name']) ?></h3>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>

<?php if ($targetUser['auth_source'] !== 'local'): ?>
    <div class="alert alert-info small">
        Цей обліковий запис автентифікується через Active Directory. Ім'я, email та роль
        синхронізуються з AD автоматично (Епік 13) — ручні зміни тут можуть бути перезаписані
        при наступній синхронізації.
    </div>
<?php endif; ?>

<form method="post" action="/admin/users/<?= (int)$targetUser['id'] ?>" class="card p-4 shadow-sm" style="max-width:500px">
    <div class="mb-3">
        <label class="form-label">Повне ім'я</label>
        <input type="text" name="full_name" class="form-control" value="<?= \App\Core\View::e($targetUser['full_name']) ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="<?= \App\Core\View::e($targetUser['email']) ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label">Роль</label>
        <select name="role_id" class="form-select" required <?= (int)$targetUser['id'] === \App\Core\Auth::id() ? 'disabled' : '' ?>>
            <?php foreach ($roles as $role): ?>
                <option value="<?= (int)$role['id'] ?>" <?= (int)$role['id'] === (int)$targetUser['role_id'] ? 'selected' : '' ?>>
                    <?= \App\Core\View::e($role['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ((int)$targetUser['id'] === \App\Core\Auth::id()): ?>
            <div class="form-text text-warning">Не можна змінити власну роль.</div>
            <input type="hidden" name="role_id" value="<?= (int)$targetUser['role_id'] ?>">
        <?php endif; ?>
    </div>
    <button type="submit" class="btn btn-primary">Зберегти зміни</button>
</form>
