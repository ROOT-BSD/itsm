<?php $e = static fn($v) => \App\Core\View::e($v); $isNew = $board === null; require __DIR__ . '/_header.php'; ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/forum">Форум</a></li><li class="breadcrumb-item active"><?= $isNew ? 'Новий розділ' : 'Налаштування: ' . $e($board['name']) ?></li></ol></nav>
<form method="post" action="<?= $isNew ? '/forum/boards' : '/forum/boards/' . (int) $board['id'] ?>" class="card card-body shadow-sm">
    <?= \App\Core\Csrf::field() ?>
    <div class="row g-3">
        <div class="col-md-8"><label class="form-label" for="fb-name">Назва</label>
            <input type="text" id="fb-name" name="name" class="form-control" maxlength="100" required value="<?= $e($form['name']) ?>"></div>
        <div class="col-md-4"><label class="form-label" for="fb-sort">Порядок у списку</label>
            <input type="number" id="fb-sort" name="sort_order" class="form-control" min="0" max="9999" value="<?= (int) $form['sort_order'] ?>"></div>
        <div class="col-12"><label class="form-label" for="fb-desc">Опис (необов'язково)</label>
            <input type="text" id="fb-desc" name="description" class="form-control" maxlength="500" value="<?= $e($form['description']) ?>"></div>
        <div class="col-md-8"><label class="form-label" for="fb-vis">Хто бачить</label>
            <select id="fb-vis" name="visibility" class="form-select">
                <?php foreach ($visibilities as $value => $label): ?><option value="<?= $e($value) ?>" <?= $form['visibility'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-4 d-flex align-items-end"><div class="form-check"><input type="checkbox" class="form-check-input" id="fb-lock" name="is_locked" value="1" <?= !empty($form['is_locked']) ? 'checked' : '' ?>><label class="form-check-label" for="fb-lock">Закрити для нових тем</label></div></div>
    </div>
    <div class="d-flex gap-2 mt-3"><button class="btn btn-primary"><?= $isNew ? 'Створити розділ' : 'Зберегти' ?></button><a href="<?= $isNew ? '/forum' : '/forum/boards/' . (int) $board['id'] ?>" class="btn btn-outline-secondary">Скасувати</a></div>
</form>
<?php if (!$isNew && \App\Core\Auth::hasRole(['admin'])): ?>
    <form method="post" action="/forum/boards/<?= (int) $board['id'] ?>/delete" class="mt-3" data-confirm="Видалити розділ «<?= $e($board['name']) ?>» РАЗОМ З УСІМА ТЕМАМИ І ПОВІДОМЛЕННЯМИ? Це незворотно.">
        <?= \App\Core\Csrf::field() ?><button class="btn btn-outline-danger btn-sm">Видалити розділ з усіма темами</button>
    </form>
<?php endif; ?>
