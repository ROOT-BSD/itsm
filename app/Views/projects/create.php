<h3 class="mb-4">Новий проєкт</h3>

<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($_GET['error']) ?></div>
<?php endif; ?>

<form method="post" action="/projects" class="card p-4 shadow-sm" style="max-width:600px">
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
        <label class="form-label">Видимість</label>
        <select name="visibility" class="form-select">
            <option value="private">Приватний</option>
            <option value="public">Публічний</option>
            <option value="restricted">Обмежений доступ</option>
        </select>
    </div>
    <div class="mb-3">
        <label class="form-label">Відповідальний за проєкт (виконавець)</label>
        <select name="responsible_user_id" class="form-select">
            <option value="">— не призначено —</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= \App\Core\View::e($u['full_name']) ?> (<?= \App\Core\View::e($u['role_name']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Створити</button>
</form>
