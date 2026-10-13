<?php $e = static fn($v) => \App\Core\View::e($v); require __DIR__ . '/_header.php'; $back = '/forum/posts/' . (int) $post['id']; ?>
<h4 class="mb-3">Редагування повідомлення</h4>
<form method="post" action="/forum/posts/<?= (int) $post['id'] ?>" class="card card-body shadow-sm" enctype="multipart/form-data">
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
        <?php $previewFor = 'forum-body'; $previewPostId = (int) $post['id']; require __DIR__ . '/_preview.php'; ?>
    </div>
    <?php if (!empty($attachments)): ?>
        <div class="mb-3">
            <div class="form-label">Прикріплені файли</div>
            <?php foreach (array_values($attachments) as $i => $a): $isImg = in_array($a['mime_type'], ['image/jpeg', 'image/png'], true); $label = trim(str_replace(['[', ']', "\n", '\\'], ' ', pathinfo($a['original_name'], PATHINFO_FILENAME))) ?: 'файл'; ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="remove_attachments[]" value="<?= (int) $a['id'] ?>" id="rm-<?= (int) $a['id'] ?>">
                    <label class="form-check-label" for="rm-<?= (int) $a['id'] ?>">
                        <a href="/forum/files/<?= (int) $a['id'] ?>" target="_blank" rel="noopener noreferrer"><?= $e($a['original_name']) ?></a>
                        <span class="text-muted small"><?= $e(\App\Core\UploadLimits::human((int) $a['size_bytes'])) ?></span> — позначте, щоб видалити
                    </label>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 ms-2" data-forum-insert="<?= $e(($isImg ? '!' : '') . '[' . $label . '](file:' . ($i + 1) . ')') ?>" data-forum-insert-target="forum-body">Вставити (file:<?= $i + 1 ?>)</button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php $attachLimit = max(0, \App\Models\Forum::MAX_FILES_PER_POST - count($attachments ?? [])); $fieldSuffix = 2; $attachOffset = count($attachments ?? []); require __DIR__ . '/_files_field.php'; ?>
    <div class="d-flex gap-2"><button class="btn btn-primary">Зберегти</button><a href="<?= $e($back) ?>" class="btn btn-outline-secondary">Скасувати</a></div>
</form>
