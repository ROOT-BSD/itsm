<div class="mb-3">
    <a href="/admin/users" class="text-decoration-none">&larr; Користувачі</a>
</div>

<h3 class="mb-4">Редагування: <?= \App\Core\View::e($targetUser['full_name']) ?></h3>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>

<?php if ($targetUser['auth_source'] !== 'local'): ?>
    <div class="alert alert-info small">
        Цей обліковий запис автентифікується через Active Directory. Ім'я та email синхронізуються з AD
        автоматично — ручні зміни цих полів можуть бути перезаписані при наступній синхронізації.
        <?php if (\App\Models\User::overridesReady()): ?>
            Роль, навпаки, можна закріпити вручну (галочка нижче) — тоді синхронізація її не змінює.
            Деактивація кнопкою в списку користувачів теж закріплюється: синхронізація не вмикає такого користувача знову.
        <?php else: ?>
            Роль і деактивація також перезаписуються синхронізацією (щоб це змінити, застосуйте міграцію 027 — <code>update.sh</code>).
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" action="/admin/users/<?= (int)$targetUser['id'] ?>" class="card p-4 shadow-sm form-card-sm">
    <?= \App\Core\Csrf::field() ?>
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
    <?php if ($targetUser['auth_source'] === 'local'): ?>
    <?php if (!empty($unitReady)): ?>
    <div class="mb-3">
        <label class="form-label">Підрозділ <span class="text-muted small">(необов'язково)</span></label>
        <input type="text" name="unit_ou" class="form-control" list="unitOus" placeholder="Київ › ІТ" value="<?= \App\Core\View::e(\App\Core\LdapDn::ouLabel((string)($targetUser['unit_ou'] ?? ''))) ?>">
        <datalist id="unitOus">
            <?php foreach ($knownOus as $ouPath): ?>
                <option value="<?= \App\Core\View::e(\App\Core\LdapDn::ouLabel($ouPath)) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <div class="form-text">Від найбільшого підрозділу до найменшого через «›»: <code>Київ › ІТ</code>. Той самий запис, що й у AD, ставить локального користувача в один підрозділ з AD-користувачами. Потрібен для ролі «Адміністратор Підрозділу» та щоб його бачив адміністратор підрозділу.</div>
    </div>
    <?php endif; ?>
    <?php elseif (!empty($targetUser['ad_ou'])): ?>
    <div class="mb-3">
        <label class="form-label">Підрозділ (AD OU)</label>
        <input type="text" class="form-control" value="<?= \App\Core\View::e(\App\Core\LdapDn::ouLabel((string)$targetUser['ad_ou'])) ?>" disabled>
        <div class="form-text">Приходить із Active Directory при синхронізації — змінюється в AD.</div>
    </div>
    <?php endif; ?>
    <?php if ($targetUser['auth_source'] === 'ad' && \App\Models\User::overridesReady()): ?>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="ad_role_locked" value="1" id="adRoleLocked"
                   <?= !empty($targetUser['ad_role_locked']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="adRoleLocked">Закріпити роль (синхронізація з AD її не змінює)</label>
            <div class="form-text">Якщо змінити роль вище, її буде закріплено автоматично. Зніміть галочку й збережіть, щоб знову віддати роль на розсуд груп AD.</div>
        </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">Зберегти зміни</button>
</form>
