<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="/admin" class="text-decoration-none">&larr; Адмін-панель</a>
        <h3 class="mb-0 mt-1">Облік часу — усі проєкти</h3>
    </div>
    <a href="/reports" class="btn btn-primary">📄 Сформувати звіт</a>
</div>

<div class="card mb-4">
    <div class="card-body text-center">
        <div class="display-6"><?= \App\Core\View::e(number_format((float)$totalHours, 2)) ?></div>
        <div class="text-muted">годин витрачено по всій системі</div>
    </div>
</div>

<?php if (!empty($hoursByDay) || !empty($hoursByWeek) || !empty($hoursByMonth)): ?>
<?php
$periods = [
    'day' => ['label' => 'По днях', 'col' => 'День', 'totals' => $hoursByDay, 'byUser' => $hoursByDayUser, 'byProject' => $hoursByDayProject],
    'week' => ['label' => 'По тижнях', 'col' => 'Тиждень (ISO)', 'totals' => $hoursByWeek, 'byUser' => $hoursByWeekUser, 'byProject' => $hoursByWeekProject],
    'month' => ['label' => 'По місяцях', 'col' => 'Місяць', 'totals' => $hoursByMonth, 'byUser' => $hoursByMonthUser, 'byProject' => $hoursByMonthProject],
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
                <div class="col-md-4 border-end">
                    <div class="px-3 pt-2 small text-muted text-uppercase">Загалом <span class="fw-normal">(клікніть на дату — покаже ці записи нижче)</span></div>
                    <table class="table table-sm mb-0">
                        <thead><tr><th><?= $p['col'] ?></th><th>Годин</th></tr></thead>
                        <tbody>
                        <?php foreach ($p['totals'] as $row): ?>
                            <tr>
                                <td>
                                    <a href="#" class="text-decoration-none" onclick="filterAllRecords('<?= $code ?>', <?= htmlspecialchars(json_encode($row['period_label']), ENT_QUOTES) ?>); return false;">
                                        <?= \App\Core\View::e($row['period_label']) ?>
                                    </a>
                                </td>
                                <td><?= \App\Core\View::e(number_format((float)$row['total_hours'], 2)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-4 border-end">
                    <div class="px-3 pt-2 small text-muted text-uppercase">По користувачах</div>
                    <table class="table table-sm mb-0">
                        <thead><tr><th><?= $p['col'] ?></th><th>Хто</th><th>Годин</th></tr></thead>
                        <tbody>
                        <?php foreach ($p['byUser'] as $row): ?>
                            <tr>
                                <td>
                                    <a href="#" class="text-decoration-none" onclick="filterAllRecords('<?= $code ?>', <?= htmlspecialchars(json_encode($row['period_label']), ENT_QUOTES) ?>); return false;">
                                        <?= \App\Core\View::e($row['period_label']) ?>
                                    </a>
                                </td>
                                <td><?= \App\Core\View::e($row['user_name']) ?></td>
                                <td><?= \App\Core\View::e(number_format((float)$row['total_hours'], 2)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-4">
                    <div class="px-3 pt-2 small text-muted text-uppercase">По проєктах</div>
                    <table class="table table-sm mb-0">
                        <thead><tr><th><?= $p['col'] ?></th><th>Проєкт</th><th>Годин</th></tr></thead>
                        <tbody>
                        <?php foreach ($p['byProject'] as $row): ?>
                            <tr>
                                <td>
                                    <a href="#" class="text-decoration-none" onclick="filterAllRecords('<?= $code ?>', <?= htmlspecialchars(json_encode($row['period_label']), ENT_QUOTES) ?>); return false;">
                                        <?= \App\Core\View::e($row['period_label']) ?>
                                    </a>
                                </td>
                                <td><a href="/projects/<?= (int)$row['project_id'] ?>/time"><?= \App\Core\View::e($row['project_name']) ?></a></td>
                                <td><?= \App\Core\View::e(number_format((float)$row['total_hours'], 2)) ?></td>
                            </tr>
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
    <div class="card-header d-flex justify-content-between align-items-center">
        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#all-records-collapse">
            Усі записи ▾
        </button>
        <div id="filter-status" class="small text-muted d-none">
            Показано записи за: <strong id="filter-status-value"></strong>
            <button type="button" class="btn btn-sm btn-link p-0 ms-2" onclick="resetAllRecordsFilter(); return false;">Скинути</button>
        </div>
    </div>
    <div class="collapse" id="all-records-collapse">
        <?php if (empty($timeLogs)): ?>
            <div class="card-body">
                <p class="text-muted mb-0">У системі ще немає жодного запису обліку часу.</p>
            </div>
        <?php else: ?>
            <table class="table table-sm mb-0" id="all-records-table">
                <thead><tr><th>Дата</th><th>Проєкт</th><th>Задача</th><th>Хто</th><th>Годин</th><th>Категорія</th></tr></thead>
                <tbody>
                <?php foreach ($timeLogs as $log): ?>
                    <?php
                    $logTimestamp = strtotime($log['log_date']);
                    $dayLabel = date('d.m.Y', $logTimestamp);
                    $weekLabel = date('o', $logTimestamp) . '-W' . date('W', $logTimestamp);
                    $monthLabel = date('m.Y', $logTimestamp);
                    ?>
                    <tr data-day="<?= \App\Core\View::e($dayLabel) ?>" data-week="<?= \App\Core\View::e($weekLabel) ?>" data-month="<?= \App\Core\View::e($monthLabel) ?>">
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
</div>

<script>
function filterAllRecords(periodType, periodValue) {
    const collapseEl = document.getElementById('all-records-collapse');
    // Розгортаємо спойлер, якщо він ще згорнутий
    if (!collapseEl.classList.contains('show')) {
        new bootstrap.Collapse(collapseEl, { show: true });
    }

    const rows = document.querySelectorAll('#all-records-table tbody tr');
    rows.forEach(function (row) {
        const matches = row.dataset[periodType] === periodValue;
        row.classList.toggle('d-none', !matches);
    });

    const statusEl = document.getElementById('filter-status');
    const statusValueEl = document.getElementById('filter-status-value');
    const periodNames = { day: 'день', week: 'тиждень', month: 'місяць' };
    statusValueEl.textContent = (periodNames[periodType] || periodType) + ' ' + periodValue;
    statusEl.classList.remove('d-none');

    collapseEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function resetAllRecordsFilter() {
    document.querySelectorAll('#all-records-table tbody tr').forEach(function (row) {
        row.classList.remove('d-none');
    });
    document.getElementById('filter-status').classList.add('d-none');
}
</script>
