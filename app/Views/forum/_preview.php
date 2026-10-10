<?php
/** Кнопка «Попередній перегляд» і місце для нього. Очікує $previewFor — id поля тексту. */
?>
<div class="mt-2">
    <button type="button" class="btn btn-sm btn-outline-secondary" data-forum-preview-button="<?= \App\Core\View::e($previewFor) ?>">Попередній перегляд</button>
</div>
<div class="card card-body mt-2 wiki-content" data-forum-preview-pane="<?= \App\Core\View::e($previewFor) ?>" hidden></div>
