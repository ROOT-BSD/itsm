<div class="mb-3">
    <a href="/" class="text-decoration-none">&larr; Дашборд</a>
</div>

<h3 class="mb-4">Відкриті задачі</h3>

<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr><th>#</th><th>Назва</th><th>Проєкт</th><th>Виконавець</th><th>Пріоритет</th><th>Статус</th><th>Створено</th></tr>
    </thead>
    <tbody>
    <?php if (empty($tasks)): ?>
        <tr><td colspan="7" class="text-center text-muted">Відкритих задач немає</td></tr>
    <?php else: foreach ($tasks as $t): ?>
        <tr>
            <td><a href="/tasks/<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
            <td><?= \App\Core\View::e($t['title']) ?></td>
            <td><a href="/projects/<?= (int)$t['project_id'] ?>"><?= \App\Core\View::e($t['project_name']) ?></a></td>
            <td><?= \App\Core\View::e($t['assignee_name'] ?? '—') ?></td>
            <td><?= \App\Core\View::e($t['priority']) ?></td>
            <td><span class="badge bg-secondary"><?= \App\Core\View::e($t['status_name']) ?></span></td>
            <td><?= \App\Core\View::e($t['created_at_formatted']) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
