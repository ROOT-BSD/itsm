<?php $e = static fn($v) => \App\Core\View::e($v); require __DIR__ . '/_header.php'; $back = '/forum/posts/' . (int) $post['id']; ?>
<h4 class="mb-3">Редагування повідомлення</h4>
<form method="post" action="/forum/posts/<?= (int) $post['id'] ?>" class="card card-body shadow-sm">
    <?= \App\Core\Csrf::field() ?>
    <?php if ($isFirst): ?>
        <div class="mb-3">
            <label class="form-label" for="forum-title">Заголовок теми</label>
            <input type="text" id="forum-title" name="title" class="form-control" maxlength="<?= \App\Models\Forum::MAX_TITLE ?>" required value="<?= $e($title) ?>">
        </div>
    <?php endif; ?>
    <div class="mb-3">
        <label class="form-label" for="forum-body">Текст</label>
        <textarea id="forum-body" name="body" class="form-control" rows="10" maxlength="<?= \App\Models\Forum::MAX_BODY ?>" required><?= $e($body) ?></textarea>
    </div>
    <div class="d-flex gap-2"><button class="btn btn-primary">Зберегти</button><a href="<?= $e($back) ?>" class="btn btn-outline-secondary">Скасувати</a></div>
</form>
