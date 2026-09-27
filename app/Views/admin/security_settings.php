<div class="mb-3">
    <a href="/admin" class="text-decoration-none">&larr; Адмін-панель</a>
</div>

<h3 class="mb-4">Безпека входу</h3>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= \App\Core\View::e($success) ?></div>
<?php endif; ?>

<p class="text-muted">
    Після вказаної кількості поспіль невдалих спроб введення пароля обліковий запис
    тимчасово блокується — на вказаний час. Блокування стосується лише того одного
    користувача, який помилявся з паролем, і не впливає на інших. Лічильник
    скидається одразу після вдалого входу.
</p>

<form method="post" action="/admin/security" class="card p-4 shadow-sm form-card-md">
    <?= \App\Core\Csrf::field() ?>
    <div class="mb-3">
        <label class="form-label">Кількість невдалих спроб до блокування</label>
        <input type="number" name="max_login_attempts" class="form-control" min="1" max="20" value="<?= (int)$maxAttempts ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label">Тривалість блокування (хвилин)</label>
        <input type="number" name="lockout_minutes" class="form-control" min="1" max="1440" value="<?= (int)$lockoutMinutes ?>" required>
    </div>
    <button type="submit" class="btn btn-primary">Зберегти</button>
</form>

<p class="text-muted small mt-3">
    Заблокований раніше часу обліковий запис можна розблокувати вручну на сторінці
    <a href="/admin/users">«Користувачі»</a> — кнопка «Зняти блокування» з'являється
    там, де вона потрібна.
</p>
