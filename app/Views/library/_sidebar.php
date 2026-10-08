<?php
/** Бічне меню бібліотеки: пошук, розділи, створення розділу. Очікує: $sidebar, $currentCategory, $query, $canEdit. */
$e = static fn($v) => \App\Core\View::e($v);
?>
<form method="get" action="/library" class="mb-3">
    <?php if (($currentCategory ?? null) !== null && $currentCategory !== ''): ?>
        <input type="hidden" name="category" value="<?= $e($currentCategory) ?>">
    <?php endif; ?>
    <div class="input-group">
        <input type="search" name="q" class="form-control" maxlength="100" placeholder="Пошук документів…" value="<?= $e($query ?? '') ?>" aria-label="Пошук документів">
        <button type="submit" class="btn btn-outline-secondary">Знайти</button>
    </div>
</form>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Розділи</span>
        <?php if (!empty($canEdit)): ?>
            <a href="/library/new<?= is_numeric($currentCategory ?? null) ? '?category=' . (int) $currentCategory : '' ?>" class="btn btn-sm btn-primary">+ Документ</a>
        <?php endif; ?>
    </div>
    <div class="list-group list-group-flush">
        <a href="/library" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center<?= ($currentCategory ?? null) === null ? ' active' : '' ?>">
            <span>Усі документи</span><span class="badge bg-secondary"><?= (int) $sidebar['total'] ?></span>
        </a>
        <?php foreach ($sidebar['categories'] as $c): ?>
            <a href="/library?category=<?= (int) $c['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center<?= (string) ($currentCategory ?? '') === (string) $c['id'] ? ' active' : '' ?>">
                <span><?= $e($c['name']) ?></span><span class="badge bg-secondary"><?= (int) $c['documents'] ?></span>
            </a>
        <?php endforeach; ?>
        <?php if ((int) $sidebar['uncategorized'] > 0): ?>
            <a href="/library?category=none" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center<?= ($currentCategory ?? null) === 'none' ? ' active' : '' ?>">
                <span class="fst-italic">Без розділу</span><span class="badge bg-secondary"><?= (int) $sidebar['uncategorized'] ?></span>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($canEdit)): ?>
    <form method="post" action="/library/categories" class="card card-body mb-3">
        <?= \App\Core\Csrf::field() ?>
        <label class="form-label small mb-1" for="lib-cat-new">Новий розділ</label>
        <div class="input-group input-group-sm">
            <input type="text" id="lib-cat-new" name="name" class="form-control" maxlength="100" required placeholder="Назва">
            <button type="submit" class="btn btn-outline-primary">Додати</button>
        </div>
    </form>
<?php endif; ?>
