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
        <?php
        // Сторінки верхнього рівня (без батька) — «розділи»; сторінки, що мають батьком розділ (і глибше), ховаємо під ним.
        // Розділ розгорнутий, якщо відкрито його сторінку чи будь-яку з вкладених.
        $sections = [];
        foreach ($sidebarPages as $p) {
            if (empty($p['depth'])) {
                $sections[] = ['page' => $p, 'children' => []];
            } elseif ($sections) {
                $sections[count($sections) - 1]['children'][] = $p;
            }
        }
        $badge = static function (array $p): string {
            if ($p['visibility'] === 'all') {
                return '';
            }
            $title = \App\Core\View::e(\App\Models\WikiPage::visibilities()[$p['visibility']] ?? '');
            $text = $p['visibility'] === 'admin' ? 'адмін' : ($p['visibility'] === 'portal' ? 'портал' : 'персонал');
            return '<span class="badge ' . ($p['visibility'] === 'portal' ? 'bg-success' : 'bg-secondary') . ' ms-1" title="' . $title . '">' . $text . '</span>';
        };
        ?>
        <div class="list-group list-group-flush">
            <?php foreach ($sections as $i => $sec):
                $p = $sec['page'];
                $kids = $sec['children'];
                $open = ($currentSlug ?? null) === $p['slug'] || in_array($currentSlug ?? null, array_column($kids, 'slug'), true);
                $isCurrent = ($currentSlug ?? null) === $p['slug'];
                $cid = 'wiki-sec-' . $i; ?>
                <div class="list-group-item p-0">
                    <div class="d-flex align-items-center<?= $isCurrent ? ' bg-primary bg-opacity-10' : '' ?>">
                        <?php if ($kids): ?>
                            <button type="button" class="btn btn-sm btn-link text-decoration-none px-2 wiki-sec-toggle" data-bs-toggle="collapse" data-bs-target="#<?= $cid ?>" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="<?= $cid ?>" title="Показати/сховати сторінки розділу"><span aria-hidden="true"><?= $open ? '▾' : '▸' ?></span></button>
                        <?php else: ?>
                            <span class="px-2 text-muted" style="width:2rem;display:inline-block" aria-hidden="true"></span>
                        <?php endif; ?>
                        <a href="/wiki/<?= \App\Core\View::e($p['slug']) ?>" class="flex-grow-1 py-2 pe-2 text-decoration-none fw-semibold<?= $isCurrent ? '' : ' text-reset' ?>"><?= \App\Core\View::e($p['title']) ?><?= $badge($p) ?></a>
                        <?php if ($kids): ?><span class="badge text-bg-light border me-2" title="Сторінок у розділі"><?= count($kids) ?></span><?php endif; ?>
                    </div>
                    <?php if ($kids): ?>
                    <div class="collapse<?= $open ? ' show' : '' ?>" id="<?= $cid ?>">
                        <div class="list-group list-group-flush border-top">
                            <?php foreach ($kids as $k): ?>
                                <a href="/wiki/<?= \App\Core\View::e($k['slug']) ?>"
                                   class="list-group-item list-group-item-action border-0 py-1 small<?= ($currentSlug ?? null) === $k['slug'] ? ' active' : '' ?>"
                                   style="padding-left: <?= 2.2 + max(0, min((int) $k['depth'] - 1, 4)) * 1.1 ?>rem"><?= \App\Core\View::e($k['title']) ?><?= $badge($k) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script nonce="<?= \App\Core\Csp::nonce() ?>">
// Стрілка біля розділу вікі: ▸ / ▾ відповідає стану згортання (Bootstrap collapse).
document.addEventListener('shown.bs.collapse', function (e) { var b = document.querySelector('.wiki-sec-toggle[data-bs-target="#' + e.target.id + '"] span'); if (b) { b.textContent = '▾'; } });
document.addEventListener('hidden.bs.collapse', function (e) { var b = document.querySelector('.wiki-sec-toggle[data-bs-target="#' + e.target.id + '"] span'); if (b) { b.textContent = '▸'; } });
</script>
