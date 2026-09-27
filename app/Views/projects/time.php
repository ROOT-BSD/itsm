<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="/projects/<?= (int)$project['id'] ?>" class="text-decoration-none">&larr; <?= \App\Core\View::e($project['name']) ?></a>
        <h3 class="mb-0 mt-1">Облік часу — проєкт</h3>
    </div>
    <a href="/reports?project_id=<?= (int)$project['id'] ?>" class="btn btn-primary">📄 Сформувати звіт</a>
</div>

<div class="card mb-4">
    <div class="card-body text-center">
        <div class="display-6"><?= \App\Core\View::e(number_format((float)$totalHours, 2)) ?></div>
        <div class="text-muted">годин витрачено на весь проєкт</div>
    </div>
</div>

<?php if (!empty($hoursByDay) || !empty($hoursByWeek) || !empty($hoursByMonth)): ?>
<?php
$periods = [
    'day' => ['label' => 'По днях', 'col' => 'День', 'totals' => $hoursByDay, 'byUser' => $hoursByDayUser],
    'week' => ['label' => 'По тижнях', 'col' => 'Тиждень (ISO)', 'totals' => $hoursByWeek, 'byUser' => $hoursByWeekUser],
    'month' => ['label' => 'По місяцях', 'col' => 'Місяць', 'totals' => $hoursByMonth, 'byUser' => $hoursByMonthUser],
];
?>
<div class="card mb-4">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
            <?php $first = true; foreach ($periods as $code => $p): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $first ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#period-<?= $code ?>" type="button"><?= $p['label'] ?></button>
                </li>
            <?php $first = false; endforeach; ?>
        </ul>
    </div>
    <div class="tab-content">
        <?php $first = true; foreach ($periods as $code => $p): ?>
        <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="period-<?= $code ?>">
            <div class="row g-0">
                <div class="col-md-6 border-end">
                    <div class="px-3 pt-2 small text-muted text-uppercase">Загалом</div>
                    <table class="table table-sm mb-0">
                        <thead><tr><th><?= $p['col'] ?></th><th>Годин</th></tr></thead>
                        <tbody>
                        <?php foreach ($p['totals'] as $row): ?>
                            <tr><td><?= \App\Core\View::e($row['period_label']) ?></td><td><?= \App\Core\View::e(number_format((float)$row['total_hours'], 2)) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <div class="px-3 pt-2 small text-muted text-uppercase">По користувачах</div>
                    <table class="table table-sm mb-0">
                        <thead><tr><th><?= $p['col'] ?></th><th>Хто</th><th>Годин</th></tr></thead>
                        <tbody>
                        <?php foreach ($p['byUser'] as $row): ?>
                            <tr><td><?= \App\Core\View::e($row['period_label']) ?></td><td><?= \App\Core\View::e($row['user_name']) ?></td><td><?= \App\Core\View::e(number_format((float)$row['total_hours'], 2)) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php $first = false; endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($hoursByUser)): ?>
<div class="card mb-4">
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
<?php endif; ?>

<div class="card">
    <div class="card-header">Усі записи</div>
    <?php if (empty($timeLogs)): ?>
        <div class="card-body">
            <p class="text-muted mb-0">У цьому проєкті ще немає жодного запису обліку часу.</p>
        </div>
    <?php else: ?>
        <table class="table table-sm mb-0">
            <thead><tr><th>Дата</th><th>Задача</th><th>Хто</th><th>Годин</th><th>Категорія</th></tr></thead>
            <tbody>
            <?php foreach ($timeLogs as $log): ?>
                <tr>
                    <td><?= \App\Core\View::e($log['log_date']) ?></td>
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
