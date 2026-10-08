<?php
$e = static fn($v) => \App\Core\View::e($v);
$human = static fn(int $b) => \App\Core\UploadLimits::human($b);
$currentCat = null;
foreach ($sidebar['categories'] as $c) {
    if ((string) $c['id'] === (string) $currentCategory) {
        $currentCat = $c;
    }
}
$heading = $currentCat ? $currentCat['name'] : ($currentCategory === 'none' ? 'Без розділу' : 'Усі документи');
$pageUrl = static function (int $p) use ($currentCategory, $query): string {
    $q = array_filter(['category' => $currentCategory, 'q' => $query, 'page' => $p > 1 ? $p : null], static fn($v) => $v !== null && $v !== '');
    return '/library' . ($q ? '?' . http_build_query($q) : '');
};
?>
<h3 class="mb-3">Документи</h3>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>

<div class="row">
    <div class="col-lg-3 mb-3"><?php require __DIR__ . '/_sidebar.php'; ?></div>
    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="mb-0"><?= $e($heading) ?><?= $query !== '' ? ' · пошук «' . $e($query) . '»' : '' ?> <span class="text-muted fs-6">(<?= (int) $total ?>)</span></h5>
        </div>

        <?php if ($currentCat && $canEdit): ?>
            <details class="mb-3">
                <summary class="small text-muted">Налаштування розділу</summary>
                <form method="post" action="/library/categories/<?= (int) $currentCat['id'] ?>" class="row g-2 align-items-end mt-1">
                    <?= \App\Core\Csrf::field() ?>
                    <div class="col-sm-6"><label class="form-label small mb-0" for="lib-cat-name">Назва</label>
                        <input type="text" id="lib-cat-name" name="name" class="form-control form-control-sm" maxlength="100" required value="<?= $e($currentCat['name']) ?>"></div>
                    <div class="col-sm-3"><label class="form-label small mb-0" for="lib-cat-sort">Порядок</label>
                        <input type="number" id="lib-cat-sort" name="sort_order" class="form-control form-control-sm" min="0" max="9999" value="<?= (int) $currentCat['sort_order'] ?>"></div>
                    <div class="col-sm-3 d-flex gap-2"><button class="btn btn-sm btn-primary">Зберегти</button></div>
                </form>
                <form method="post" action="/library/categories/<?= (int) $currentCat['id'] ?>/delete" class="mt-2" data-confirm="Видалити розділ «<?= $e($currentCat['name']) ?>»? Документи залишаться, вони переміщуються до «Без розділу».">
                    <?= \App\Core\Csrf::field() ?>
                    <button class="btn btn-sm btn-outline-danger">Видалити розділ</button>
                </form>
            </details>
        <?php endif; ?>

        <?php if (empty($documents)): ?>
            <div class="alert alert-light border">
                <?= $query !== '' ? 'Нічого не знайдено. Спробуйте інше слово або частину назви.' : 'Тут поки немає документів.' ?>
                <?php if ($canEdit && $query === ''): ?><a href="/library/new">Додати документ</a>.<?php endif; ?>
            </div>
        <?php else: ?>
            <table class="table table-bordered bg-white align-middle">
                <thead><tr><th>Документ</th><th>Розділ</th><th class="text-nowrap">Версія</th><th class="text-nowrap">Оновлено</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($documents as $d): ?>
                    <tr>
                        <td>
                            <a href="/library/<?= (int) $d['id'] ?>" class="fw-semibold"><?= $e($d['title']) ?></a>
                            <?php if ($d['visibility'] !== 'all'): ?>
                                <span class="badge bg-secondary" title="<?= $e(\App\Models\LibraryDocument::visibilities()[$d['visibility']] ?? '') ?>"><?= $d['visibility'] === 'admin' ? 'адмін' : 'персонал' ?></span>
                            <?php endif; ?>
                            <div class="small text-muted"><?= $e(strtoupper(pathinfo($d['original_name'], PATHINFO_EXTENSION))) ?> · <?= $e($human((int) $d['size_bytes'])) ?></div>
                        </td>
                        <td class="small"><?= $d['category_name'] !== null ? $e($d['category_name']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-nowrap">v<?= (int) $d['version_no'] ?></td>
                        <td class="text-nowrap small"><?= $e(date('d.m.Y', strtotime($d['version_at']))) ?></td>
                        <td class="text-end"><a href="/library/<?= (int) $d['id'] ?>/download?download=1" class="btn btn-sm btn-outline-secondary">Завантажити</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Сторінки"><ul class="pagination pagination-sm">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item<?= $p === $page ? ' active' : '' ?>"><a class="page-link" href="<?= $e($pageUrl($p)) ?>"><?= $p ?></a></li>
                    <?php endfor; ?>
                </ul></nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
