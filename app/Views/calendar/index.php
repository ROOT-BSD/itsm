<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">Календар — <?= \App\Core\View::e($monthName) ?> <?= (int)$year ?></h3>
    <div class="btn-group">
        <a href="/calendar?year=<?= (int)$prevYear ?>&month=<?= (int)$prevMonth ?>" class="btn btn-outline-secondary">&larr; Попередній</a>
        <a href="/calendar" class="btn btn-outline-secondary">Сьогодні</a>
        <a href="/calendar?year=<?= (int)$nextYear ?>&month=<?= (int)$nextMonth ?>" class="btn btn-outline-secondary">Наступний &rarr;</a>
    </div>
</div>

<table class="table table-bordered bg-white calendar-table">
    <thead>
        <tr class="text-center">
            <th>Пн</th><th>Вт</th><th>Ср</th><th>Чт</th><th>Пт</th><th class="text-danger">Сб</th><th class="text-danger">Нд</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $day = 1;
    // Порожні клітинки перед 1-м числом (firstWeekday: 1=Пн ... 7=Нд)
    $leadingBlanks = $firstWeekday - 1;
    $totalCells = $leadingBlanks + $daysInMonth;
    $rows = (int) ceil($totalCells / 7);
    ?>
    <?php for ($row = 0; $row < $rows; $row++): ?>
        <tr class="calendar-row">
            <?php for ($col = 1; $col <= 7; $col++): ?>
                <?php
                $cellIndex = $row * 7 + $col;
                $isBlank = $cellIndex <= $leadingBlanks || $day > $daysInMonth;
                ?>
                <td class="align-top p-1 calendar-cell <?= (!$isBlank && $day === $todayDay) ? 'bg-warning-subtle' : '' ?>">
                    <?php if (!$isBlank): ?>
                        <div class="fw-bold small text-muted mb-1"><?= $day ?></div>
                        <?php foreach ($tasksByDay[$day] ?? [] as $task): ?>
                            <?php
                            $priorityClass = match ($task['priority']) {
                                'critical' => 'task-badge-critical',
                                'high' => 'task-badge-high',
                                'low' => 'task-badge-low',
                                default => 'task-badge-normal',
                            };
                            $rangeClass = trim(
                                ($task['is_range_start'] ? 'calendar-task-start ' : '')
                                . ($task['is_range_end'] ? 'calendar-task-end' : '')
                            );
                            // Позначки початку/кінця показуємо лише для задач, що тривають
                            // більше одного дня — щоб не захаращувати однодобові задачі.
                            $isMultiDay = $task['start_date'] && $task['due_date'] && $task['start_date'] !== $task['due_date'];
                            $prefix = ($isMultiDay && $task['is_range_start']) ? '▶ ' : '';
                            $suffix = ($isMultiDay && $task['is_range_end']) ? ' ◀' : '';
                            ?>
                            <a href="/tasks/<?= (int)$task['id'] ?>"
                               class="d-block small text-decoration-none mb-1 px-1 rounded <?= $task['is_closed'] ? 'text-muted bg-light text-decoration-line-through' : 'text-white ' . $priorityClass . ' ' . $rangeClass ?>"
                               title="<?= \App\Core\View::e($task['project_name']) ?>: <?= \App\Core\View::e($task['title']) ?> (<?= \App\Core\View::e($task['start_date'] ?? $task['due_date']) ?> — <?= \App\Core\View::e($task['due_date'] ?? $task['start_date']) ?>)">
                                <?= $prefix ?><?= \App\Core\View::e(mb_strimwidth($task['title'], 0, 20, '…')) ?><?= $suffix ?>
                            </a>
                        <?php endforeach; ?>
                        <?php $day++; ?>
                    <?php endif; ?>
                </td>
            <?php endfor; ?>
        </tr>
    <?php endfor; ?>
    </tbody>
</table>

<p class="text-muted small">
    Багатоденна задача показана <strong>лише на початку («▶») та в кінці («◀»)</strong> —
    проміжні дні лишаються порожніми. Задача з однією датою показана один раз, без стрілок.
    Перекреслені — вже закриті задачі. Колір картки відповідає пріоритету
    (червоний — критичний, помаранчевий — високий, синій — звичайний, сірий — низький).
</p>
