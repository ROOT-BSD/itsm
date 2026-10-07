<?php
/**
 * Блок «Зображення» для форм СТВОРЕННЯ тікета (залогінені й анонімний портал).
 * Очікує: $pickerMaxFiles (макс. файлів), $pickerMaxBytes (макс. розмір одного).
 * Форма-власник має бути з enctype="multipart/form-data"; поведінку (вставка зі скриншота, список
 * вибраного, видалення зі списку) дає public/assets/js/paste-images.js — без нього це звичайне поле файлу.
 */
$limits = [
    'files' => (int) $pickerMaxFiles,
    'bytes' => min((int) $pickerMaxBytes, \App\Core\UploadLimits::effectiveFileMax()),
];
?>
<div class="mb-3" data-paste-images data-max-files="<?= $limits['files'] ?>" data-max-bytes="<?= $limits['bytes'] ?>">
    <label class="form-label">Зображення (необов'язково)</label>
    <input type="file" name="files[]" class="form-control" multiple data-paste-input
           accept=".png,.jpg,.jpeg,image/png,image/jpeg">
    <div class="form-text">
        PNG або JPG, до <?= $limits['files'] ?> файлів, кожен до <?= \App\Core\View::e(\App\Core\UploadLimits::human($limits['bytes'])) ?>.
        Скриншот можна просто вставити з буфера обміну — клацніть у форму й натисніть <kbd>Ctrl</kbd>+<kbd>V</kbd>.
    </div>
    <ul class="list-group mt-2" data-paste-list hidden></ul>
    <div class="text-danger small mt-1" data-paste-error hidden></div>
</div>
