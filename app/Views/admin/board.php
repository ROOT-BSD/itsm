<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="/admin" class="text-decoration-none">&larr; Адмін-панель</a>
        <h3 class="mb-0 mt-1">Канбан-дошка — усі проєкти</h3>
    </div>
</div>

<div id="kanban-error" class="alert alert-danger d-none"></div>

<div class="d-flex gap-2" id="kanban-board">
    <?php foreach ($statuses as $status): ?>
        <?php $columnTasks = $tasksByStatus[$status['id']] ?? []; ?>
        <div class="kanban-column-wrapper">
            <div class="bg-light rounded p-2 mb-2 d-flex justify-content-between align-items-center border kanban-column-header">
                <strong class="text-truncate"><?= \App\Core\View::e($status['name']) ?></strong>
                <span class="badge bg-secondary kanban-count flex-shrink-0"><?= count($columnTasks) ?></span>
            </div>
            <div class="kanban-column border rounded p-2 bg-white"
                 data-status-id="<?= (int)$status['id'] ?>">
                <?php foreach ($columnTasks as $task): ?>
                    <div class="card mb-2 kanban-card" draggable="true" data-task-id="<?= (int)$task['id'] ?>">
                        <div class="card-body p-1 kanban-card-compact">
                            <span class="badge bg-light text-dark border mb-1"><?= \App\Core\View::e($task['project_name']) ?></span>
                            <br>
                            <a href="/tasks/<?= (int)$task['id'] ?>" class="fw-semibold text-decoration-none">
                                #<?= (int)$task['id'] ?> <?= \App\Core\View::e($task['title']) ?>
                            </a>
                            <div class="small text-muted mt-1">
                                <?= \App\Core\View::e($task['type_name']) ?> ·
                                <span class="badge bg-<?php
                                    echo match ($task['priority']) {
                                        'critical' => 'danger',
                                        'high' => 'warning',
                                        'low' => 'secondary',
                                        default => 'info',
                                    };
                                ?>"><?= \App\Core\View::e($task['priority']) ?></span>
                            </div>
                            <div class="small text-muted"><?= \App\Core\View::e($task['assignee_name'] ?? '— не призначено —') ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
(function () {
    const CSRF_TOKEN = <?= json_encode(\App\Core\Csrf::token()) ?>;
    const board = document.getElementById('kanban-board');
    const errorBox = document.getElementById('kanban-error');
    let draggedCard = null;

    board.addEventListener('dragstart', function (e) {
        const card = e.target.closest('.kanban-card');
        if (!card) return;
        draggedCard = card;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', card.dataset.taskId);
        setTimeout(() => card.style.opacity = '0.4', 0);
    });

    board.addEventListener('dragend', function () {
        if (draggedCard) draggedCard.style.opacity = '1';
        draggedCard = null;
    });

    board.querySelectorAll('.kanban-column').forEach(function (column) {
        column.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        });

        column.addEventListener('drop', function (e) {
            e.preventDefault();
            if (!draggedCard) return;

            const taskId = draggedCard.dataset.taskId;
            const newStatusId = column.dataset.statusId;
            const oldColumn = draggedCard.closest('.kanban-column');

            if (oldColumn === column) return;

            errorBox.classList.add('d-none');
            column.appendChild(draggedCard);
            updateColumnCounts();

            fetch('/tasks/' + taskId + '/status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'status_id=' + encodeURIComponent(newStatusId) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
            }).catch(function () {
                oldColumn.appendChild(draggedCard);
                updateColumnCounts();
                errorBox.textContent = 'Не вдалося оновити статус задачі. Оновіть сторінку і спробуйте ще раз.';
                errorBox.classList.remove('d-none');
            });
        });
    });

    function updateColumnCounts() {
        board.querySelectorAll('.kanban-column-wrapper').forEach(function (col) {
            const count = col.querySelectorAll('.kanban-card').length;
            const badge = col.querySelector('.kanban-count');
            if (badge) badge.textContent = count;
        });
    }
})();
</script>
