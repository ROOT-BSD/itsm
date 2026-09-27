<h3 class="mb-4">Дашборд</h3>

<div class="row mb-4">
    <div class="col-md-3">
        <a href="/projects" class="text-decoration-none text-reset">
            <div class="card text-center p-3 h-100 shadow-sm"><h2><?= (int)$stats['active_projects'] ?></h2><div class="text-muted">Активні проєкти</div></div>
        </a>
    </div>
    <div class="col-md-3">
        <div class="card text-center p-3 h-100 shadow-sm"><h2><?= (int)$stats['active_subprojects'] ?></h2><div class="text-muted">Підпроєкти</div></div>
    </div>
    <div class="col-md-3">
        <a href="/tasks" class="text-decoration-none text-reset">
            <div class="card text-center p-3 h-100 shadow-sm"><h2><?= (int)$stats['open_tasks'] ?></h2><div class="text-muted">Відкриті задачі</div></div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="/tickets" class="text-decoration-none text-reset">
            <div class="card text-center p-3 h-100 shadow-sm"><h2><?= (int)$stats['open_tickets'] ?></h2><div class="text-muted">Відкриті тікети</div></div>
        </a>
    </div>
</div>

<h5>Мої відкриті задачі</h5>
<table class="table table-bordered bg-white">
    <thead><tr><th>#</th><th>Назва</th><th>Проєкт</th><th>Пріоритет</th><th>Статус</th><th>Термін</th><th>Створено</th></tr></thead>
    <tbody>
    <?php if (empty($myOpenTasks)): ?>
        <tr><td colspan="7" class="text-center text-muted">Немає призначених відкритих задач</td></tr>
    <?php else: foreach ($myOpenTasks as $t): ?>
        <tr>
            <td><a href="/tasks/<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
            <td><?= \App\Core\View::e($t['title']) ?></td>
            <td><?= \App\Core\View::e($t['project_name']) ?></td>
            <td><?= \App\Core\View::e($t['priority']) ?></td>
            <td><?= \App\Core\View::e($t['status_name']) ?></td>
            <td>
                <?php if (!empty($t['due_date']) && $t['due_date'] < date('Y-m-d')): ?>
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
