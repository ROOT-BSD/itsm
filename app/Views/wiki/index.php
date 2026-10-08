<h3 class="mb-3">Вікі</h3>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= \App\Core\View::e($success) ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-3 mb-3"><?php require __DIR__ . '/_sidebar.php'; ?></div>
    <div class="col-lg-9">
        <?php if ($results !== null): ?>
            <h5 class="mb-3">Результати пошуку «<?= \App\Core\View::e($query) ?>» — знайдено: <?= count($results) ?></h5>
            <?php if (empty($results)): ?>
                <div class="alert alert-light border">Нічого не знайдено. Спробуйте інше слово або його частину.</div>
            <?php else: ?>
                <div class="list-group">
                    <?php foreach ($results as $r): ?>
                        <a href="/wiki/<?= \App\Core\View::e($r['slug']) ?>" class="list-group-item list-group-item-action">
                            <div class="fw-semibold"><?= \App\Core\View::e($r['title']) ?></div>
                            <div class="small text-muted"><?= $r['snippet_html'] /* уже екрановано контролером */ ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php elseif (empty($sidebarPages)): ?>
            <div class="alert alert-light border">
                У вікі ще немає сторінок.
                <?php if ($canEdit): ?>
                    <a href="/wiki/new">Створити першу сторінку</a>.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="text-muted">Корпоративна база знань: інструкції, регламенти, відповіді на типові запитання.</p>
            <table class="table table-bordered bg-white align-middle">
                <thead><tr><th>Сторінка</th><th>Остання зміна</th><th>Хто змінив</th></tr></thead>
                <tbody>
                <?php foreach ($sidebarPages as $p): ?>
                    <tr>
                        <td<?php if (!empty($p['depth'])): ?> style="padding-left: <?= 0.5 + min((int) $p['depth'], 5) * 1.5 ?>rem"<?php endif; ?>>
                            <?php if (!empty($p['depth'])): ?><span class="text-muted" aria-hidden="true">↳</span> <?php endif; ?>
                            <a href="/wiki/<?= \App\Core\View::e($p['slug']) ?>"><?= \App\Core\View::e($p['title']) ?></a>
                        </td>
                        <td class="text-nowrap"><?= \App\Core\View::e(date('d.m.Y H:i', strtotime($p['updated_at']))) ?></td>
                        <td><?= \App\Core\View::e($p['updated_by_name'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
