<h3 class="mb-4">Нова задача</h3>

<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($_GET['error']) ?></div>
<?php endif; ?>

<form method="post" action="/projects/<?= (int)$projectId ?>/tasks" class="card p-4 shadow-sm form-card-md">
    <?= \App\Core\Csrf::field() ?>
    <div class="mb-3">
        <label class="form-label">Назва</label>
        <input type="text" name="title" class="form-control" required>
    </div>
    <div class="mb-3">
        <label class="form-label">Опис</label>
        <textarea name="description" class="form-control" rows="4"></textarea>
    </div>
    <div class="mb-3">
        <label class="form-label">Тип</label>
        <select name="type_id" class="form-select">
            <?php foreach ($types as $type): ?>
                <option value="<?= (int)$type['id'] ?>"><?= \App\Core\View::e($type['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label class="form-label">Пріоритет</label>
        <select name="priority" class="form-select">
            <option value="low">Низький</option>
            <option value="normal" selected>Звичайний</option>
            <option value="high">Високий</option>
            <option value="critical">Критичний</option>
        </select>
    </div>
    <div class="mb-3">
        <label class="form-label">Дата початку</label>
        <input type="date" name="start_date" class="form-control">
    </div>
    <div class="mb-3">
        <label class="form-label">Термін виконання</label>
        <input type="date" name="due_date" class="form-control">
    </div>
    <div class="mb-3">
        <label class="form-label">Виконавець</label>
        <select name="assignee_id" class="form-select">
            <option value="">— не призначено —</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= \App\Core\View::e($u['full_name']) ?> (<?= \App\Core\View::e($u['role_name']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label class="form-label">Етап (дорожня карта)</label>
        <select name="milestone_id" class="form-select">
            <option value="">— не прив'язано —</option>
            <?php foreach ($milestones as $m): ?>
                <option value="<?= (int)$m['id'] ?>"><?= \App\Core\View::e($m['title']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Створити задачу</button>
</form>
