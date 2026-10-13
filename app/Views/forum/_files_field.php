<?php
/** Поле вибору файлів. Очікує $attachmentsReady; необов'язково $attachLimit (скільки ще можна додати). */
$limit = $attachLimit ?? \App\Models\Forum::MAX_FILES_PER_POST;
if (!empty($attachmentsReady) && $limit > 0): ?>
    <div class="mt-3">
        <label class="form-label" for="forum-files-<?= (int) ($fieldSuffix ?? 0) ?>">Файли (необов'язково)</label>
        <input type="file" id="forum-files-<?= (int) ($fieldSuffix ?? 0) ?>" name="files[]" class="form-control" multiple data-forum-files="<?= \App\Core\View::e($previewFor ?? '') ?>" data-forum-offset="<?= (int) ($attachOffset ?? 0) ?>" accept="<?= \App\Core\View::e(\App\Services\LibraryService::acceptAttribute()) ?>">
        <div class="form-text">До <?= (int) $limit ?> файлів; дозволено: <?= \App\Core\View::e(\App\Services\LibraryService::allowedLabel()) ?>; до <?= \App\Core\View::e(\App\Core\UploadLimits::human(\App\Services\LibraryService::maxBytes())) ?> кожен.</div>
        <div class="form-text">Щоб показати файл у тексті, натисніть «Вставити» біля нього (з'являється після вибору файлу) або напишіть <code>![опис](file:1)</code> — номер файлу за порядком завантаження: <code>file:1</code> — перший, <code>file:2</code> — другий. JPG і PNG показуються картинкою, решта — посиланням <code>[текст](file:1)</code>.</div>
        <div class="d-flex flex-wrap gap-2 mt-2" data-forum-insert-list></div>
    </div>
<?php endif; ?>
