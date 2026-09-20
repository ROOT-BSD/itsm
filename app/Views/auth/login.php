<div class="row justify-content-center">
    <div class="col-md-4">
        <h3 class="mb-4 text-center">Вхід у систему</h3>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/login" class="card p-4 shadow-sm">
            <?= \App\Core\Csrf::field() ?>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">Пароль</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Увійти</button>
            <p class="text-muted small mt-3 mb-0">
                Тестовий обліковий запис: admin@example.local / admin123
            </p>
        </form>
    </div>
</div>
