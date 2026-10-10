<?php
/** Поле вибору файлів. Очікує $attachmentsReady; необов'язково $attachLimit (скільки ще можна додати). */
$limit = $attachLimit ?? \App\Models\Forum::MAX_FILES_PER_POST;
if (!empty($attachmentsReady) && $limit > 0): ?>
    <div class="mt-3">
        <label class="form-label" for="forum-files-<?= (int) ($fieldSuffix ?? 0) ?>">Файли (необов'язково)</label>
        <input type="file" id="forum-files-<?= (int) ($fieldSuffix ?? 0) ?>" name="files[]" class="form-control" multiple accept="<?= \App\Core\View::e(\App\Services\LibraryService::acceptAttribute()) ?>">
        <div class="form-text">До <?= (int) $limit ?> файлів; дозволено: <?= \App\Core\View::e(\App\Services\LibraryService::allowedLabel()) ?>; до <?= \App\Core\View::e(\App\Core\UploadLimits::human(\App\Services\LibraryService::maxBytes())) ?> кожен.</div>
    </div>
<?php endif; ?>
