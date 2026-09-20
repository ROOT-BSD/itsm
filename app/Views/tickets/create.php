<div class="mb-3">
    <a href="/tickets" class="text-decoration-none">&larr; Тікети</a>
</div>

<h3 class="mb-4">Нове звернення до ІТ-підтримки</h3>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>

<form method="post" action="/tickets" class="card p-4 shadow-sm" style="max-width:600px">
    <?= \App\Core\Csrf::field() ?>
    <div class="mb-3">
        <label class="form-label">Черга</label>
        <select name="queue_id" class="form-select" required>
            <?php foreach ($queues as $q): ?>
                <option value="<?= (int)$q['id'] ?>"><?= \App\Core\View::e($q['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label class="form-label">Тема звернення</label>
        <input type="text" name="subject" class="form-control" required>
    </div>
    <div class="mb-3">
        <label class="form-label">Опис проблеми / запиту</label>
        <textarea name="description" class="form-control" rows="4"></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Надіслати звернення</button>
</form>
