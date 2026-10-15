<?php
use App\Core\View;
use App\Models\LoginLog;
?>
<div class="mb-3"><?php require __DIR__ . '/_back.php'; ?></div>
<h3 class="mb-2">Журнал входів</h3>
<p class="text-muted small">Записи зберігаються <?= (int) LoginLog::KEEP_DAYS ?> діб. Для невдалих спроб із неіснуючим користувачем показано введений логін. <a href="/admin/sessions">Активні сесії →</a></p>

<?php if (!$ready): ?>
    <div class="alert alert-warning">Потрібна міграція 040: виконайте <code>sudo bash update.sh</code>.</div>
<?php else: ?>
<form method="get" action="/admin/login-log" class="card p-3 shadow-sm mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label small mb-1">Логін, ім'я або IP</label>
            <input type="text" name="q" class="form-control form-control-sm" value="<?= View::e($filters['q']) ?>"></div>
        <div class="col-md-2"><label class="form-label small mb-1">Результат</label>
            <select name="result" class="form-select form-select-sm">
                <option value="">— усі —</option>
                <option value="ok" <?= $filters['result'] === 'ok' ? 'selected' : '' ?>>Успішні</option>
                <option value="fail" <?= $filters['result'] === 'fail' ? 'selected' : '' ?>>Невдалі</option>
            </select></div>
        <div class="col-md-2"><label class="form-label small mb-1">Від</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= View::e($filters['date_from']) ?>"></div>
        <div class="col-md-2"><label class="form-label small mb-1">До</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= View::e($filters['date_to']) ?>"></div>
        <div class="col-md-2 d-flex gap-2">
            <button class="btn btn-sm btn-primary">Фільтрувати</button>
            <a href="/admin/login-log" class="btn btn-sm btn-outline-secondary">Скинути</a></div>
    </div>
</form>

<div class="text-muted small mb-2">Знайдено: <?= (int) $total ?></div>
<table class="table table-sm bg-white">
    <thead><tr><th>Коли</th><th>Користувач</th><th>Результат</th><th>Спосіб</th><th>IP</th><th>Пристрій</th></tr></thead>
    <tbody>
    <?php if (empty($entries)): ?>
        <tr><td colspan="6" class="text-center text-muted">Записів немає</td></tr>
    <?php else: foreach ($entries as $e): ?>
        <tr class="<?= $e['success'] ? '' : 'table-warning' ?>">
            <td><?= View::e(date('d.m.Y H:i:s', strtotime($e['created_at']))) ?></td>
            <td><?= View::e($e['full_name'] ?? '—') ?><div class="small text-muted"><?= View::e($e['login']) ?></div></td>
            <td><?= $e['success'] ? '✔ вхід' : '✖ ' . View::e(LoginLog::REASONS[$e['reason']] ?? 'відмова') ?></td>
            <td><?= View::e(LoginLog::METHODS[$e['method']] ?? $e['method']) ?></td>
            <td><code><?= View::e($e['ip'] ?? '—') ?></code></td>
            <td title="<?= View::e($e['user_agent'] ?? '') ?>"><?= View::e(\App\Models\UserSession::describe($e['user_agent'])) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
<?php if ($totalPages > 1): $q = $filters; ?>
    <nav class="d-flex justify-content-between align-items-center">
        <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= View::e(http_build_query(array_merge($q, ['page' => $page - 1]))) ?>">&larr; Попередня</a>
        <span class="text-muted small">Сторінка <?= $page ?> з <?= $totalPages ?></span>
        <a class="btn btn-sm btn-outline-secondary <?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= View::e(http_build_query(array_merge($q, ['page' => $page + 1]))) ?>">Наступна &rarr;</a>
    </nav>
<?php endif; ?>
<?php endif; ?>
