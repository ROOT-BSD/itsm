<div class="mb-3">
    <a href="/" class="text-decoration-none">&larr; Дашборд</a>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">Відкриті задачі</h3>
    <?php $exportUrl = '/tasks/export'; require __DIR__ . '/../_export_button.php'; ?>
</div>

<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr><th>#</th><th>Назва</th><th>Проєкт</th><th>Виконавець</th><th>Пріоритет</th><th>Статус</th><th>Термін</th><th>Створено</th></tr>
    </thead>
    <tbody>
    <?php if (empty($tasks)): ?>
        <tr><td colspan="8" class="text-center text-muted">Відкритих задач немає</td></tr>
    <?php else: foreach ($tasks as $t): ?>
        <tr class="<?= \App\Core\View::urgencyClass($t['due_date'] ?? null) ?>">
            <td><a href="/tasks/<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
            <td><?php if (!empty($t['parent_task_id'])): ?><span class="text-muted" title="Підзадача задачі #<?= (int)$t['parent_task_id'] ?>">↳ </span><?php endif; ?><?= \App\Core\View::e($t['title']) ?>
                <?= \App\Core\View::progress($t) ?></td>
            <td><a href="/projects/<?= (int)$t['project_id'] ?>"><?= \App\Core\View::e($t['project_name']) ?></a></td>
            <td><?= \App\Core\View::e($t['assignee_name'] ?? '—') ?></td>
            <td><?= \App\Core\View::e($t['priority']) ?></td>
            <td><span class="badge bg-secondary"><?= \App\Core\View::e($t['status_name']) ?></span></td>
            <td>
                <?php if (!empty($t['due_date']) && \App\Core\View::urgencyLevel($t['due_date']) === 4): ?>
                    <span class="badge bg-danger">⚠️ <?= \App\Core\View::e($t['due_date']) ?></span>
                <?php elseif (!empty($t['due_date'])): ?>
                    <?= \App\Core\View::e($t['due_date']) ?>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td><?= \App\Core\View::e($t['created_at_formatted']) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
