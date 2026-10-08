<div class="mb-3">
    <?php require __DIR__ . '/_back.php'; ?>
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

<?php
/** Одна таблиця користувачів — та сама розмітка для розділу «Локальні» і для кожного OU Active Directory. */
$renderUserTable = function (array $users) {
    ?>
    <table class="table table-bordered bg-white align-middle mb-4">
        <thead>
            <tr><th>Ім'я</th><th>Email</th><th>Роль</th><th>Статус</th><th class="col-min-280">Змінити пароль</th><th>Дії</th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td>
                    <?= \App\Core\View::e($u['full_name']) ?>
                    <?php if ($u['auth_source'] === 'local' && !empty($u['unit_ou'])): ?>
                        <br><span class="text-muted small">Підрозділ: <?= \App\Core\View::e(\App\Core\LdapDn::ouLabel((string)$u['unit_ou'])) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= \App\Core\View::e($u['email']) ?></td>
                <td>
                    <?= \App\Core\View::e($u['role_name']) ?>
                    <?php if (!empty($u['ad_role_locked'])): ?>
                        <br><span class="badge bg-warning text-dark mt-1" title="Роль задано вручну — синхронізація з AD її не змінює">Роль закріплено</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((int)$u['is_active'] === 1): ?>
                        <span class="badge bg-success">Активний</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Деактивовано</span>
                        <?php if (!empty($u['ad_blocked'])): ?>
                            <br><span class="badge bg-warning text-dark mt-1" title="Вимкнено вручну — синхронізація з AD не вмикає користувача знову">Вимкнено вручну</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($u['locked_until']) && strtotime($u['locked_until']) > time()): ?>
                        <br><span class="badge bg-danger mt-1">Заблоковано до <?= \App\Core\View::e(date('H:i d.m', strtotime($u['locked_until']))) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($u['auth_source'] === 'local'): ?>
                    <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/password" class="d-flex gap-1"
                          data-confirm="Змінити пароль для <?= \App\Core\View::e($u['email']) ?>?">
        <?= \App\Core\Csrf::field() ?>
                        <input type="password" name="new_password" class="form-control form-control-sm" placeholder="Новий пароль" minlength="8" required>
                        <input type="password" name="confirm_password" class="form-control form-control-sm" placeholder="Повтор" minlength="8" required>
                        <button type="submit" class="btn btn-sm btn-primary text-nowrap">
                            Змінити
                        </button>
                    </form>
                    <?php else: ?>
                        <span class="text-muted small">Керується через AD</span>
                    <?php endif; ?>
                </td>
                <td class="text-nowrap">
                    <a href="/admin/users/<?= (int)$u['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">Редагувати</a>

                    <?php if (!empty($u['locked_until']) && strtotime($u['locked_until']) > time()): ?>
                    <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/unlock" class="d-inline">
        <?= \App\Core\Csrf::field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">Зняти блокування</button>
                    </form>
                    <?php endif; ?>

                    <?php if ((int)$u['id'] !== \App\Core\Auth::id()): ?>
                    <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/active" class="d-inline"
                          <?= (int)$u['is_active'] === 1 ? 'data-confirm="Деактивувати обліковий запис?"' : '' ?>>
        <?= \App\Core\Csrf::field() ?>
                        <?php if ((int)$u['is_active'] === 1): ?>
                            <input type="hidden" name="active" value="0">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Деактивувати</button>
                        <?php else: ?>
                            <input type="hidden" name="active" value="1">
                            <button type="submit" class="btn btn-sm btn-outline-success">Активувати</button>
                        <?php endif; ?>
                    </form>
                    <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/delete" class="d-inline"
                          data-confirm="Видалити користувача «<?= \App\Core\View::e($u['full_name']) ?>» назавжди? Це можливо лише якщо з ним не пов’язані жодні дані.">
        <?= \App\Core\Csrf::field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">
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
    <?php
};
?>

<h5>Локальні облікові записи</h5>
<?php if (empty($localUsers)): ?>
    <div class="alert alert-light border mb-4">Локальних облікових записів немає.</div>
<?php else: ?>
    <?php $renderUserTable($localUsers); ?>
<?php endif; ?>

<h5 class="mt-4">Active Directory</h5>
<?php if (empty($adGroups)): ?>
    <div class="alert alert-light border mb-4">
        AD-облікових записів ще немає — див. <a href="/admin/ad">Адмін-панель → Налаштування → Active Directory</a>, щоб налаштувати підключення й синхронізацію.
    </div>
<?php else: foreach ($adGroups as $group): ?>
    <h6 class="text-muted mt-3"><?= $group['label'] === '' ? '— поза OU (напр., стандартний контейнер Users) —' : \App\Core\View::e($group['label']) ?></h6>
    <?php $renderUserTable($group['users']); ?>
<?php endforeach; endif; ?>
