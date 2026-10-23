<div class="row justify-content-center">
    <div class="col-lg-8">
        <h2 class="mb-1">База знань</h2>
        <p class="text-muted">Відповіді на типові запитання й інструкції. Для читання входити в систему й реєструватися не потрібно.</p>
        <form method="get" action="/support/kb" class="input-group mb-3">
            <input type="search" name="q" class="form-control" placeholder="Що шукаєте? Наприклад: VPN, пароль, принтер" value="<?= \App\Core\View::e($q) ?>" maxlength="200">
            <button class="btn btn-primary" type="submit">Знайти</button>
        </form>
        <?php if (!$pages): ?>
            <div class="alert alert-light border"><?= $searched ? 'Нічого не знайдено. Спробуйте інші слова або подайте звернення.' : 'Публічних статей поки немає.' ?></div>
        <?php else: ?>
            <div class="list-group">
                <?php foreach ($pages as $p): ?>
                    <a class="list-group-item list-group-item-action" href="/support/kb/<?= \App\Core\View::e($p['slug']) ?>">
                        <?= \App\Core\View::e($p['title']) ?>
                        <?php if (!empty($p['excerpt'])): ?><div class="small text-muted"><?= \App\Core\View::e($p['excerpt']) ?></div><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="mt-3 text-muted">Не знайшли відповідь? <a href="/support">Подайте звернення</a> без входу.</p>
    </div>
</div>
