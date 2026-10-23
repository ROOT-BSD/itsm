<?php
/**
 * Форма запису журналу робіт (створення й редагування). Очікує: $task, $journalImages, $formEntry (запис або null),
 * $formAction, $formId, $formSubmit. Скрипт public/assets/js/journal-editor.js додає вставку зображень у текст і перегляд.
 */
$fe = $formEntry ?? [];
$fid = $formId;
?>
<form method="post" action="<?= \App\Core\View::e($formAction) ?>" enctype="multipart/form-data" novalidate data-journal-form data-preview-url="/tasks/<?= (int) $task['id'] ?>/journal/preview">
    <?= \App\Core\Csrf::field() ?>
    <div class="btn-toolbar gap-1 mb-1" role="toolbar" aria-label="Форматування тексту" data-journal-toolbar>
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary" data-journal-md="bold" title="Жирний (Ctrl+B)"><strong>Ж</strong></button>
            <button type="button" class="btn btn-outline-secondary" data-journal-md="italic" title="Курсив (Ctrl+I)"><em>К</em></button>
            <button type="button" class="btn btn-outline-secondary" data-journal-md="strike" title="Закреслений"><del>З</del></button>
            <button type="button" class="btn btn-outline-secondary" data-journal-md="code" title="Код у рядку"><code>&lt;/&gt;</code></button>
        </div>
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary" data-journal-md="heading" title="Заголовок">H</button>
            <button type="button" class="btn btn-outline-secondary" data-journal-md="ul" title="Маркований список">• Список</button>
            <button type="button" class="btn btn-outline-secondary" data-journal-md="ol" title="Нумерований список">1. Список</button>
            <button type="button" class="btn btn-outline-secondary" data-journal-md="quote" title="Цитата">❝</button>
            <button type="button" class="btn btn-outline-secondary" data-journal-md="codeblock" title="Блок коду">Блок коду</button>
            <button type="button" class="btn btn-outline-secondary" data-journal-md="link" title="Посилання (Ctrl+K)">🔗</button>
        </div>
    </div>
    <textarea name="body" id="<?= $fid ?>-body" class="form-control mb-2" rows="<?= $fe ? 4 : 3 ?>" maxlength="20000" placeholder="Що зроблено… Текст у форматі Markdown (панель вище). Зображення: кнопка нижче, перетягування чи Ctrl+V"><?= \App\Core\View::e($fe['comment'] ?? '') ?></textarea>
    <div class="row g-2 align-items-end mb-2">
        <div class="col-auto">
            <label class="form-label small mb-0" for="<?= $fid ?>-hours">Витрачено часу <strong data-journal-minutes-label></strong></label>
            <?php
            // Час у хвилинах: спершу 10, стрілки ▲▼ змінюють на крок (10 хв); 0 — запис без часу. Давній запис із нестандартним часом показується як є.
            $curMin = !empty($fe) ? (int) round((float) $fe['hours'] * 60) : 10;
            ?>
            <div class="input-group input-group-sm" style="width:9.5rem">
                <input type="number" name="minutes" id="<?= $fid ?>-hours" class="form-control" min="0" max="720" step="10" value="<?= $curMin ?>" inputmode="numeric" data-journal-minutes>
                <span class="input-group-text">хв</span>
            </div>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-0" for="<?= $fid ?>-date">Дата</label>
            <input type="date" name="log_date" id="<?= $fid ?>-date" class="form-control form-control-sm" value="<?= \App\Core\View::e($fe['log_date'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="col-auto">
            <label class="form-label small mb-0" for="<?= $fid ?>-cat">Категорія</label>
            <input type="text" name="category" id="<?= $fid ?>-cat" class="form-control form-control-sm" maxlength="100" placeholder="напр. діагностика" value="<?= \App\Core\View::e($fe['activity_category'] ?? '') ?>">
        </div>
        <?php if ($journalImages): ?>
        <div class="col-auto">
            <label class="form-label small mb-0" for="<?= $fid ?>-ex">Вставити прикріплене</label>
            <select id="<?= $fid ?>-ex" class="form-select form-select-sm" style="width:12rem" data-journal-existing>
                <option value="">— зображення —</option>
                <?php foreach ($journalImages as $img): ?><option value="<?= (int) $img['id'] ?>"><?= \App\Core\View::e($img['original_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <button class="btn btn-sm btn-primary" type="submit"><?= \App\Core\View::e($formSubmit) ?></button>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-journal-pick>🖼 Вставити зображення</button>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-journal-preview>Перегляд</button>
        <input type="file" name="files[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden data-journal-files>
        <span class="small text-muted" data-journal-picked></span>
    </div>
    <div class="border rounded p-2 mt-2 bg-light" data-journal-pane hidden></div>
</form>
