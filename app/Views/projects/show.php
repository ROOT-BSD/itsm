<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h3 class="mb-0"><?= \App\Core\View::e($project['name']) ?></h3>
        <small class="text-muted">Створив: <?= \App\Core\View::e($project['created_by_name']) ?></small>
    </div>
    <div class="d-flex gap-2">
        <a href="/projects/<?= (int)$project['id'] ?>/board" class="btn btn-outline-secondary">📋 Канбан-дошка</a>
        <a href="/projects/<?= (int)$project['id'] ?>/gantt" class="btn btn-outline-secondary">📊 Діаграма Ганта</a>
        <a href="/projects/<?= (int)$project['id'] ?>/roadmap" class="btn btn-outline-secondary">🗺️ Дорожня карта</a>
        <a href="/projects/<?= (int)$project['id'] ?>/time" class="btn btn-outline-secondary">⏱️ Облік часу</a>
        <a href="/projects/<?= (int)$project['id'] ?>/tasks/create" class="btn btn-primary">+ Нова задача</a>
    </div>
</div>

<p><?= nl2br(\App\Core\View::e($project['description'])) ?></p>

<?php if (\App\Core\Auth::hasRole(['admin', 'it_manager'])): ?>
<form method="post" action="/projects/<?= (int)$project['id'] ?>/responsible" class="d-flex gap-2 align-items-center mb-3">
    <?= \App\Core\Csrf::field() ?>
    <label class="form-label mb-0">Відповідальний (виконавець проєкту):</label>
    <select name="responsible_user_id" class="form-select w-auto">
        <option value="">— не призначено —</option>
        <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)($project['responsible_user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                <?= \App\Core\View::e($u['full_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-sm btn-outline-primary">Зберегти</button>
</form>
<?php else: ?>
<p class="text-muted small">
    Відповідальний за проєкт: <?= \App\Core\View::e($project['responsible_name'] ?? 'не призначено') ?>
</p>
<?php endif; ?>

<table class="table table-bordered bg-white">
    <thead>
        <tr><th>#</th><th>Назва</th><th>Тип</th><th>Статус</th><th>Пріоритет</th><th>Виконавець</th></tr>
    </thead>
    <tbody>
    <?php if (empty($tasks)): ?>
        <tr><td colspan="6" class="text-center text-muted">Задач ще немає</td></tr>
    <?php else: foreach ($tasks as $t): ?>
        <tr>
            <td><a href="/tasks/<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
            <td><?= \App\Core\View::e($t['title']) ?></td>
            <td><?= \App\Core\View::e($t['type_name']) ?></td>
            <td><span class="badge bg-secondary"><?= \App\Core\View::e($t['status_name']) ?></span></td>
            <td><?= \App\Core\View::e($t['priority']) ?></td>
            <td><?= \App\Core\View::e($t['assignee_name'] ?? '—') ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
