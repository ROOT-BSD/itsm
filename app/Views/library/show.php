<?php
$e = static fn($v) => \App\Core\View::e($v);
$human = static fn(int $b) => \App\Core\UploadLimits::human($b);
$latest = $versions[0] ?? null;
$id = (int) $document['id'];
$maxFile = \App\Services\LibraryService::maxBytes();
?>
<h3 class="mb-3">Документи</h3>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>

<div class="row">
    <div class="col-lg-3 mb-3"><?php require __DIR__ . '/_sidebar.php'; ?></div>
    <div class="col-lg-9">
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <h4 class="mb-1"><?= $e($document['title']) ?></h4>
                        <div class="small text-muted">
                            Розділ: <?= $document['category_name'] !== null ? '<a href="/library?category=' . (int) $document['category_id'] . '">' . $e($document['category_name']) . '</a>' : 'без розділу' ?>
                            · Бачить: <?= $e(\App\Models\LibraryDocument::visibilities()[$document['visibility']] ?? $document['visibility']) ?>
                        </div>
                    </div>
                    <?php if ($canEdit): ?>
                        <a href="/library/<?= $id ?>/edit" class="btn btn-sm btn-outline-secondary text-nowrap">Редагувати</a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($document['description'])): ?>
                    <p class="mt-3 mb-0" style="white-space: pre-line"><?= $e($document['description']) ?></p>
                <?php endif; ?>

                <?php if ($latest): ?>
                    <div class="border rounded p-3 mt-3 d-flex flex-wrap justify-content-between align-items-center gap-2 bg-light">
                        <div>
                            <div class="fw-semibold"><?= $e($latest['original_name']) ?></div>
                            <div class="small text-muted">версія <?= (int) $latest['version_no'] ?> · <?= $e($human((int) $latest['size_bytes'])) ?> · <?= $e(date('d.m.Y H:i', strtotime($latest['created_at']))) ?></div>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if (in_array($latest['mime_type'], \App\Services\LibraryService::INLINE_MIMES, true)): ?>
                                <a href="/library/<?= $id ?>/download" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">Відкрити</a>
                            <?php endif; ?>
                            <a href="/library/<?= $id ?>/download?download=1" class="btn btn-sm btn-primary">Завантажити</a>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="small text-muted mt-3">
                    Додав(ла): <?= $e($document['created_by_name'] ?? 'користувача видалено') ?>, <?= $e(date('d.m.Y', strtotime($document['created_at']))) ?>
                    <?php if ($document['updated_by_name'] !== null): ?> · остання зміна: <?= $e($document['updated_by_name']) ?>, <?= $e(date('d.m.Y H:i', strtotime($document['updated_at']))) ?><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Історія версій (<?= count($versions) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($versions as $v): ?>
                    <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <span class="badge <?= $v === $latest ? 'bg-success' : 'bg-secondary' ?>">v<?= (int) $v['version_no'] ?></span>
                            <a href="/library/versions/<?= (int) $v['id'] ?>/download?download=1"><?= $e($v['original_name']) ?></a>
                            <div class="small text-muted">
                                <?= $e($human((int) $v['size_bytes'])) ?> · <?= $e($v['uploader_name'] ?? 'користувача видалено') ?> · <?= $e(date('d.m.Y H:i', strtotime($v['created_at']))) ?>
                                <?php if (!empty($v['comment'])): ?> · «<?= $e($v['comment']) ?>»<?php endif; ?>
                            </div>
                        </div>
                        <?php if ($canDelete && count($versions) > 1): ?>
                            <form method="post" action="/library/versions/<?= (int) $v['id'] ?>/delete" data-confirm="Видалити версію <?= (int) $v['version_no'] ?>? Файл буде видалено безповоротно.">
                                <?= \App\Core\Csrf::field() ?>
                                <button class="btn btn-sm btn-outline-danger">Видалити</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php if ($canEdit): ?>
            <div class="card mb-4">
                <div class="card-header">Завантажити нову версію</div>
                <form method="post" action="/library/<?= $id ?>/versions" enctype="multipart/form-data" class="card-body">
                    <?= \App\Core\Csrf::field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="lib-file">Файл</label>
                            <input type="file" id="lib-file" name="file" class="form-control" accept="<?= $e(\App\Services\LibraryService::acceptAttribute()) ?>" required>
                            <div class="form-text"><?= $e(\App\Services\LibraryService::allowedLabel()) ?> · до <?= $e($human($maxFile)) ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="lib-comment">Що змінилось (необов'язково)</label>
                            <input type="text" id="lib-comment" name="comment" class="form-control" maxlength="255">
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3">Завантажити версію</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($canDelete): ?>
            <form method="post" action="/library/<?= $id ?>/delete" data-confirm="Видалити документ «<?= $e($document['title']) ?>» разом з усіма версіями? Це незворотно.">
                <?= \App\Core\Csrf::field() ?>
                <button class="btn btn-outline-danger btn-sm">Видалити документ</button>
            </form>
        <?php endif; ?>
    </div>
</div>
