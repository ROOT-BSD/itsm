<?php
/** Бічне меню вікі: пошук і список сторінок. Очікує: $sidebarPages, $currentSlug, $canEdit, $query (необов'язково). */
?>
<form method="get" action="/wiki" class="mb-3">
    <div class="input-group">
        <input type="search" name="q" class="form-control" placeholder="Пошук у вікі…" value="<?= \App\Core\View::e($query ?? '') ?>" aria-label="Пошук у вікі">
        <button type="submit" class="btn btn-outline-secondary">Знайти</button>
    </div>
</form>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <a href="/wiki" class="text-decoration-none text-reset fw-semibold">Сторінки</a>
        <?php if (!empty($canEdit)): ?>
            <a href="/wiki/new" class="btn btn-sm btn-primary">+ Нова</a>
        <?php endif; ?>
    </div>
    <?php if (empty($sidebarPages)): ?>
        <div class="card-body text-muted small">Сторінок ще немає.</div>
    <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($sidebarPages as $p): ?>
                <a href="/wiki/<?= \App\Core\View::e($p['slug']) ?>"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center<?= ($currentSlug ?? null) === $p['slug'] ? ' active' : '' ?>"
                   <?php if (!empty($p['depth'])): ?>style="padding-left: <?= 1 + min((int) $p['depth'], 5) * 1.1 ?>rem"<?php endif; ?>>
                    <span><?php if (!empty($p['depth'])): ?><span class="text-muted" aria-hidden="true">↳ </span><?php endif; ?><?= \App\Core\View::e($p['title']) ?></span>
                    <?php if ($p['visibility'] !== 'all'): ?>
                        <span class="badge bg-secondary" title="<?= \App\Core\View::e(\App\Models\WikiPage::VISIBILITIES[$p['visibility']] ?? '') ?>"><?= $p['visibility'] === 'admin' ? 'адмін' : 'персонал' ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
