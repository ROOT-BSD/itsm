<div class="row justify-content-center">
    <div class="col-md-4">
        <div class="text-center mb-3">
            <a href="/support" class="btn btn-outline-secondary btn-sm">📩 Подати звернення до ІТ-підтримки без входу</a>
        </div>

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
        </form>
        <?php if (!empty($ssoEnabled)): ?>
            <div class="text-center mt-3">
                <a href="/sso/login" class="btn btn-outline-primary w-100">Увійти через Windows (без пароля)</a>
                <div class="form-text">Для комп'ютерів домену: вхід за вашим обліковим записом Windows.</div>
            </div>
        <?php endif; ?>
    </div>
</div>
