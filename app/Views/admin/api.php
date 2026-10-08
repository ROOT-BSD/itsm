<?php
$e = static fn($v) => \App\Core\View::e($v);
$fmt = static fn(?string $d) => $d ? date('d.m.Y H:i', strtotime($d)) : '—';
?>
<div class="mb-3"><?php require __DIR__ . '/_back.php'; ?></div>

<h3 class="mb-3">REST API</h3>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>

<?php if (!$available): ?>
    <div class="alert alert-warning">
        <strong>Таблиці REST API ще не створено.</strong> Файли застосунку оновлено, але не виконано оновлення бази даних —
        запустіть на сервері <code>sudo bash update.sh</code> (крок «Перевірка таблиць REST API і вебхуків»).
    </div>
<?php endif; ?>

<?php if ($newToken): ?>
    <div class="alert alert-success border-success">
        <h5 class="alert-heading">Новий токен для «<?= $e($newToken['user']) ?>» — <?= $e($newToken['name']) ?></h5>
        <p class="mb-2"><strong>Скопіюйте його зараз.</strong> У системі лишається лише його хеш — відновити токен потім неможливо (доведеться створити новий).</p>
        <input type="text" class="form-control font-monospace" readonly value="<?= $e($newToken['token']) ?>" onclick="this.select()">
        <p class="small mt-2 mb-0">Права: <strong><?= $newToken['scope'] === 'write' ? 'читання й запис' : 'лише читання' ?></strong>.
            Перевірка: <code>curl -H "Authorization: Bearer ТОКЕН" <?= $e($appUrl) ?>/api/v1/me</code></p>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <form method="post" action="/admin/api/settings" class="card p-4 shadow-sm h-100">
            <?= \App\Core\Csrf::field() ?>
            <h5 class="mb-3">Налаштування</h5>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="api-enabled" name="api_enabled" value="1" <?= $enabled ? 'checked' : '' ?> <?= $available ? '' : 'disabled' ?>>
                <label class="form-check-label" for="api-enabled">Увімкнути REST API</label>
            </div>
            <div class="mb-3">
                <label class="form-label" for="api-rate">Ліміт запитів на токен, за хвилину</label>
                <input type="number" id="api-rate" name="api_rate_limit" class="form-control w-auto" min="10" max="10000" value="<?= (int) $rate ?>" <?= $available ? '' : 'disabled' ?>>
                <div class="form-text">Понад ліміт API відповідає 429 і час, через який можна повторити.</div>
            </div>
            <div><button type="submit" class="btn btn-primary" <?= $available ? '' : 'disabled' ?>>Зберегти</button></div>
            <p class="small text-muted mt-3 mb-0">Вимкнене API відхиляє всі запити (503), навіть із чинним токеном. Токени при цьому не видаляються.</p>
        </form>
    </div>
    <div class="col-lg-6">
        <form method="post" action="/admin/api/tokens" class="card p-4 shadow-sm h-100">
            <?= \App\Core\Csrf::field() ?>
            <h5 class="mb-3">Створити токен для користувача</h5>
            <div class="mb-2">
                <label class="form-label">Від імені користувача</label>
                <select name="user_id" class="form-select user-select" <?= $available ? '' : 'disabled' ?>>
                    <option value="">— оберіть користувача —</option>
                    <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"><?= $e($u['full_name']) ?> (<?= $e($u['email']) ?>)</option><?php endforeach; ?>
                </select>
            </div>
            <div class="mb-2"><label class="form-label">Назва</label>
                <input type="text" name="name" class="form-control" maxlength="100" placeholder="Моніторинг Zabbix" required <?= $available ? '' : 'disabled' ?>></div>
            <div class="row g-2 mb-3">
                <div class="col"><label class="form-label">Права</label>
                    <select name="scope" class="form-select"><option value="read">Лише читання</option><option value="write">Читання й запис</option></select></div>
                <div class="col"><label class="form-label">Термін дії</label>
                    <select name="expires_days" class="form-select"><option value="30">30 днів</option><option value="90" selected>90 днів</option><option value="365">1 рік</option><option value="0">Без обмеження</option></select></div>
            </div>
            <div><button type="submit" class="btn btn-primary" <?= $available ? '' : 'disabled' ?>>Створити токен</button></div>
            <p class="small text-muted mt-3 mb-0">Для інтеграцій заведіть окремого службового користувача з мінімально потрібною роллю: токен діє з його правами.</p>
        </form>
    </div>
</div>

<h5 class="mb-2">Усі токени (<?= count($tokens) ?>)</h5>
<?php if (!$tokens): ?>
    <p class="text-muted">Токенів ще немає.</p>
<?php else: ?>
    <div class="table-responsive">
    <table class="table table-bordered bg-white align-middle small">
        <thead><tr><th>Користувач</th><th>Токен</th><th>Створено</th><th>Востаннє</th><th>Діє до</th><th>Стан</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tokens as $t):
            $expired = $t['expires_at'] && strtotime($t['expires_at']) < time();
            $state = $t['revoked_at'] ? ['відкликаний', 'secondary'] : ($expired ? ['прострочений', 'warning'] : (!(int) $t['is_active'] ? ['власник деактивований', 'warning'] : ['діє', 'success'])); ?>
            <tr>
                <td><?= $e($t['full_name']) ?><div class="text-muted"><?= $e($t['email']) ?></div></td>
                <td><?= $e($t['name']) ?> <span class="badge bg-light text-dark border"><?= $t['scope'] === 'write' ? 'запис' : 'читання' ?></span>
                    <div class="text-muted"><code><?= $e($t['token_prefix']) ?>…</code></div></td>
                <td class="text-nowrap"><?= $e($fmt($t['created_at'])) ?></td>
                <td class="text-nowrap"><?= $e($fmt($t['last_used_at'])) ?><?= $t['last_used_ip'] ? '<div class="text-muted">' . $e($t['last_used_ip']) . '</div>' : '' ?></td>
                <td class="text-nowrap"><?= $t['expires_at'] ? $e($fmt($t['expires_at'])) : 'без обмеження' ?></td>
                <td><span class="badge bg-<?= $state[1] ?>"><?= $state[0] ?></span></td>
                <td>
                    <?php if (!$t['revoked_at']): ?>
                        <form method="post" action="/admin/api/tokens/<?= (int) $t['id'] ?>/revoke" data-confirm="Відкликати токен «<?= $e($t['name']) ?>»? Інтеграція, що ним користується, одразу перестане працювати.">
                            <?= \App\Core\Csrf::field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger">Відкликати</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<div class="card p-3 mt-4">
    <h6>Швидкий старт</h6>
    <pre class="mb-2 small">curl -H "Authorization: Bearer ТОКЕН" <?= $e($appUrl) ?>/api/v1/tickets?status=new
curl -X POST -H "Authorization: Bearer ТОКЕН" -H "Content-Type: application/json" \
     -d '{"queue_id":1,"subject":"Тема","description":"Опис"}' <?= $e($appUrl) ?>/api/v1/tickets</pre>
    <div class="small text-muted">
        Маршрути: <code>/api/v1/me</code>, <code>/projects</code>, <code>/projects/{id}/tasks</code>, <code>/tasks</code>, <code>/tickets</code>
        (+ <code>/comments</code>), <code>/ticket-queues</code>, <code>/task-statuses</code>, <code>/task-types</code>, <code>/users</code>.
        Читання — GET; створення — POST; зміна статусу, виконавця, оператора — PATCH. Відповіді — JSON.
    </div>
</div>
