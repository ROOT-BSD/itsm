<h3 class="mb-4">Дашборд</h3>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card text-center p-3"><h2><?= (int)$stats['active_projects'] ?></h2><div class="text-muted">Активні проєкти</div></div>
    </div>
    <div class="col-md-4">
        <div class="card text-center p-3"><h2><?= (int)$stats['open_tasks'] ?></h2><div class="text-muted">Відкриті задачі</div></div>
    </div>
    <div class="col-md-4">
        <div class="card text-center p-3"><h2><?= (int)$stats['open_tickets'] ?></h2><div class="text-muted">Відкриті тікети</div></div>
    </div>
</div>

<h5>Мої відкриті задачі</h5>
<table class="table table-bordered bg-white">
    <thead><tr><th>#</th><th>Назва</th><th>Проєкт</th><th>Пріоритет</th><th>Статус</th></tr></thead>
    <tbody>
    <?php if (empty($myOpenTasks)): ?>
        <tr><td colspan="5" class="text-center text-muted">Немає призначених відкритих задач</td></tr>
    <?php else: foreach ($myOpenTasks as $t): ?>
        <tr>
            <td><a href="/tasks/<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
            <td><?= \App\Core\View::e($t['title']) ?></td>
            <td><?= \App\Core\View::e($t['project_name']) ?></td>
            <td><?= \App\Core\View::e($t['priority']) ?></td>
            <td><?= \App\Core\View::e($t['status_name']) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
