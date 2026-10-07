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

        <form method="post" action="<?= $isNew ? '/wiki' : '/wiki/' . $e($page['slug']) ?>" class="card card-body shadow-sm">
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
    </div>
</div>
