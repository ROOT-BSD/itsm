<?php
$e = static fn($v) => \App\Core\View::e($v);
$isNew = $document === null;
$maxFile = \App\Services\LibraryService::maxBytes();
$cancelUrl = $isNew ? '/library' : '/library/' . (int) $document['id'];
?>
<h3 class="mb-3">Документи</h3>

<div class="row">
    <div class="col-lg-3 mb-3"><?php require __DIR__ . '/_sidebar.php'; ?></div>
    <div class="col-lg-9">
        <h4 class="mb-3"><?= $isNew ? 'Новий документ' : 'Редагування: ' . $e($document['title']) ?></h4>

        <?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

        <form method="post" action="<?= $isNew ? '/library' : '/library/' . (int) $document['id'] ?>"<?= $isNew ? ' enctype="multipart/form-data"' : '' ?> class="card card-body shadow-sm">
            <?= \App\Core\Csrf::field() ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="lib-title">Назва</label>
                    <input type="text" id="lib-title" name="title" class="form-control" maxlength="200" required value="<?= $e($form['title']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="lib-desc">Опис (необов'язково)</label>
                    <textarea id="lib-desc" name="description" class="form-control" rows="4" maxlength="5000"><?= $e($form['description']) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="lib-category">Розділ</label>
                    <select id="lib-category" name="category_id" class="form-select">
                        <option value="">— без розділу —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= (string) $form['category_id'] === (string) $c['id'] ? 'selected' : '' ?>><?= $e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="lib-visibility">Хто бачить</label>
                    <select id="lib-visibility" name="visibility" class="form-select">
                        <?php foreach ($visibilities as $value => $label): ?>
                            <option value="<?= $e($value) ?>" <?= $form['visibility'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($isNew): ?>
                    <div class="col-md-6">
                        <label class="form-label" for="lib-file">Файл</label>
                        <input type="file" id="lib-file" name="file" class="form-control" accept="<?= $e(\App\Services\LibraryService::acceptAttribute()) ?>" required>
                        <div class="form-text"><?= $e(\App\Services\LibraryService::allowedLabel()) ?> · до <?= $e(\App\Core\UploadLimits::human($maxFile)) ?>. Файли з макросами не приймаються.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="lib-comment">Примітка до версії (необов'язково)</label>
                        <input type="text" id="lib-comment" name="comment" class="form-control" maxlength="255" value="<?= $e($form['comment']) ?>">
                    </div>
                <?php endif; ?>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-primary"><?= $isNew ? 'Додати документ' : 'Зберегти' ?></button>
                <a href="<?= $e($cancelUrl) ?>" class="btn btn-outline-secondary">Скасувати</a>
            </div>
        </form>
    </div>
</div>
