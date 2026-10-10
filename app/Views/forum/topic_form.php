<?php $e = static fn($v) => \App\Core\View::e($v); require __DIR__ . '/_header.php'; ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/forum">Форум</a></li><li class="breadcrumb-item"><a href="/forum/boards/<?= (int) $board['id'] ?>"><?= $e($board['name']) ?></a></li><li class="breadcrumb-item active">Нова тема</li></ol></nav>
<form method="post" action="/forum/boards/<?= (int) $board['id'] ?>/topics" class="card card-body shadow-sm" enctype="multipart/form-data">
    <?= \App\Core\Csrf::field() ?>
    <div class="mb-3">
        <label class="form-label" for="forum-title">Заголовок</label>
        <input type="text" id="forum-title" name="title" class="form-control" maxlength="<?= \App\Models\Forum::MAX_TITLE ?>" required value="<?= $e($form['title']) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label" for="forum-body">Повідомлення</label>
        <textarea id="forum-body" name="body" class="form-control" rows="10" maxlength="<?= \App\Models\Forum::MAX_BODY ?>" required><?= $e($form['body']) ?></textarea>
        <div class="form-text">Markdown: **жирний**, *курсив*, `код`, списки, &gt; цитата, [текст](https://посилання).</div>
        <?php $previewFor = 'forum-body'; require __DIR__ . '/_preview.php'; ?>
        <?php $fieldSuffix = 1; require __DIR__ . '/_files_field.php'; ?>
    </div>
    <div class="d-flex gap-2"><button class="btn btn-primary">Створити тему</button><a href="/forum/boards/<?= (int) $board['id'] ?>" class="btn btn-outline-secondary">Скасувати</a></div>
</form>
