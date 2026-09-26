<div class="mb-3">
    <a href="/projects/<?= (int)$task['project_id'] ?>" class="text-decoration-none">&larr; <?= \App\Core\View::e($task['project_name']) ?></a>
</div>

<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($_GET['error']) ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h3><?= \App\Core\View::e($task['title']) ?> <small class="text-muted">#<?= (int)$task['id'] ?></small></h3>

        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/description" class="mb-3">
            <?= \App\Core\Csrf::field() ?>
            <label class="form-label">Опис</label>
            <textarea name="description" class="form-control mb-2" rows="4"><?= \App\Core\View::e($task['description'] ?? '') ?></textarea>
            <button class="btn btn-sm btn-outline-primary" type="submit">Зберегти опис</button>
        </form>

        <table class="table table-sm w-auto">
            <tr><th>Тип</th><td><?= \App\Core\View::e($task['type_name']) ?></td></tr>
            <tr><th>Пріоритет</th><td><?= \App\Core\View::e($task['priority']) ?></td></tr>
            <tr><th>Автор</th><td><?= \App\Core\View::e($task['author_name']) ?></td></tr>
            <tr><th>Дата початку</th><td><?= \App\Core\View::e($task['start_date'] ?? '—') ?></td></tr>
            <tr><th>Термін</th><td><?= \App\Core\View::e($task['due_date'] ?? '—') ?></td></tr>
            <tr><th>Витрачено годин</th><td><?= \App\Core\View::e((string)$task['actual_hours']) ?></td></tr>
        </table>

        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/assignee" class="d-flex gap-2 align-items-center mb-2">
    <?= \App\Core\Csrf::field() ?>
            <label class="form-label mb-0">Виконавець:</label>
            <select name="assignee_id" class="form-select w-auto">
                <option value="">— не призначено —</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)($task['assignee_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= \App\Core\View::e($u['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary" type="submit">Призначити</button>
        </form>

        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/milestone" class="d-flex gap-2 align-items-center mb-2">
            <?= \App\Core\Csrf::field() ?>
            <label class="form-label mb-0">Етап:</label>
            <select name="milestone_id" class="form-select w-auto" onchange="this.form.requestSubmit()">
                <option value="">— не прив'язано —</option>
                <?php foreach ($milestones as $m): ?>
                    <option value="<?= (int)$m['id'] ?>" <?= (int)($task['milestone_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
                        <?= \App\Core\View::e($m['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/status" class="d-flex gap-2 align-items-center mb-2">
    <?= \App\Core\Csrf::field() ?>
            <label class="form-label mb-0">Статус:</label>
            <select name="status_id" class="form-select w-auto">
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === (int)$task['status_id']) ? 'selected' : '' ?>><?= \App\Core\View::e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary" type="submit">Оновити</button>
        </form>

        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/project" class="d-flex gap-2 align-items-center mb-3">
            <?= \App\Core\Csrf::field() ?>
            <label class="form-label mb-0">Проєкт:</label>
            <select name="project_id" class="form-select w-auto">
                <?php foreach ($projects as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (int)$task['project_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= \App\Core\View::e($p['name']) ?><?= !empty($p['parent_id']) ? ' (підпроєкт «' . \App\Core\View::e($p['parent_name']) . '»)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary" type="submit">Перенести</button>
        </form>
        <p class="text-muted small">
            Перенесення в інший проєкт знімає прив'язку до етапу дорожньої карти й видаляє зв'язки з задачами, що лишаються в іншому проєкті — залежність можлива лише між задачами одного проєкту.
        </p>

        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/delete"
              onsubmit="return confirm('Остаточно видалити задачу «<?= \App\Core\View::e($task['title']) ?>»? Коментарі, записи обліку часу та зв\'язки з іншими задачами буде видалено разом з нею. Дію не можна скасувати.');">
            <?= \App\Core\Csrf::field() ?>
            <button class="btn btn-sm btn-outline-danger" type="submit">🗑️ Видалити задачу</button>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Облік часу</div>
    <div class="card-body">
        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/time" class="row g-2 align-items-end">
    <?= \App\Core\Csrf::field() ?>
            <div class="col-auto">
                <label class="form-label">Годин</label>
                <input type="number" step="0.25" min="0.25" name="hours" class="form-control" required>
            </div>
            <div class="col-auto">
                <label class="form-label">Дата</label>
                <input type="date" name="log_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-auto">
                <label class="form-label">Категорія</label>
                <input type="text" name="category" class="form-control" placeholder="напр. діагностика">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" type="submit">Додати</button>
            </div>
        </form>

        <?php if (!empty($timeLogs)): ?>
            <table class="table table-sm mt-3 mb-0">
                <thead><tr><th>Дата</th><th>Хто</th><th>Годин</th><th>Категорія</th></tr></thead>
                <tbody>
                <?php foreach ($timeLogs as $log): ?>
                    <tr>
                        <td><?= \App\Core\View::e($log['log_date']) ?></td>
                        <td><?= \App\Core\View::e($log['user_name']) ?></td>
                        <td><?= \App\Core\View::e((string)$log['hours']) ?></td>
                        <td class="text-muted"><?= \App\Core\View::e($log['activity_category'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="text-muted small mt-3 mb-0">Ще жодного запису обліку часу для цієї задачі.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">Коментарі</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($comments as $c): ?>
        <li class="list-group-item">
            <strong><?= \App\Core\View::e($c['author_name']) ?></strong>
            <span class="text-muted small"><?= \App\Core\View::e($c['created_at']) ?></span>
            <p class="mb-0"><?= nl2br(\App\Core\View::e($c['body'])) ?></p>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="card-body">
        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/comments">
    <?= \App\Core\Csrf::field() ?>
            <textarea name="body" class="form-control mb-2" rows="2" placeholder="Написати коментар..." required></textarea>
            <button class="btn btn-sm btn-primary" type="submit">Додати коментар</button>
        </form>
    </div>
</div>
