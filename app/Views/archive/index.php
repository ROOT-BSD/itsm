<div class="mb-3">
    <a href="/" class="text-decoration-none">&larr; Дашборд</a>
</div>

<h3 class="mb-2">Архів</h3>
<p class="text-muted">
    Закриті тікети, задачі й проєкти — прибрані з головних списків, щоб ті показували лише актуальну роботу.
    Тут зібрано все закрите, до чого у вас є доступ.
</p>

<div class="d-flex gap-2 mb-2">
    <button type="button" class="btn btn-sm btn-outline-secondary" id="arch-open-all">Показати все</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="arch-close-all">Згорнути все</button>
    <span class="small text-muted align-self-center">Розділи згорнуті — натисніть на назву, щоб відкрити.</span>
</div>

<details id="arch-projects" class="archive-sec card shadow-sm mb-3">
<summary class="card-header fw-semibold" style="cursor:pointer">Закриті проєкти <span class="badge text-bg-secondary ms-1"><?= count($projects ?? []) ?></span></summary>
<div class="card-body p-2">
<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr><th>Назва</th><th>Відповідальний</th></tr>
    </thead>
    <tbody>
    <?php if (empty($projects)): ?>
        <tr><td colspan="2" class="text-center text-muted">Закритих проєктів немає</td></tr>
    <?php else: foreach ($projects as $p): ?>
        <tr>
            <td>
                <a href="/projects/<?= (int)$p['id'] ?>"><?= \App\Core\View::e($p['name']) ?></a>
                <?php if (!empty($p['parent_id'])): ?>
                    <div class="small text-muted">↳ підпроєкт: <?= \App\Core\View::e($p['parent_name']) ?></div>
                <?php endif; ?>
            </td>
            <td><?= \App\Core\View::e($p['responsible_name'] ?? '— не призначено —') ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
</details>

<details id="arch-tickets" class="archive-sec card shadow-sm mb-3">
<summary class="card-header fw-semibold" style="cursor:pointer">Закриті тікети <span class="badge text-bg-secondary ms-1"><?= count($tickets ?? []) ?></span></summary>
<div class="card-body p-2">
<div class="d-flex justify-content-end mb-2">
    <?php $exportUrl = '/tickets/export'; $exportExtra = ['status' => 'closed']; require __DIR__ . '/../_export_button.php'; ?>
</div>
<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr><th>#</th><th>Тема</th><th>Черга</th><th>Проєкт</th><th>Заявник</th><th>Оператор</th></tr>
    </thead>
    <tbody>
    <?php if (empty($tickets)): ?>
        <tr><td colspan="6" class="text-center text-muted">Закритих тікетів немає</td></tr>
    <?php else: foreach ($tickets as $t): ?>
        <tr>
            <td><a href="/tickets/<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
            <td><?= \App\Core\View::e($t['subject']) ?></td>
            <td><?= \App\Core\View::e($t['queue_name']) ?></td>
            <td>
                <?php if (!empty($t['project_id'])): ?>
                    <a href="/projects/<?= (int)$t['project_id'] ?>"><?= \App\Core\View::e($t['project_name'] ?? '') ?></a>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td><?= \App\Core\View::e($t['requester_name']) ?></td>
            <td><?= \App\Core\View::e($t['operator_name'] ?? '— не призначено —') ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
</details>

<details id="arch-tasks" class="archive-sec card shadow-sm mb-3">
<summary class="card-header fw-semibold" style="cursor:pointer">Закриті задачі <span class="badge text-bg-secondary ms-1"><?= count($tasks ?? []) ?></span></summary>
<div class="card-body p-2">
<div class="d-flex justify-content-end mb-2">
    <?php $exportUrl = '/tasks/export'; $exportExtra = ['status' => 'closed']; require __DIR__ . '/../_export_button.php'; ?>
</div>
<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr><th>#</th><th>Назва</th><th>Проєкт</th><th>Виконавець</th><th>Пріоритет</th></tr>
    </thead>
    <tbody>
    <?php if (empty($tasks)): ?>
        <tr><td colspan="5" class="text-center text-muted">Закритих задач немає</td></tr>
    <?php else: foreach ($tasks as $t): ?>
        <tr>
            <td><a href="/tasks/<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
            <td><?= \App\Core\View::e($t['title']) ?> <?= \App\Core\View::progress($t) ?></td>
            <td><a href="/projects/<?= (int)$t['project_id'] ?>"><?= \App\Core\View::e($t['project_name']) ?></a></td>
            <td><?= \App\Core\View::e($t['assignee_name'] ?? '— не призначено —') ?></td>
            <td><?= \App\Core\View::e($t['priority']) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
</details>

<script nonce="<?= \App\Core\Csp::nonce() ?>">
(function () {
    var secs = document.querySelectorAll('details.archive-sec');
    function setAll(open) { secs.forEach(function (d) { d.open = open; }); }
    document.getElementById('arch-open-all').addEventListener('click', function () { setAll(true); });
    document.getElementById('arch-close-all').addEventListener('click', function () { setAll(false); });
    // Якщо за адресою є #якір розділу — відкриваємо його.
    secs.forEach(function (d) { if (d.id && location.hash === '#' + d.id) { d.open = true; } });
})();
</script>
