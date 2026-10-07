<div class="mb-3">
    <?php require __DIR__ . '/_back.php'; ?>
</div>

<h3 class="mb-2">Active Directory</h3>
<p class="text-muted">
    Користувачі з auth_source «ad» входять під своїм паролем AD (система перевіряє його напряму в каталозі,
    не зберігає копію). Синхронізація створює/оновлює/деактивує такі облікові записи за даними каталогу —
    локальні облікові записи вона ніколи не чіпає, навіть якщо email збігається.
</p>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= \App\Core\View::e($success) ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Підключення до каталогу</span>
                <?php if ($configured): ?>
                    <span class="badge bg-success">налаштовано</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark">не налаштовано</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($configured): ?>
                    <table class="table table-sm mb-3">
                        <tr><th class="email-table-label">Сервер</th><td><?= \App\Core\View::e($ad['host']) ?>:<?= \App\Core\View::e((string)$ad['port']) ?></td></tr>
                        <tr><th>Шифрування</th><td><?= \App\Core\View::e(['ldaps' => 'LDAPS', 'starttls' => 'STARTTLS', 'none' => 'без шифрування'][$ad['encryption']] ?? $ad['encryption']) ?></td></tr>
                        <tr><th>Базовий DN</th><td><?= \App\Core\View::e($ad['base_dn']) ?></td></tr>
                        <tr><th>Службовий акаунт</th><td><?= \App\Core\View::e($ad['bind_dn']) ?></td></tr>
                        <tr><th>Перевірка сертифіката</th><td><?= $ad['verify_cert'] ? 'так' : '<span class="text-warning">вимкнена</span>' ?></td></tr>
                    </table>
                    <form method="post" action="/admin/ad/test">
                        <?= \App\Core\Csrf::field() ?>
                        <button type="submit" class="btn btn-outline-primary btn-sm">Перевірити з'єднання</button>
                    </form>
                    <hr>
                <?php endif; ?>

                <p class="small text-muted">
                    <?= $configured ? 'Змінити параметри нижче — форма записує прямо у файл .env на сервері.' : 'Заповніть форму нижче — вона сама збереже параметри у файл .env на сервері (без ручного редагування).' ?>
                </p>
                <form method="post" action="/admin/ad/connection">
                    <?= \App\Core\Csrf::field() ?>
                    <div class="mb-2">
                        <label class="form-label small mb-1">Сервер (DNS-ім'я або IP контролера домену)</label>
                        <input type="text" name="ad_host" class="form-control form-control-sm" placeholder="dc01.company.local" value="<?= \App\Core\View::e($form['host']) ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">Домен Active Directory</label>
                        <input type="text" name="ad_base_dn" class="form-control form-control-sm" placeholder="hest.org.ua" value="<?= \App\Core\View::e($form['base_dn']) ?>" required>
                        <div class="form-text">Просто назва домену — система сама перетворить її на потрібний для пошуку формат. Якщо вже знаєте LDAP-нотацію (<code>dc=hest,dc=org,dc=ua</code>) — можна і так, теж спрацює.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">Користувач (службовий обліковий запис — лише для читання каталогу, не адміністратор домену)</label>
                        <input type="text" name="ad_bind_dn" class="form-control form-control-sm" placeholder="itsm@hest.org.ua" value="<?= \App\Core\View::e($form['bind_dn']) ?>" required>
                        <div class="form-text">Формат <code>логін@домен</code> (як при вході у Windows) або повний DN — обидва варіанти приймає Active Directory.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small mb-1">Пароль</label>
                        <input type="password" name="ad_bind_password" class="form-control form-control-sm" placeholder="<?= $form['has_password'] ? 'уже задано — залиште порожнім, щоб не змінювати' : '' ?>" autocomplete="new-password">
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="ad_encrypted" id="ad_encrypted" value="1" <?= $form['encrypted'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ad_encrypted">Шифрування (STARTTLS, порт 389)</label>
                        <div class="form-text">Потрібен LDAPS або нестандартний порт — налаштуйте <code>AD_ENCRYPTION</code>/<code>AD_PORT</code> у .env вручну, ця галочка розрахована на типовий STARTTLS.</div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="ad_verify_cert" id="ad_verify_cert" value="1" <?= $form['verify_cert'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ad_verify_cert">Перевіряти сертифікат сервера</label>
                        <div class="form-text">Вимкніть лише для внутрішнього сервера з самопідписаним сертифікатом.</div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Зберегти</button>
                </form>
                <p class="small text-muted mt-3 mb-0">
                    Атрибути пошуку (типові значення вже відповідають реальному Active Directory) — <code>AD_USER_FILTER</code>, <code>AD_SYNC_FILTER</code>,
                    <code>AD_USERNAME_ATTR</code>, <code>AD_NAME_ATTR</code>, <code>AD_EMAIL_ATTR</code>, <code>AD_GROUP_ATTR</code>, <code>AD_DEFAULT_ROLE</code> —
                    за потреби змінюються лише вручну в <code>.env</code>, ця форма їх не чіпає.
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header">Синхронізація користувачів</div>
            <div class="card-body">
                <form method="post" action="/admin/ad" class="mb-3">
                    <?= \App\Core\Csrf::field() ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="sync_enabled" id="sync_enabled" value="1" <?= $syncEnabled ? 'checked' : '' ?> <?= $configured ? '' : 'disabled' ?>>
                        <label class="form-check-label" for="sync_enabled">Синхронізувати користувачів з AD</label>
                        <?php if (!$configured): ?>
                            <div class="form-text text-warning">Спершу налаштуйте підключення ліворуч.</div>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Зберегти</button>
                </form>

                <hr>
                <p class="mb-1"><strong>Користувачів з AD зараз у системі:</strong> <?= (int)$adUsersCount ?></p>
                <p class="mb-1">
                    <strong>Останній запуск:</strong>
                    <?= $lastSyncAt ? \App\Core\View::e($lastSyncAt) : '<span class="text-muted">ще не було</span>' ?>
                </p>
                <?php if ($lastSyncSummary): ?>
                    <p class="small text-muted mb-2"><?= \App\Core\View::e($lastSyncSummary) ?></p>
                <?php endif; ?>
                <form method="post" action="/admin/ad/sync" class="mb-3">
                    <?= \App\Core\Csrf::field() ?>
                    <button type="submit" class="btn btn-outline-secondary btn-sm" <?= $configured ? '' : 'disabled' ?>>Синхронізувати зараз</button>
                    <span class="form-text">— працює незалежно від галочки вище.</span>
                </form>

                <p class="small mb-1">Для автоматичної синхронізації додайте в cron (наприклад, раз на годину):</p>
<pre class="bg-light border rounded p-2 small mb-0">0 * * * *  www-data  php <?= \App\Core\View::e($scriptPath) ?> >> /var/log/itsm-ad-sync.log 2>&1</pre>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Відповідність груп AD ролям</div>
    <div class="card-body">
        <p class="text-muted small">
            Під час синхронізації кожен AD-користувач отримує роль першої групи зі свого <code>memberOf</code>,
            для якої тут є відповідність (менший пріоритет — перевіряється раніше). Якщо жодна група не збіглась —
            роль за замовчуванням (<code>AD_DEFAULT_ROLE</code> у .env, типово «Заявник»).
        </p>
        <table class="table table-bordered bg-white align-middle mb-3">
            <thead><tr><th>Група AD</th><th>Роль</th><th>Пріоритет</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($mappings)): ?>
                <tr><td colspan="4" class="text-center text-muted">Відповідностей ще немає — усі AD-користувачі отримують роль за замовчуванням</td></tr>
            <?php else: foreach ($mappings as $m): ?>
                <tr>
                    <td><code><?= \App\Core\View::e($m['ad_group']) ?></code></td>
                    <td><?= \App\Core\View::e($m['role_name']) ?></td>
                    <td><?= (int)$m['rank'] ?></td>
                    <td>
                        <form method="post" action="/admin/ad/mappings/<?= (int)$m['id'] ?>/delete" data-confirm="Видалити відповідність для «<?= \App\Core\View::e($m['ad_group']) ?>»?">
                            <?= \App\Core\Csrf::field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger">Видалити</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <form method="post" action="/admin/ad/mappings" class="row g-2 align-items-end">
            <?= \App\Core\Csrf::field() ?>
            <div class="col-md-5">
                <label class="form-label small mb-1">Група AD (значення memberOf, зазвичай повний DN)</label>
                <input type="text" name="ad_group" class="form-control form-control-sm" placeholder="cn=ITSM-Operators,ou=Groups,dc=company,dc=local" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Роль</label>
                <select name="role_id" class="form-select form-select-sm" required>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= (int)$r['id'] ?>"><?= \App\Core\View::e($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Пріоритет</label>
                <input type="number" name="rank" class="form-control form-control-sm" value="100">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">Додати</button>
            </div>
        </form>
    </div>
</div>
