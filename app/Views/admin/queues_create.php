<div class="mb-3">
    <a href="/admin/queues" class="text-decoration-none">&larr; Черги тікетів</a>
</div>

<h3 class="mb-4">Нова черга</h3>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>

<form method="post" action="/admin/queues" class="card p-4 shadow-sm form-card-sm">
    <?= \App\Core\Csrf::field() ?>
    <div class="mb-3">
        <label class="form-label">Назва черги</label>
        <input type="text" name="name" class="form-control" required autofocus placeholder="напр. Мережева підтримка">
    </div>
    <div class="mb-3">
        <label class="form-label">Опис (необов'язково)</label>
        <textarea name="description" class="form-control" rows="3" placeholder="Для яких звернень призначена ця черга"></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Створити чергу</button>
</form>
