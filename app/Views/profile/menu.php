<?php $e = static fn($v) => \App\Core\View::e((string) $v); ?>
<div class="mb-3"><a href="/profile" class="text-decoration-none">&larr; Профіль</a></div>
<h3 class="mb-3">Налаштування меню</h3>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>
<div class="card p-4 shadow-sm form-card-md">
    <?php if (!$ready): ?>
        <div class="alert alert-warning mb-0">Налаштування меню ще не підготовлено на сервері (потрібна міграція 048 — запустіть update.sh).</div>
    <?php else: ?>
    <p class="text-muted small">Відмітьте розділи, які бажаєте бачити у верхньому меню. Це лише зручність: сторінки розділів лишаються доступними за прямим посиланням і в пошуку, а права доступу не змінюються.</p>
    <form method="post" action="/profile/menu">
        <?= \App\Core\Csrf::field() ?>
        <?php foreach ($items as $key => $label): ?>
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" name="show[]" value="<?= $e($key) ?>" id="m_<?= $e($key) ?>" <?= in_array($key, $hidden, true) ? '' : 'checked' ?>>
                <label class="form-check-label" for="m_<?= $e($key) ?>"><?= $e($label) ?></label>
            </div>
        <?php endforeach; ?>
        <button class="btn btn-primary mt-2" type="submit">Зберегти</button>
    </form>
    <?php endif; ?>
</div>
