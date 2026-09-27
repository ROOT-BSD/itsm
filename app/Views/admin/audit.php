<div class="mb-3">
    <a href="/admin" class="text-decoration-none">&larr; Адмін-панель</a>
</div>

<h3 class="mb-4">Журнал аудиту</h3>

<?php
// Українські підписи для типів сутностей — використовуються і у фільтрі, і в таблиці.
$entityTypeLabels = [
    'project' => 'Проєкт',
    'task' => 'Задача',
    'ticket' => 'Тікет',
    'milestone' => 'Етап',
    'task_relation' => "Зв'язок задач",
    'ticket_queue' => 'Черга тікетів',
    'user' => 'Користувач',
];

// Підписи для полів усередині JSON-колонки "changes" — щоб замість
// "status_id: 3" показувати "Статус: В роботі".
$changeKeyLabels = [
    'status_id' => 'Статус',
    'assignee_id' => 'Виконавець',
    'responsible_user_id' => 'Відповідальний',
    'operator_id' => 'Оператор',
    'milestone_id' => 'Етап',
    'related_task_id' => "Пов'язана задача",
    'relation_type' => "Тип зв'язку",
    'start_date' => 'Дата початку',
    'due_date' => 'Термін виконання',
    'name' => 'Назва',
    'title' => 'Назва',
    'email' => 'Email',
];

// Значення поля relation_type — той самий переклад, що й на сторінці Ганта.
$relationTypeLabels = ['blocks' => 'блокує', 'blocked_by' => 'залежить від', 'duplicates' => 'дублює', 'related' => "пов'язана з"];

/** Перші 2 слова назви — щоб довга назва не розтягувала колонку. */
$shortenName = function (string $text): string {
    $words = preg_split('/\s+/u', trim($text));
    $short = implode(' ', array_slice($words, 0, 2));
    return count($words) > 2 ? $short . '…' : $short;
};
?>

<form method="get" action="/admin/audit" class="card p-3 shadow-sm mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small mb-1">Тип сутності</label>
            <select name="entity_type" class="form-select form-select-sm">
                <option value="">— усі —</option>
                <?php foreach ($entityTypes as $type): ?>
                    <option value="<?= \App\Core\View::e($type) ?>" <?= $filters['entity_type'] === $type ? 'selected' : '' ?>>
                        <?= \App\Core\View::e($entityTypeLabels[$type] ?? $type) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Хто виконав</label>
            <select name="user_id" class="form-select form-select-sm">
                <option value="">— усі —</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)($filters['user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= \App\Core\View::e($u['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">З дати</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= \App\Core\View::e($filters['date_from']) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">По дату</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= \App\Core\View::e($filters['date_to']) ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary btn-sm w-100">Фільтрувати</button>
        </div>
    </div>
</form>

<p class="text-muted small">Знайдено записів: <?= (int)$total ?></p>

<?php
$entityLinks = ['task' => '/tasks/', 'project' => '/projects/', 'ticket' => '/tickets/'];
?>

<?php if (empty($entries)): ?>
    <p class="text-muted">Нічого не знайдено за обраними фільтрами.</p>
<?php else: ?>
    <table class="table table-sm">
        <thead><tr><th>Дата й час</th><th>Тип</th><th>Сутність</th><th>Дія</th><th>Хто</th><th>Деталі</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
            <?php
            $type = $entry['entity_type'];
            $id = (int) $entry['entity_id'];
            $resolvedName = $entityNames[$type][$id] ?? null;
            $shortName = $resolvedName !== null ? $shortenName($resolvedName) : null;
            ?>
            <tr>
                <td><?= \App\Core\View::e($entry['created_at']) ?></td>
                <td><?= \App\Core\View::e($entityTypeLabels[$type] ?? $type) ?></td>
                <td>
                    <?php if (isset($entityLinks[$type])): ?>
                        <a href="<?= $entityLinks[$type] . $id ?>">#<?= $id ?><?= $shortName !== null ? ' ' . \App\Core\View::e($shortName) : '' ?></a>
                    <?php else: ?>
                        #<?= $id ?><?= $shortName !== null ? ' ' . \App\Core\View::e($shortName) : '' ?>
                    <?php endif; ?>
                </td>
                <td><?= \App\Core\View::e($entry['action']) ?></td>
                <td><?= \App\Core\View::e($entry['user_name'] ?? '— система —') ?></td>
                <td class="small text-muted">
                    <?php if (!empty($entry['changes'])): ?>
                        <?php
                        $decoded = json_decode($entry['changes'], true);
                        $pairs = [];
                        if (is_array($decoded)) {
                            foreach ($decoded as $key => $value) {
                                $label = $changeKeyLabels[$key] ?? $key;
                                if ($value === null) {
                                    $displayValue = '—';
                                } elseif ($key === 'relation_type') {
                                    $displayValue = $relationTypeLabels[$value] ?? (string) $value;
                                } elseif (isset($referencedNames[$key][(int) $value])) {
                                    $displayValue = $referencedNames[$key][(int) $value];
                                } else {
                                    $displayValue = (string) $value;
                                }
                                $pairs[] = \App\Core\View::e($label) . ': ' . \App\Core\View::e($displayValue);
                            }
                        }
                        echo implode(', ', $pairs);
                        ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
        <?php
        $queryWithoutPage = $filters;
        $queryWithoutPage['user_id'] = $filters['user_id'] ?: '';
        ?>
        <nav class="d-flex justify-content-between align-items-center">
            <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>"
               href="?<?= http_build_query(array_merge($queryWithoutPage, ['page' => $page - 1])) ?>">&larr; Попередня</a>
            <span class="text-muted small">Сторінка <?= $page ?> з <?= $totalPages ?></span>
            <a class="btn btn-sm btn-outline-secondary <?= $page >= $totalPages ? 'disabled' : '' ?>"
               href="?<?= http_build_query(array_merge($queryWithoutPage, ['page' => $page + 1])) ?>">Наступна &rarr;</a>
        </nav>
    <?php endif; ?>
<?php endif; ?>
