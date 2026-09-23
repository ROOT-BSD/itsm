<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="/admin" class="text-decoration-none">&larr; Адмін-панель</a>
        <h3 class="mb-0 mt-1">Облік часу — усі проєкти</h3>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body text-center">
        <div class="display-6"><?= \App\Core\View::e(number_format((float)$totalHours, 2)) ?></div>
        <div class="text-muted">годин витрачено по всій системі</div>
    </div>
</div>

<div class="row">
    <?php if (!empty($hoursByProject)): ?>
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">Годин по кожному проєкту</div>
            <table class="table table-sm mb-0">
                <thead><tr><th>Проєкт</th><th>Годин</th></tr></thead>
                <tbody>
                <?php foreach ($hoursByProject as $row): ?>
                    <tr>
                        <td><a href="/projects/<?= (int)$row['project_id'] ?>/time"><?= \App\Core\View::e($row['project_name']) ?></a></td>
                        <td><?= \App\Core\View::e(number_format((float)$row['total_hours'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($hoursByUser)): ?>
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">Годин по кожному учаснику</div>
            <table class="table table-sm mb-0">
                <thead><tr><th>Хто</th><th>Годин</th></tr></thead>
                <tbody>
                <?php foreach ($hoursByUser as $row): ?>
                    <tr>
                        <td><?= \App\Core\View::e($row['user_name']) ?></td>
                        <td><?= \App\Core\View::e(number_format((float)$row['total_hours'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">Усі записи</div>
    <?php if (empty($timeLogs)): ?>
        <div class="card-body">
            <p class="text-muted mb-0">У системі ще немає жодного запису обліку часу.</p>
        </div>
    <?php else: ?>
        <table class="table table-sm mb-0">
            <thead><tr><th>Дата</th><th>Проєкт</th><th>Задача</th><th>Хто</th><th>Годин</th><th>Категорія</th></tr></thead>
            <tbody>
            <?php foreach ($timeLogs as $log): ?>
                <tr>
                    <td><?= \App\Core\View::e($log['log_date']) ?></td>
                    <td><a href="/projects/<?= (int)$log['project_id'] ?>"><?= \App\Core\View::e($log['project_name']) ?></a></td>
                    <td><a href="/tasks/<?= (int)$log['task_id'] ?>">#<?= (int)$log['task_id'] ?> <?= \App\Core\View::e($log['task_title']) ?></a></td>
                    <td><?= \App\Core\View::e($log['user_name']) ?></td>
                    <td><?= \App\Core\View::e((string)$log['hours']) ?></td>
                    <td class="text-muted"><?= \App\Core\View::e($log['activity_category'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
