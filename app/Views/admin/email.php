<div class="mb-3">
    <a href="/admin" class="text-decoration-none">&larr; Адмін-панель</a>
</div>

<h3 class="mb-2">Пошта → тікети</h3>
<p class="text-muted">
    Листи, що надходять у поштову скриньку підтримки, автоматично стають тікетами; відповіді в тій самій гілці листування
    додаються коментарями до вже наявного тікета. Вкладення поки не зберігаються — у тікеті лише їх перелік.
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
                <span>Підключення до скриньки (IMAP)</span>
                <?php if ($configured): ?>
                    <span class="badge bg-success">налаштовано</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark">не налаштовано</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($configured): ?>
                    <table class="table table-sm mb-3">
                        <tr><th class="email-table-label">Сервер</th><td><?= \App\Core\View::e($mail['host']) ?>:<?= (int)$mail['port'] ?></td></tr>
                        <tr><th>Шифрування</th><td><?= \App\Core\View::e(['ssl' => 'SSL/TLS', 'tls' => 'STARTTLS', 'none' => 'без шифрування'][$mail['encryption']] ?? $mail['encryption']) ?></td></tr>
                        <tr><th>Користувач</th><td><?= \App\Core\View::e($mail['username']) ?></td></tr>
                        <tr><th>Папка</th><td><?= \App\Core\View::e($mail['folder']) ?></td></tr>
                        <tr><th>Перевірка сертифіката</th><td><?= $mail['verify_cert'] ? 'так' : '<span class="text-warning">вимкнена</span>' ?></td></tr>
                    </table>
                    <form method="post" action="/admin/email/test">
                        <?= \App\Core\Csrf::field() ?>
                        <button type="submit" class="btn btn-outline-primary btn-sm">Перевірити з'єднання</button>
                    </form>
                <?php else: ?>
                    <p>Пароль до скриньки зберігається лише у файлі <code>.env</code> на сервері (не в базі даних). Додайте туди:</p>
<pre class="bg-light border rounded p-2 small mb-2">MAIL_IMAP_HOST=imap.example.org
MAIL_IMAP_PORT=993
MAIL_IMAP_ENCRYPTION=ssl
MAIL_IMAP_USERNAME=support@example.org
MAIL_IMAP_PASSWORD=пароль
MAIL_IMAP_FOLDER=INBOX</pre>
                    <p class="small text-muted mb-0">
                        <code>MAIL_IMAP_ENCRYPTION</code>: <code>ssl</code> (порт 993), <code>tls</code> (STARTTLS, порт 143) або <code>none</code>.
                        Для внутрішнього сервера з власним (самопідписаним) сертифікатом додайте <code>MAIL_IMAP_VERIFY_CERT=false</code>.
                        Після збереження .env оновіть цю сторінку.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header">Обробка пошти</div>
            <div class="card-body">
                <form method="post" action="/admin/email" class="mb-3">
                    <?= \App\Core\Csrf::field() ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="enabled" id="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enabled">Автоматично обробляти пошту (за розкладом cron)</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Черга для тікетів з пошти</label>
                        <select name="queue_id" class="form-select">
                            <option value="0" <?= $queueId === 0 ? 'selected' : '' ?>>— перша черга за замовчуванням —</option>
                            <?php foreach ($queues as $q): ?>
                                <option value="<?= (int)$q['id'] ?>" <?= $queueId === (int)$q['id'] ? 'selected' : '' ?>><?= \App\Core\View::e($q['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Зберегти</button>
                </form>

                <hr>
                <p class="mb-1">
                    <strong>Останній запуск:</strong>
                    <?= $lastRunAt ? \App\Core\View::e($lastRunAt) : '<span class="text-muted">ще не було</span>' ?>
                </p>
                <?php if ($lastRunSummary): ?>
                    <p class="small text-muted mb-2"><?= \App\Core\View::e($lastRunSummary) ?></p>
                <?php endif; ?>
                <form method="post" action="/admin/email/fetch" class="mb-3">
                    <?= \App\Core\Csrf::field() ?>
                    <button type="submit" class="btn btn-outline-secondary btn-sm" <?= $configured ? '' : 'disabled' ?>>Забрати пошту зараз</button>
                    <span class="form-text">— працює незалежно від галочки вище.</span>
                </form>

                <p class="small mb-1">Для автоматичної обробки додайте в cron (наприклад, кожні 5 хвилин):</p>
<pre class="bg-light border rounded p-2 small mb-0">*/5 * * * *  www-data  php <?= \App\Core\View::e($scriptPath) ?> >> /var/log/itsm-mail.log 2>&1</pre>
            </div>
        </div>
    </div>
</div>

<h5>Журнал обробки</h5>
<p class="text-muted small">Останні 50 листів, включно з пропущеними — тут видно, чому певний лист не став тікетом.</p>
<?php
$resultLabels = [
    'ticket_created' => ['Створено тікет', 'success'],
    'comment_added' => ['Додано коментар', 'primary'],
    'skipped' => ['Пропущено', 'secondary'],
];
?>
<table class="table table-sm table-bordered bg-white align-middle">
    <thead><tr><th>Коли</th><th>Від</th><th>Тема</th><th>Результат</th><th>Тікет</th><th>Примітка</th></tr></thead>
    <tbody>
    <?php if (empty($log)): ?>
        <tr><td colspan="6" class="text-center text-muted">Листів ще не оброблено</td></tr>
    <?php else: foreach ($log as $row): ?>
        <tr>
            <td class="text-nowrap"><?= \App\Core\View::e($row['created_at']) ?></td>
            <td><?= \App\Core\View::e($row['from_email'] !== '' ? $row['from_email'] : '—') ?></td>
            <td><?= \App\Core\View::e($row['subject'] !== '' ? $row['subject'] : '—') ?></td>
            <td>
                <?php [$label, $color] = $resultLabels[$row['result']] ?? [$row['result'], 'secondary']; ?>
                <span class="badge bg-<?= $color ?>"><?= \App\Core\View::e($label) ?></span>
            </td>
            <td>
                <?php if (!empty($row['ticket_id'])): ?>
                    <a href="/tickets/<?= (int)$row['ticket_id'] ?>">#<?= (int)$row['ticket_id'] ?></a>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td class="small text-muted"><?= \App\Core\View::e($row['note'] ?? '') ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
