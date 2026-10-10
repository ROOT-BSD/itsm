<div class="mb-3"><?php require __DIR__ . '/_nav.php'; ?></div>
<h3 class="mb-1">Користувачі підрозділу</h3>
<?php if ($ou === null): ?>
    <div class="alert alert-warning mt-3">
        Підрозділ не визначено: у вашого облікового запису немає підрозділу (AD OU або, для локального акаунта, підрозділу, який задає адміністратор системи).
        Роль «Адміністратор Підрозділу» діє лише в межах підрозділу — зверніться до адміністратора системи.
    </div>
<?php else: ?>
    <p class="text-muted">
        Підрозділ: <strong><?= \App\Core\View::e($ouLabel) ?></strong> (разом із вкладеними). Ви бачите й редагуєте проєкти, задачі й тікети людей цього підрозділу
        та керуєте їхніми обліковими записами (користувачі AD і локальні). Проєкти, канбан, Гант, облік часу, черги й CSAT підрозділу — в розділі «Керування», журнал аудиту — тут, у «Налаштуваннях системи». Глобальні налаштування системи, Active Directory й API вам недоступні.
    </p>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= \App\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if ($ou !== null && !$overridesReady): ?>
    <div class="alert alert-warning small">
        Для користувачів AD керування ролями й деактивацією вимкнено, доки адміністратор системи не виконає <code>update.sh</code> (міграція 027): без неї зміни
        скасовувалися б наступною синхронізацією з AD. Локальних користувачів це не стосується.
    </div>
<?php endif; ?>

<?php if ($ou !== null): ?>
    <?php if (empty($users)): ?>
        <div class="alert alert-light border">У підрозділі ще немає інших користувачів — вони з'являються після синхронізації з AD.</div>
    <?php else: ?>
    <table class="table table-bordered bg-white align-middle">
        <thead><tr><th>Ім'я</th><th>Email</th><th>Підрозділ</th><th class="col-min-280">Роль</th><th>Статус</th><th class="col-min-280">Дії</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u):
            $protected = in_array($u['role_code'], $protectedRoles, true);
            $locked = !empty($u['locked_until']) && strtotime($u['locked_until']) > time();
            $isAd = $u['auth_source'] === 'ad';
            $canChange = !$isAd || $overridesReady;   // зміни користувача AD закріплюються прапорцями міграції 027
        ?>
            <tr>
                <td>
                    <?= \App\Core\View::e($u['full_name']) ?>
                    <br><span class="badge <?= $isAd ? 'bg-info text-dark' : 'bg-light text-dark border' ?> mt-1"><?= $isAd ? 'Active Directory' : 'Локальний' ?></span>
                </td>
                <td><?= \App\Core\View::e($u['email']) ?></td>
                <td class="small"><?= \App\Core\View::e(\App\Core\LdapDn::ouLabel((string)($u['unit_path'] ?? ''))) ?></td>
                <td>
                    <?php if ($protected): ?>
                        <?= \App\Core\View::e($u['role_name']) ?> <span class="text-muted small">(змінює лише адміністратор системи)</span>
                    <?php else: ?>
                    <form method="post" action="/unit/users/<?= (int)$u['id'] ?>/role" class="d-flex gap-1">
                        <?= \App\Core\Csrf::field() ?>
                        <select name="role_id" class="form-select form-select-sm" <?= $canChange ? '' : 'disabled' ?>>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= (int)$r['id'] ?>" <?= (int)$r['id'] === (int)$u['role_id'] ? 'selected' : '' ?>><?= \App\Core\View::e($r['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary" <?= $canChange ? '' : 'disabled' ?>>Змінити</button>
                    </form>
                    <?php if (!empty($u['ad_role_locked'])): ?>
                        <span class="badge bg-warning text-dark mt-1" title="Роль задано вручну — синхронізація з AD її не змінює">Роль закріплено</span>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((int)$u['is_active'] === 1): ?>
                        <span class="badge bg-success">Активний</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Деактивовано</span>
                        <?php if (!empty($u['ad_blocked'])): ?><br><span class="badge bg-warning text-dark mt-1">Вимкнено вручну</span><?php endif; ?>
                    <?php endif; ?>
                    <?php if ($locked): ?>
                        <br><span class="badge bg-danger mt-1">Заблоковано до <?= \App\Core\View::e(date('H:i d.m', strtotime($u['locked_until']))) ?></span>
                    <?php endif; ?>
                </td>
                <td class="text-nowrap">
                    <?php if (!$protected): ?>
                        <?php if ($locked): ?>
                        <form method="post" action="/unit/users/<?= (int)$u['id'] ?>/unlock" class="d-inline">
                            <?= \App\Core\Csrf::field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger">Зняти блокування</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" action="/unit/users/<?= (int)$u['id'] ?>/active" class="d-inline"
                              <?= (int)$u['is_active'] === 1 ? 'data-confirm="Деактивувати обліковий запис?"' : '' ?>>
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="active" value="<?= (int)$u['is_active'] === 1 ? '0' : '1' ?>">
                            <button type="submit" class="btn btn-sm <?= (int)$u['is_active'] === 1 ? 'btn-outline-secondary' : 'btn-outline-success' ?>" <?= $canChange ? '' : 'disabled' ?>>
                                <?= (int)$u['is_active'] === 1 ? 'Деактивувати' : 'Активувати' ?>
                            </button>
                        </form>
                        <?php if (!$isAd): ?>
                        <form method="post" action="/unit/users/<?= (int)$u['id'] ?>/password" class="d-flex gap-1 mt-2"
                              data-confirm="Змінити пароль для <?= \App\Core\View::e($u['email']) ?>?">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="password" name="new_password" class="form-control form-control-sm" placeholder="Новий пароль" minlength="8" required autocomplete="new-password">
                            <input type="password" name="confirm_password" class="form-control form-control-sm" placeholder="Повтор" minlength="8" required autocomplete="new-password">
                            <button type="submit" class="btn btn-sm btn-primary text-nowrap">Пароль</button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
<?php endif; ?>
