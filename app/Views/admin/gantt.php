<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/frappe-gantt/1.2.1/frappe-gantt.min.css">

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="/admin" class="text-decoration-none">&larr; Адмін-панель</a>
        <h3 class="mb-0 mt-1">Діаграма Ганта — усі проєкти</h3>
    </div>
</div>

<div id="gantt-error" class="alert alert-danger d-none"></div>

<?php if (empty($tasks)): ?>
    <p class="text-muted">У системі ще немає задач.</p>
<?php else: ?>
    <div class="card mb-4">
        <div class="card-body p-2 gantt-card-body">
            <svg id="gantt-chart"></svg>
        </div>
    </div>

    <p class="text-muted small">
        Перетягніть смугу задачі, щоб змінити дату початку/завершення — зміна зберігається одразу.
        Назва кожної смуги починається з назви проєкту. Щоб додати нову залежність між задачами —
        відкрийте діаграму Ганта конкретного проєкту (кнопка «📊 Діаграма Ганта» на сторінці проєкту).
    </p>
<?php endif; ?>

<?php if (!empty($tasks)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/frappe-gantt/1.2.1/frappe-gantt.umd.min.js"></script>
<script>
(function () {
    const CSRF_TOKEN = <?= json_encode(\App\Core\Csrf::token()) ?>;
    const errorBox = document.getElementById('gantt-error');

    const PROGRESS_BY_STATUS = { new: 0, in_progress: 40, on_hold: 20, resolved: 90, closed: 100 };

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
            name: <?= json_encode('[' . $t['project_name'] . '] #' . $t['id'] . ' ' . $t['title']) ?>,
            start: <?= json_encode($t['start_date'] ?: date('Y-m-d', strtotime($t['created_at']))) ?>,
            end: <?= json_encode($t['due_date'] ?: date('Y-m-d', strtotime(($t['start_date'] ?: $t['created_at']) . ' +1 day'))) ?>,
            progress: PROGRESS_BY_STATUS[<?= json_encode($t['status_code']) ?>] ?? 0,
            dependencies: (dependenciesByTask[<?= (int)$t['id'] ?>] || []).join(','),
            custom_class: <?= json_encode($t['is_closed'] ? 'gantt-task-closed' : 'gantt-task-' . $t['priority']) ?>
        },
        <?php endforeach; ?>
    ];

    function formatDate(d) {
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

    // Frappe Gantt за замовчуванням прокручує так, щоб "сьогодні" було
    // ПО ЦЕНТРУ видимої області. На загальній діаграмі (усі проєкти,
    // зазвичай багато майбутніх задач) зручніше бачити "сьогодні" зліва,
    // щоб одразу було видно все, що попереду. setTimeout — щоб спрацювати
    // ПІСЛЯ власного автоскролу бібліотеки, а не до нього.
    // today-highlight — це SVG-елемент (rect), тому позицію беремо через
    // getBBox() (координати в системі SVG), а не offsetLeft (він для HTML).
    setTimeout(function () {
        const container = document.querySelector('.gantt-container');
        const todayLine = document.querySelector('.today-highlight');
        if (container && todayLine && typeof todayLine.getBBox === 'function') {
            const bbox = todayLine.getBBox();
            container.scrollLeft = Math.max(0, bbox.x - 40);
        }
    }, 100);
})();
</script>
<?php endif; ?>
