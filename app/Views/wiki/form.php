<?php
$e = static fn($v) => \App\Core\View::e($v);
$isNew = $page === null;
$cancelUrl = $isNew ? '/wiki' : '/wiki/' . $page['slug'];
?>
<div class="row">
    <div class="col-lg-3 mb-3"><?php require __DIR__ . '/_sidebar.php'; ?></div>
    <div class="col-lg-9">
        <h3 class="mb-3"><?= $isNew ? 'Нова сторінка' : 'Редагування: ' . $e($page['title']) ?></h3>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= $e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= $isNew ? '/wiki' : '/wiki/' . $e($page['slug']) ?>" class="card card-body shadow-sm" data-wiki-form<?= $isNew ? '' : ' data-wiki-slug="' . $e($page['slug']) . '"' ?>>
            <?= \App\Core\Csrf::field() ?>
            <?php if (!$isNew): ?>
                <input type="hidden" name="version" value="<?= (int) ($postedVersion ?? $page['version']) ?>">
            <?php endif; ?>

            <div class="row g-3 mb-3">
                <div class="col-md-8">
                    <label class="form-label" for="wiki-title">Назва</label>
                    <input type="text" id="wiki-title" name="title" class="form-control" maxlength="200" required value="<?= $e($form['title']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="wiki-slug">Адреса</label>
                    <?php if ($isNew): ?>
                        <input type="text" id="wiki-slug" name="slug" class="form-control" maxlength="80" placeholder="з назви, автоматично"
                               pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= $e($form['slug']) ?>">
                        <div class="form-text">Латиниця, цифри, дефіси. Лишіть порожнім — утвориться з назви.</div>
                    <?php else: ?>
                        <input type="text" id="wiki-slug" class="form-control" value="/wiki/<?= $e($page['slug']) ?>" disabled>
                        <div class="form-text">Адреса не змінюється — на неї можуть вести посилання.</div>
                    <?php endif; ?>
                </div>
                <?php if ($parentOptions !== null): ?>
                    <div class="col-12">
                        <label class="form-label" for="wiki-parent">Батьківська сторінка</label>
                        <?php
                        $currentParent = $form['parent_id'] ?? null;
                        $parentKnown = $currentParent === null || $currentParent === false;
                        foreach ($parentOptions as $o) {
                            if ((int) $o['id'] === (int) $currentParent) {
                                $parentKnown = true;
                            }
                        }
                        ?>
                        <?php if ($parentKnown): ?>
                            <select id="wiki-parent" name="parent_id" class="form-select">
                                <option value="">— верхній рівень —</option>
                                <?php foreach ($parentOptions as $o): ?>
                                    <option value="<?= (int) $o['id'] ?>" <?= (int) $currentParent === (int) $o['id'] ? 'selected' : '' ?>><?= $e(str_repeat('— ', min((int) $o['depth'], 5)) . $o['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Сторінка з'явиться в дереві під обраною. Максимум <?= (int) \App\Models\WikiPage::MAX_DEPTH ?> рівнів.</div>
                        <?php else: ?>
                            <input type="text" id="wiki-parent" class="form-control" value="Батьківська сторінка вам недоступна — розташування не змінюється" disabled>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="col-md-8">
                    <label class="form-label" for="wiki-visibility">Хто бачить</label>
                    <select id="wiki-visibility" name="visibility" class="form-select">
                        <?php foreach (\App\Models\WikiPage::VISIBILITIES as $value => $label): ?>
                            <option value="<?= $e($value) ?>" <?= $form['visibility'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="wiki-sort">Порядок у списку</label>
                    <input type="number" id="wiki-sort" name="sort_order" class="form-control" min="0" max="9999" value="<?= (int) $form['sort_order'] ?>">
                    <div class="form-text">Менше число — вище в списку.</div>
                </div>
            </div>

            <div class="mb-2 d-flex justify-content-between align-items-center">
                <label class="form-label mb-0" for="wiki-content">Текст (Markdown)</label>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-wiki-preview-button>Попередній перегляд</button>
            </div>
            <textarea id="wiki-content" name="content" class="form-control wiki-editor" rows="24" spellcheck="true"><?= $e($form['content']) ?></textarea>
            <div class="wiki-preview card card-body mt-3 wiki-content" data-wiki-preview hidden></div>

            <details class="mt-3 small">
                <summary class="text-muted">Довідка з розмітки</summary>
                <table class="table table-sm mt-2 mb-0">
                    <tbody>
                    <tr><td><code># Заголовок</code>, <code>## Підзаголовок</code></td><td>заголовки (на їх основі будується зміст)</td></tr>
                    <tr><td><code>**жирний**</code>, <code>*курсив*</code>, <code>`код`</code></td><td>виділення</td></tr>
                    <tr><td><code>- пункт</code> / <code>1. пункт</code></td><td>списки (вкладені — відступом у 2–3 пробіли)</td></tr>
                    <tr><td><code>[текст](https://…)</code></td><td>посилання (лише http, https, mailto, /відносні)</td></tr>
                    <tr><td><code>[[адреса]]</code>, <code>[[адреса|текст]]</code></td><td>посилання на іншу сторінку вікі</td></tr>
                    <tr><td><code>![опис](file:12)</code>, <code>[текст](file:12)</code></td><td>зображення чи посилання на файл, завантажений до цієї сторінки (кнопка «Вставити» нижче)</td></tr>
                    <tr><td><code>| A | B |</code> + <code>|---|---|</code></td><td>таблиця</td></tr>
                    <tr><td><code>&gt; цитата</code>, <code>---</code></td><td>цитата, горизонтальна лінія</td></tr>
                    <tr><td>три <code>`</code> до і після блоку</td><td>блок коду (HTML у тексті не виконується — показується буквально)</td></tr>
                    </tbody>
                </table>
            </details>

            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Зберегти</button>
                <a href="<?= $e($cancelUrl) ?>" class="btn btn-outline-secondary">Скасувати</a>
            </div>
        </form>

        <div class="card mt-3" data-wiki-attachments<?= $isNew ? '' : ' data-upload-url="/wiki/' . $e($page['slug']) . '/attachments"' ?> data-max-bytes="<?= (int) $attachmentMax ?>">
            <div class="card-header fw-semibold">Зображення та вкладення</div>
            <div class="card-body">
                <?php if ($isNew): ?>
                    <p class="text-muted mb-0">Зберіть сторінку, а потім відредагуйте її — тоді можна додавати зображення й файли (також перетягуванням у поле тексту чи вставкою зображення з буфера).</p>
                <?php elseif (!$attachmentsReady): ?>
                    <p class="text-muted mb-0">Вкладення вікі не підготовлено: адміністратор має виконати update.sh (міграція 026).</p>
                <?php else: ?>
                    <p class="small text-muted">
                        Дозволено: <?= $e(\App\Services\LibraryService::allowedLabel()) ?>; до <?= $e(\App\Core\UploadLimits::human((int) $attachmentMax)) ?> на файл.
                        Файл можна також перетягнути в поле тексту, а зображення — вставити з буфера (Ctrl+V). Розмітка додається в текст на місці курсора;
                        щоб зберегти її на сторінці, натисніть «Зберегти».
                    </p>
                    <div class="alert alert-danger py-2 small" data-wiki-attach-error hidden></div>
                    <ul class="list-group mb-3" data-wiki-attachment-list>
                        <?php foreach ($attachments as $a): $img = \App\Models\WikiAttachment::isImage($a['mime_type']); ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                <span>
                                    <a href="/wiki/files/<?= (int) $a['id'] ?>" target="_blank" rel="noopener noreferrer"><?= $e($a['original_name']) ?></a>
                                    <span class="text-muted small ms-1"><?= $e(\App\Core\UploadLimits::human((int) $a['size_bytes'])) ?></span>
                                </span>
                                <span class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-wiki-insert="<?= $e(($img ? '!' : '') . '[' . (trim(str_replace(['[', ']', "\n", '\\'], ' ', pathinfo($a['original_name'], PATHINFO_FILENAME))) ?: 'файл') . '](file:' . (int) $a['id'] . ')') ?>">Вставити</button>
                                    <form method="post" action="/wiki/<?= $e($page['slug']) ?>/attachments/<?= (int) $a['id'] ?>/delete" data-wiki-attachment-delete
                                          data-confirm="Видалити файл «<?= $e($a['original_name']) ?>»? Посилання на нього в тексті перестануть працювати.">
                                        <?= \App\Core\Csrf::field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Видалити</button>
                                    </form>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="post" action="/wiki/<?= $e($page['slug']) ?>/attachments" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap" data-wiki-upload-form>
                        <?= \App\Core\Csrf::field() ?>
                        <input type="file" name="file" class="form-control w-auto flex-grow-1" accept="<?= $e(\App\Services\LibraryService::acceptAttribute()) ?>" required>
                        <button type="submit" class="btn btn-outline-primary">Завантажити</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
