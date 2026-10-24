<h3 class="mb-4"><?= $parentProject ? 'Новий підпроєкт для «' . \App\Core\View::e($parentProject['name']) . '»' : 'Новий проєкт' ?></h3>

<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($_GET['error']) ?></div>
<?php endif; ?>

<form method="post" action="/projects" class="card p-4 shadow-sm form-card-md">
    <?= \App\Core\Csrf::field() ?>
    <div class="mb-3">
        <label class="form-label">Назва проєкту</label>
        <input type="text" name="name" class="form-control" required>
    </div>
    <div class="mb-3">
        <label class="form-label">Опис</label>
        <textarea name="description" class="form-control" rows="4"></textarea>
    </div>
    <div class="mb-3">
        <label class="form-label">Батьківський проєкт (необов'язково)</label>
        <select name="parent_id" class="form-select">
            <option value="">— немає, основний проєкт —</option>
            <?php foreach ($projects as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $preselectedParentId === (int)$p['id'] ? 'selected' : '' ?>><?= \App\Core\View::e($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="form-text">Якщо обрати — цей проєкт стане підпроєктом обраного, зі своїми задачами.</div>
    </div>
    <div class="mb-3">
        <label class="form-label">Відповідальний за проєкт (виконавець)</label>
        <select name="responsible_user_id" class="form-select user-select">
            <option value="">— не призначено —</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= \App\Core\View::e($u['full_name']) ?> (<?= \App\Core\View::e($u['role_name']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php $projectTemplates = \App\Models\Template::ready() ? \App\Models\Template::all('project') : []; ?>
    <?php if ($projectTemplates): ?>
    <div class="row g-2 mb-3">
        <div class="col-md-7">
            <label class="form-label">Шаблон проєкту (необов'язково)</label>
            <select name="template_id" class="form-select">
                <option value="">— без шаблону —</option>
                <?php foreach ($projectTemplates as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"><?= \App\Core\View::e($t['name']) ?> (задач: <?= (int)$t['items_count'] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5">
            <label class="form-label">Дата старту</label>
            <input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <?php $roleTemplates = \App\Models\Template::rolesByTemplate(); $roleUsers = $users; require __DIR__ . '/../templates/_role_map.php'; ?>
        <div class="form-text">Шаблон створить у проєкті набір задач (з чек-листами й підзадачами); дати початку й терміну рахуються від дати старту.</div>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">Створити</button>
</form>
