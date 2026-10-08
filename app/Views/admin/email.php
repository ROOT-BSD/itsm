<div class="mb-3">
    <?php require __DIR__ . '/_back.php'; ?>
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

<div class="card mb-4">
    <div class="card-header">Домен застосунку</div>
    <div class="card-body">
        <p class="text-muted small mb-2">
            Використовується для посилань у листах — наприклад, посилання для відстеження звернення в автовідповіді.
            Якщо тут порожньо, використовується <code>APP_URL</code> з файлу <code>.env</code> на сервері
            (зараз: <code><?= \App\Core\View::e($appUrlEnvDefault !== '' ? $appUrlEnvDefault : '— не задано —') ?></code>).
        </p>
        <form method="post" action="/admin/email/app-url" class="d-flex gap-2 align-items-start flex-wrap">
            <?= \App\Core\Csrf::field() ?>
            <input type="text" name="app_url" class="form-control app-url-input"
                   placeholder="https://itsm.company.com" value="<?= \App\Core\View::e($appUrlOverride) ?>">
            <button type="submit" class="btn btn-primary btn-sm">Зберегти</button>
        </form>
        <p class="small text-muted mt-2 mb-0">
            Зараз посилання формуються як: <code><?= \App\Core\View::e($appUrlEffective !== '' ? $appUrlEffective : 'http://localhost') ?>/support/track/…</code>
        </p>
    </div>
</div>

<div class="row">
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Отримання пошти (IMAP)</span>
                <?php if ($configured): ?>
                    <span class="badge bg-success">налаштовано</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark">не налаштовано</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($configured && $configProblem): ?>
                    <div class="alert alert-danger small"><strong>Помилка в налаштуваннях .env:</strong> <?= \App\Core\View::e($configProblem) ?></div>
                <?php endif; ?>
                <?php if ($configured): ?>
                    <table class="table table-sm mb-3">
                        <tr><th class="email-table-label">Сервер</th><td><?= \App\Core\View::e($mail['host']) ?>:<?= \App\Core\View::e((string)$mail['port']) ?></td></tr>
                        <tr><th>Шифрування</th><td><?= \App\Core\View::e(['ssl' => 'SSL/TLS', 'tls' => 'STARTTLS', 'none' => 'без шифрування'][$mail['encryption']] ?? $mail['encryption']) ?></td></tr>
                        <tr><th>Користувач</th><td><?= \App\Core\View::e($mail['username']) ?></td></tr>
                        <tr><th>Папка</th><td><?= \App\Core\View::e($mail['folder']) ?></td></tr>
                        <tr><th>Перевірка сертифіката</th><td><?= $mail['verify_cert'] ? 'так' : '<span class="text-warning">вимкнена</span>' ?></td></tr>
                    </table>
                    <form method="post" action="/admin/email/test">
                        <?= \App\Core\Csrf::field() ?>
                        <button type="submit" class="btn btn-outline-primary btn-sm" <?= $configProblem ? 'disabled' : '' ?>>Перевірити з'єднання</button>
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

    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Надсилання пошти (SMTP)</span>
                <?php if ($smtpConfigured): ?>
                    <span class="badge bg-success">налаштовано</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark">не налаштовано</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <p class="text-muted small">Потрібне лише для автовідповіді заявнику з посиланням для відстеження — саме отримання й створення тікетів працює без цього.</p>
                <?php if ($smtpConfigured): ?>
                    <table class="table table-sm mb-3">
                        <tr><th class="email-table-label">Сервер</th><td><?= \App\Core\View::e($smtp['host']) ?>:<?= \App\Core\View::e((string)$smtp['port']) ?></td></tr>
                        <tr><th>Шифрування</th><td><?= \App\Core\View::e(['ssl' => 'SSL/TLS', 'tls' => 'STARTTLS', 'none' => 'без шифрування'][$smtp['encryption']] ?? $smtp['encryption']) ?></td></tr>
                        <tr><th>Від кого (From)</th><td><?= \App\Core\View::e($smtp['from_name']) ?> &lt;<?= \App\Core\View::e($smtp['from_email']) ?>&gt;</td></tr>
                        <tr><th>Перевірка сертифіката</th><td><?= $smtp['verify_cert'] ? 'так' : '<span class="text-warning">вимкнена</span>' ?></td></tr>
                    </table>
                    <form method="post" action="/admin/email/test-smtp" class="d-inline">
                        <?= \App\Core\Csrf::field() ?>
                        <button type="submit" class="btn btn-outline-primary btn-sm">Перевірити з'єднання</button>
                    </form>
                    <form method="post" action="/admin/email/send-test" class="d-inline">
                        <?= \App\Core\Csrf::field() ?>
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Надіслати тестовий лист собі (<?= \App\Core\View::e($myEmail) ?>)</button>
                    </form>
                <?php else: ?>
                    <p>Без окремих <code>MAIL_SMTP_*</code> успадковує сервер/логін/пароль з <code>MAIL_IMAP_*</code> вище (типова ситуація — та сама скринька). Мінімум додайте адресу відправника:</p>
<pre class="bg-light border rounded p-2 small mb-2">MAIL_SMTP_FROM_EMAIL=support@example.org
MAIL_SMTP_FROM_NAME=Служба підтримки</pre>
                    <p class="small text-muted mb-0">
                        За потреби окремий сервер: <code>MAIL_SMTP_HOST</code>, <code>MAIL_SMTP_PORT</code>, <code>MAIL_SMTP_ENCRYPTION</code> (<code>ssl</code> — порт 465, <code>tls</code>/STARTTLS — порт 587, <code>none</code>), <code>MAIL_SMTP_USERNAME</code>, <code>MAIL_SMTP_PASSWORD</code>.
                        Самопідписаний сертифікат — <code>MAIL_SMTP_VERIFY_CERT=false</code>.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header">Обробка пошти</div>
            <div class="card-body">
                <form method="post" action="/admin/email" class="mb-3">
                    <?= \App\Core\Csrf::field() ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="enabled" id="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enabled">Автоматично обробляти пошту (за розкладом cron)</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="autoreply_enabled" id="autoreply_enabled" value="1" <?= $autoreplyEnabled ? 'checked' : '' ?> <?= $smtpConfigured ? '' : 'disabled' ?>>
                        <label class="form-check-label" for="autoreply_enabled">Надсилати автовідповідь заявнику з посиланням для відстеження</label>
                        <?php if (!$smtpConfigured): ?>
                            <div class="form-text text-warning">Спершу налаштуйте надсилання пошти (SMTP) ліворуч.</div>
                        <?php endif; ?>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="notify_tickets_enabled" id="notify_tickets_enabled" value="1" <?= $notifyTicketsEnabled ? 'checked' : '' ?> <?= $smtpConfigured ? '' : 'disabled' ?>>
                        <label class="form-check-label" for="notify_tickets_enabled">Сповіщення про тікети — нова відповідь, зміна статусу</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="notify_projects_enabled" id="notify_projects_enabled" value="1" <?= $notifyProjectsEnabled ? 'checked' : '' ?> <?= $smtpConfigured ? '' : 'disabled' ?>>
                        <label class="form-check-label" for="notify_projects_enabled">Сповіщення про проєкти — створення з відповідальним, зміна призначення</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="notify_tasks_enabled" id="notify_tasks_enabled" value="1" <?= $notifyTasksEnabled ? 'checked' : '' ?> <?= $smtpConfigured ? '' : 'disabled' ?>>
                        <label class="form-check-label" for="notify_tasks_enabled">Сповіщення про задачі — створення з виконавцем, зміна призначення</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="notify_forum_enabled" id="notify_forum_enabled" value="1" <?= $notifyForumEnabled ? 'checked' : '' ?> <?= $smtpConfigured ? '' : 'disabled' ?>>
                        <label class="form-check-label" for="notify_forum_enabled">Сповіщення про форум — нова відповідь у темі, де ви автор або учасник</label>
                        <div class="form-text">Лист отримують автор теми та ті, хто в ній писав (не більше 20 адресатів на відповідь), якщо бачать розділ.</div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="notify_reminders_enabled" id="notify_reminders_enabled" value="1" <?= $notifyRemindersEnabled ? 'checked' : '' ?> <?= $smtpConfigured ? '' : 'disabled' ?>>
                        <label class="form-check-label" for="notify_reminders_enabled">Нагадування про термін задачі — за 2 дні, за 1 день, у день настання</label>
                        <div class="form-text">Потребує окремого запису в cron (див. нижче) — самої галочки недостатньо.</div>
                    </div>
                    <?php if (!$smtpConfigured): ?>
                        <div class="form-text text-warning mb-3">Усі сповіщення вище вимкнені, доки не налаштовано надсилання пошти (SMTP) ліворуч.</div>
                    <?php endif; ?>
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
                    <button type="submit" class="btn btn-outline-secondary btn-sm" <?= ($configured && !$configProblem) ? '' : 'disabled' ?>>Забрати пошту зараз</button>
                    <span class="form-text">— працює незалежно від галочки вище.</span>
                </form>

                <p class="small mb-1">Для автоматичної обробки додайте в cron (наприклад, кожні 5 хвилин):</p>
<pre class="bg-light border rounded p-2 small mb-0">*/5 * * * *  www-data  php <?= \App\Core\View::e($scriptPath) ?> >> /var/log/itsm-mail.log 2>&1</pre>

                <?php if ($notifyRemindersEnabled): ?>
                <p class="small mb-1 mt-3">Для нагадувань про термін задачі (раз на день зранку):</p>
<pre class="bg-light border rounded p-2 small mb-0">0 8 * * *  www-data  php <?= \App\Core\View::e($remindersScriptPath) ?> >> /var/log/itsm-reminders.log 2>&1</pre>
                <?php endif; ?>
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
