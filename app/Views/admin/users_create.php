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
    <?php if (!empty($unitReady)): ?>
    <div class="mb-3">
        <label class="form-label">Підрозділ <span class="text-muted small">(необов'язково)</span></label>
        <input type="text" name="unit_ou" class="form-control" list="unitOus" placeholder="Київ › ІТ" value="">
        <datalist id="unitOus">
            <?php foreach ($knownOus as $ouPath): ?>
                <option value="<?= \App\Core\View::e(\App\Core\LdapDn::ouLabel($ouPath)) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <div class="form-text">Від найбільшого підрозділу до найменшого через «›»: <code>Київ › ІТ</code>. Той самий запис, що й у AD, ставить локального користувача в один підрозділ з AD-користувачами. Потрібен для ролі «Адміністратор Підрозділу» та щоб його бачив адміністратор підрозділу.</div>
    </div>
    <?php endif; ?>
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
