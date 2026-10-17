<?php
$e = static fn($v) => \App\Core\View::e($v);
$h = static fn(float $b) => \App\Services\BackupService::human($b);
?>
<div class="mb-3"><?php require __DIR__ . '/_back.php'; ?></div>

<h3 class="mb-3">Резервні копії</h3>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>

<p class="text-muted">Одна копія — один архів <code>.tar.gz</code>: повний дамп бази даних, вкладення й документи (<code>storage/uploads</code>) та текст вікі (<code>storage/wiki</code>).
    Файл <code>.env</code> (паролі) до архіву <strong>не входить</strong> — зберігайте його окремо. Мініатюри й кеш PDF не копіюються: вони відтворюються самі.</p>

<div class="alert alert-secondary small">
    <strong>Шифрування:</strong> <?= $encryptionOn ? 'увімкнено (AES-256, пароль <code>BACKUP_PASSWORD</code> з .env) — нові копії мають розширення <code>.tar.gz.enc</code>. <strong>Збережіть пароль окремо: без нього копію не відновити.</strong>' : 'вимкнено. Задайте <code>BACKUP_PASSWORD</code> у .env, щоб нові копії шифрувались.' ?><br>
    <strong>Перенесення на інший носій:</strong>
    <?php if ($remoteDir === '' && $remoteScp === ''): ?>не налаштовано (<code>BACKUP_REMOTE_DIR</code> — змонтований мережевий диск, <code>BACKUP_REMOTE_SCP</code> — <code>користувач@хост:/каталог/</code>).
    <?php else: ?><?= $remoteDir !== '' ? 'каталог <code>' . $e($remoteDir) . '</code> ' : '' ?><?= $remoteScp !== '' ? 'scp <code>' . $e($remoteScp) . '</code>' : '' ?><?php endif; ?>
    <?php if ($lastCheck && $lastCheck !== 'ok'): ?><br><span class="text-danger"><?= $e($lastCheck) ?></span><?php elseif ($lastCheck === 'ok'): ?><br><span class="text-success">Остання копія перевірена й перенесена без помилок.</span><?php endif; ?>
</div>

<?php if (!$writable): ?>
    <div class="alert alert-danger">Каталог <code><?= $e($dir) ?></code> недоступний для запису веб-серверу. Дайте права: <code>sudo mkdir -p <?= $e($dir) ?> &amp;&amp; sudo chown www-data:www-data <?= $e($dir) ?> &amp;&amp; sudo chmod 770 <?= $e($dir) ?></code> (або запустіть <code>sudo bash update.sh</code>).</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card shadow-sm h-100"><div class="card-body">
            <h5 class="card-title">Створити копію зараз</h5>
            <form method="post" action="/admin/backup" id="backup-create-form">
                <?= \App\Core\Csrf::field() ?>
                <button type="submit" class="btn btn-primary" <?= $writable ? '' : 'disabled' ?>>Створити резервну копію</button>
            </form>
            <p class="small text-muted mt-2 mb-0">Для великого сховища (десятки гігабайт) надійніше запускати з планувальника — нижче. Кнопка виконує все в межах одного запиту.
                Вільне місце: <?= $free === null ? '—' : $e($h($free)) ?>.
                <?php if ($lastRun): ?>Остання копія: <?= $e(date('d.m.Y H:i', strtotime($lastRun))) ?> — <?= $e($lastSummary) ?>.<?php elseif ($lastSummary): ?><?= $e($lastSummary) ?><?php else: ?>Копій ще не створювали.<?php endif; ?></p>
        </div></div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm h-100"><div class="card-body">
            <h5 class="card-title">Скільки копій тримати</h5>
            <form method="post" action="/admin/backup/settings" class="d-flex gap-2">
                <?= \App\Core\Csrf::field() ?>
                <input type="number" name="keep" min="1" max="365" value="<?= (int) $keep ?>" class="form-control" style="max-width:110px">
                <button type="submit" class="btn btn-outline-primary">Зберегти</button>
            </form>
            <p class="small text-muted mt-2 mb-0">Після кожної нової копії найстаріші понад цю кількість видаляються автоматично.</p>
        </div></div>
    </div>
</div>

<h5>Наявні копії</h5>
<table class="table table-bordered bg-white align-middle">
    <thead><tr><th>Файл</th><th>Створено</th><th>Розмір</th><th style="width:380px"></th></tr></thead>
    <tbody>
    <?php if (empty($backups)): ?>
        <tr><td colspan="4" class="text-center text-muted">Копій ще немає</td></tr>
    <?php else: foreach ($backups as $b): ?>
        <tr>
            <td><code><?= $e($b['name']) ?></code><?= !empty($b['encrypted']) ? ' <span class="badge bg-success">зашифровано</span>' : '' ?></td>
            <td><?= $e(date('d.m.Y H:i', $b['mtime'])) ?></td>
            <td><?= $e($h((float) $b['size'])) ?></td>
            <td class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="/admin/backup/<?= $e(\App\Services\BackupService::stampOf($b['name'])) ?>/download">Скачати</a>
                <form method="post" action="/admin/backup/<?= $e(\App\Services\BackupService::stampOf($b['name'])) ?>/verify" class="d-inline">
                    <?= \App\Core\Csrf::field() ?>
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Перевірити</button>
                </form>
                <button type="button" class="btn btn-sm btn-outline-warning js-restore-pick" data-stamp="<?= $e(\App\Services\BackupService::stampOf($b['name'])) ?>">Відновити…</button>
                <form method="post" action="/admin/backup/<?= $e(\App\Services\BackupService::stampOf($b['name'])) ?>/delete" class="d-inline" data-confirm="Видалити цю копію безповоротно?">
                    <?= \App\Core\Csrf::field() ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger">Видалити</button>
                </form>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

<div class="card shadow-sm border-warning mt-4" id="restore-card"><div class="card-body">
    <h5 class="card-title">Відновити з копії</h5>
    <div class="alert alert-warning small">Відновлення <strong>повністю замінює</strong> поточну базу даних і додає/перезаписує файли <code>storage/uploads</code> та <code>storage/wiki</code> даними з копії:
        усе, що з’явилось після створення копії, буде втрачено. Спершу автоматично створюється страхувальна копія поточного стану (для відкату). Файл <code>.env</code> не змінюється. Потрібні утиліти <code>mysql</code> і <code>tar</code> на сервері.</div>
    <form method="post" action="/admin/backup/restore" enctype="multipart/form-data" id="restore-form" data-confirm="Поточну базу даних і файли буде ЗАМІНЕНО даними з копії. Продовжити?">
        <?= \App\Core\Csrf::field() ?>
        <div class="form-check">
            <input class="form-check-input" type="radio" name="source" id="rs-existing" value="existing" checked>
            <label class="form-check-label" for="rs-existing">Одна зі створених копій (на сервері)</label>
        </div>
        <select name="stamp" id="restore-stamp" class="form-select mt-1 mb-2" <?= empty($backups) ? 'disabled' : '' ?>>
            <?php if (empty($backups)): ?><option value="">— копій ще немає —</option>
            <?php else: foreach ($backups as $b): ?>
                <option value="<?= $e(\App\Services\BackupService::stampOf($b['name'])) ?>"><?= $e($b['name']) ?> — <?= $e(date('d.m.Y H:i', $b['mtime'])) ?>, <?= $e($h((float) $b['size'])) ?></option>
            <?php endforeach; endif; ?>
        </select>
        <div class="form-check">
            <input class="form-check-input" type="radio" name="source" id="rs-upload" value="upload">
            <label class="form-check-label" for="rs-upload">Файл з мого комп’ютера</label>
        </div>
        <input type="file" name="backup_file" id="restore-file" class="form-control mt-1" accept=".gz,.enc,.tar.gz">
        <div class="form-text mb-2">Файл <code>.tar.gz</code> або <code>.tar.gz.enc</code>. Максимум для завантаження через браузер на цьому сервері — <strong><?= $e($uploadMax) ?></strong> (обмежують <code>upload_max_filesize</code> і <code>post_max_size</code> у PHP);
            більшу копію покладіть у каталог копій на сервері (вона з’явиться у списку вище) або скористайтесь <code>php bin/restore.php</code>.</div>
        <div class="row g-2">
            <div class="col-md-6">
                <label class="form-label small mb-0" for="restore-pass">Пароль шифрування (лише для копії з іншого сервера)</label>
                <input type="password" name="password" id="restore-pass" class="form-control" autocomplete="off" placeholder="порожньо — взяти BACKUP_PASSWORD з .env">
            </div>
            <div class="col-md-6">
                <label class="form-label small mb-0" for="restore-confirm">Для підтвердження введіть слово «<?= $e($restoreWord) ?>»</label>
                <input type="text" name="confirm" id="restore-confirm" class="form-control" autocomplete="off" required>
            </div>
        </div>
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="safety" id="restore-safety" value="1" checked>
            <label class="form-check-label" for="restore-safety">Створити страхувальну копію поточного стану перед відновленням (рекомендовано)</label>
        </div>
        <button type="submit" class="btn btn-danger mt-3" id="restore-btn" <?= $writable ? '' : 'disabled' ?>>Відновити</button>
    </form>
</div></div>

<h5 class="mt-4">Автоматично за розкладом</h5>
<p>Додайте в cron (наприклад, щоночі о 02:30); запускайте від користувача веб-сервера, щоб скачування з цієї сторінки працювало:</p>
<pre class="bg-light p-2 border rounded"><code>30 2 * * *  www-data  php <?= $e($root) ?>/bin/backup.php &gt;&gt; /var/log/itsm-backup.log 2&gt;&amp;1</code></pre>

<h5 class="mt-4">Відновлення</h5>
<p class="mb-1">Інструкція лежить усередині кожного архіву (<code>RESTORE.txt</code>). Коротко:</p>
<pre class="bg-light p-2 border rounded"><code>mkdir restore &amp;&amp; tar -xzf backup-….tar.gz -C restore
mysql -u&lt;користувач&gt; -p &lt;база&gt; &lt; restore/database.sql
cp -a restore/storage/uploads/. <?= $e($root) ?>/storage/uploads/
cp -a restore/storage/wiki/.    <?= $e($root) ?>/storage/wiki/</code></pre>
<div class="alert alert-warning small">Архів містить усі дані системи, зокрема хеші паролів і токени. Зберігайте його в захищеному місці, а <strong>копію тримайте не лише на цьому сервері</strong>: скачайте її або налаштуйте перенесення на інший носій — інакше вона не врятує від виходу сервера з ладу.
    Каталог копій: <code><?= $e($dir) ?></code> (поза веб-коренем).</div>

<script nonce="<?= \App\Core\Csp::nonce() ?>">
// Після натискання блокуємо кнопку, щоб не запустити копію вдруге, і просимо не закривати сторінку.
document.getElementById('backup-create-form')?.addEventListener('submit', function () {
    const b = this.querySelector('button');
    setTimeout(function () { b.disabled = true; b.textContent = 'Створюється… не закривайте сторінку'; }, 0);
});

// «Відновити…» у рядку таблиці: обрати цю копію у формі відновлення й перейти до неї.
document.querySelectorAll('.js-restore-pick').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('rs-existing').checked = true;
        document.getElementById('restore-stamp').value = btn.dataset.stamp;
        document.getElementById('restore-card').scrollIntoView({behavior: 'smooth'});
        document.getElementById('restore-confirm').focus();
    });
});
// Вибір файлу автоматично перемикає джерело; після відправки блокуємо кнопку (відновлення може тривати хвилини).
document.getElementById('restore-file')?.addEventListener('change', function () {
    if (this.files.length) { document.getElementById('rs-upload').checked = true; }
});
document.getElementById('restore-form')?.addEventListener('submit', function (ev) {
    const b = document.getElementById('restore-btn');
    setTimeout(function () { if (ev.defaultPrevented) { return; } b.disabled = true; b.textContent = 'Відновлюється… не закривайте сторінку'; }, 0);
});
</script>
