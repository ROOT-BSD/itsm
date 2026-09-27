<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/frappe-gantt/1.2.1/frappe-gantt.min.css">

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="/projects/<?= (int)$project['id'] ?>" class="text-decoration-none">&larr; <?= \App\Core\View::e($project['name']) ?></a>
        <h3 class="mb-0 mt-1">Діаграма Ганта</h3>
    </div>
    <a href="/projects/<?= (int)$project['id'] ?>/tasks/create" class="btn btn-primary">+ Нова задача</a>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= \App\Core\View::e($success) ?></div>
<?php endif; ?>

<div id="gantt-error" class="alert alert-danger d-none"></div>

<?php if (empty($tasks)): ?>
    <p class="text-muted">У проєкті ще немає задач.</p>
<?php else: ?>
    <div class="card mb-4">
        <div class="card-body p-2 gantt-card-body">
            <svg id="gantt-chart"></svg>
        </div>
    </div>

    <p class="text-muted small">
        Перетягніть смугу задачі, щоб змінити дату початку/завершення — зміна зберігається одразу.
        Задачі без вказаних дат показані орієнтовно (за датою створення) — перше ж перетягування
        встановить для них реальні дати. Стрілки — залежності між задачами (розділ нижче).
    </p>
<?php endif; ?>

<div class="card">
    <div class="card-header">Додати залежність між задачами</div>
    <div class="card-body">
        <?php if (count($tasks) < 2): ?>
            <p class="text-muted small mb-0">Для додавання залежності потрібно щонайменше дві задачі в проєкті.</p>
        <?php else: ?>
            <form method="post" action="/projects/<?= (int)$project['id'] ?>/relations" class="row g-2 align-items-end">
                <?= \App\Core\Csrf::field() ?>
                <div class="col-auto">
                    <label class="form-label small mb-1">Задача</label>
                    <select name="task_id" class="form-select form-select-sm" required>
                        <?php foreach ($tasks as $t): ?>
                            <option value="<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?> <?= \App\Core\View::e($t['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">Зв'язок</label>
                    <select name="relation_type" class="form-select form-select-sm">
                        <option value="blocked_by">залежить від (blocked_by)</option>
                        <option value="blocks">блокує (blocks)</option>
                        <option value="related">пов'язана з (related)</option>
                        <option value="duplicates">дублює (duplicates)</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">Іншої задачі</label>
                    <select name="related_task_id" class="form-select form-select-sm" required>
                        <?php foreach ($tasks as $t): ?>
                            <option value="<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?> <?= \App\Core\View::e($t['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Додати</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if (!empty($relations)): ?>
            <table class="table table-sm mt-3 mb-0">
                <thead><tr><th>Задача</th><th>Зв'язок</th><th>Пов'язана задача</th><th style="width:260px">Дії</th></tr></thead>
                <tbody>
                <?php
                $taskTitleById = [];
                foreach ($tasks as $t) { $taskTitleById[$t['id']] = '#' . $t['id'] . ' ' . $t['title']; }
                $relationLabels = ['blocks' => 'блокує', 'blocked_by' => 'залежить від', 'duplicates' => 'дублює', 'related' => "пов'язана з"];
                ?>
                <?php foreach ($relations as $r): ?>
                    <tr>
                        <td><?= \App\Core\View::e($taskTitleById[$r['task_id']] ?? ('#' . $r['task_id'])) ?></td>
                        <td class="text-muted"><?= \App\Core\View::e($relationLabels[$r['relation_type']] ?? $r['relation_type']) ?></td>
                        <td><?= \App\Core\View::e($taskTitleById[$r['related_task_id']] ?? ('#' . $r['related_task_id'])) ?></td>
                        <td>
                            <form method="post" action="/projects/<?= (int)$project['id'] ?>/relations/<?= (int)$r['id'] ?>" class="d-flex gap-1">
                                <?= \App\Core\Csrf::field() ?>
                                <select name="relation_type" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
                                    <?php foreach ($relationLabels as $code => $label): ?>
                                        <option value="<?= $code ?>" <?= $r['relation_type'] === $code ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <form method="post" action="/projects/<?= (int)$project['id'] ?>/relations/<?= (int)$r['id'] ?>/delete"
                                  onsubmit="return confirm('Видалити цей зв\'язок?');" class="mt-1">
                                <?= \App\Core\Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger">Видалити</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($tasks)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/frappe-gantt/1.2.1/frappe-gantt.umd.min.js"></script>
<script>
(function () {
    const CSRF_TOKEN = <?= json_encode(\App\Core\Csrf::token()) ?>;
    const errorBox = document.getElementById('gantt-error');

    // Прогрес — орієнтовний, за кодом статусу (у системі немає окремого поля "% виконання").
    const PROGRESS_BY_STATUS = { new: 0, in_progress: 40, on_hold: 20, resolved: 90, closed: 100 };

    // Мапа "хто кого блокує" з task_relations -> формат dependencies для Frappe Gantt
    // (dependencies задачі X — це список ID задач, які мають завершитись ПЕРЕД X).
    const dependenciesByTask = {};
    <?php foreach ($relations as $r): ?>
        <?php if ($r['relation_type'] === 'blocked_by'): ?>
            (dependenciesByTask[<?= (int)$r['task_id'] ?>] ??= []).push(<?= (int)$r['related_task_id'] ?>);
        <?php elseif ($r['relation_type'] === 'blocks'): ?>
            (dependenciesByTask[<?= (int)$r['related_task_id'] ?>] ??= []).push(<?= (int)$r['task_id'] ?>);
        <?php endif; ?>
    <?php endforeach; ?>

    const tasks = [
        <?php foreach ($tasks as $t): ?>
        {
            id: "<?= (int)$t['id'] ?>",
            name: <?= json_encode('#' . $t['id'] . ' ' . $t['title']) ?>,
            start: <?= json_encode($t['start_date'] ?: date('Y-m-d', strtotime($t['created_at']))) ?>,
            end: <?= json_encode($t['due_date'] ?: date('Y-m-d', strtotime(($t['start_date'] ?: $t['created_at']) . ' +1 day'))) ?>,
            progress: PROGRESS_BY_STATUS[<?= json_encode($t['status_code']) ?>] ?? 0,
            dependencies: (dependenciesByTask[<?= (int)$t['id'] ?>] || []).join(','),
            custom_class: <?= json_encode($t['is_closed'] ? 'gantt-task-closed' : 'gantt-task-' . $t['priority']) ?>
        },
        <?php endforeach; ?>
    ];

    function formatDate(d) {
        // Локальна дата без зсуву часового поясу (на відміну від toISOString, який дає UTC)
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return `${yyyy}-${mm}-${dd}`;
    }

    const gantt = new Gantt('#gantt-chart', tasks, {
        view_mode: 'Week',
        language: 'en',
        on_click: function (task) {
            window.location.href = '/tasks/' + task.id;
        },
        on_date_change: function (task, start, end) {
            errorBox.classList.add('d-none');
            fetch('/tasks/' + task.id + '/dates', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'start_date=' + encodeURIComponent(formatDate(start))
                    + '&due_date=' + encodeURIComponent(formatDate(end))
                    + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
            }).then(async function (response) {
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    throw new Error(data.error || ('HTTP ' + response.status));
                }
            }).catch(function (err) {
                errorBox.textContent = 'Не вдалося зберегти нові дати: ' + err.message + '. Оновіть сторінку.';
                errorBox.classList.remove('d-none');
            });
        }
    });

    // Frappe Gantt за замовчуванням НЕ гарантує видимість "сьогодні" — сам
    // прокручує ближче до початку всього діапазону задач, тож "сьогодні"
    // може опинитися далеко за межами видимої області, якщо є давні задачі
    // в минулому. Тому примусово прокручуємо так, щоб "сьогодні" було
    // біля лівого краю. setTimeout — щоб спрацювати ПІСЛЯ власного
    // автоскролу бібліотеки. Клас називається .current-highlight у
    // Frappe Gantt 1.x (перевірено безпосередньо headless-браузером на
    // реальній сторінці — той самий фікс, що вже підтверджено на
    // /admin/gantt, тут лише перенесений на дошку одного проєкту).
    setTimeout(function () {
        const container = document.querySelector('.gantt-container');
        const todayLine = document.querySelector('.current-highlight');
        if (container && todayLine) {
            container.scrollLeft = Math.max(0, todayLine.offsetLeft - 40);
        }
    }, 150);
})();
</script>
<?php endif; ?>
