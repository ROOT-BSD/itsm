<div class="mb-3">
    <a href="/admin/queues" class="text-decoration-none">&larr; Черги тікетів</a>
</div>

<?php $q = $queue ?? null; ?>
<h3 class="mb-4"><?= $q ? 'Зміна черги' : 'Нова черга' ?></h3>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>

<form method="post" action="<?= $q ? '/admin/queues/' . (int) $q['id'] . '/edit' : '/admin/queues' ?>" class="card p-4 shadow-sm form-card-sm">
    <?= \App\Core\Csrf::field() ?>
    <div class="mb-3">
        <label class="form-label">Назва черги</label>
        <input type="text" name="name" class="form-control" required autofocus maxlength="100" placeholder="напр. Мережева підтримка" value="<?= \App\Core\View::e($q['name'] ?? '') ?>">
    </div>
    <div class="mb-3">
        <label class="form-label">Опис (необов'язково)</label>
        <textarea name="description" class="form-control" rows="3" maxlength="255" placeholder="Для яких звернень призначена ця черга"><?= \App\Core\View::e($q['description'] ?? '') ?></textarea>
    </div>
    <button type="submit" class="btn btn-primary"><?= $q ? 'Зберегти' : 'Створити чергу' ?></button>
</form>
