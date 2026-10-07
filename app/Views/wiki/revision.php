<?php $e = static fn($v) => \App\Core\View::e($v); ?>
<div class="row">
    <div class="col-lg-3 mb-3"><?php require __DIR__ . '/_sidebar.php'; ?></div>
    <div class="col-lg-9">
        <div class="mb-2"><a href="/wiki/<?= $e($page['slug']) ?>/history" class="text-decoration-none">&larr; Історія змін</a></div>
        <div class="alert alert-warning">
            Це <strong>стара версія <?= (int)$revision['version'] ?></strong> —
            <?= $e(date('d.m.Y H:i', strtotime($revision['created_at']))) ?>,
            <?= $e($revision['edited_by_name'] ?? 'система (імпорт документації)') ?>.
            <?php if ((int)$revision['version'] === (int)$page['version']): ?>
                Вона збігається з поточною.
            <?php else: ?>
                <a href="/wiki/<?= $e($page['slug']) ?>">Відкрити поточну</a>.
            <?php endif; ?>
        </div>
        <h2 class="mb-3"><?= $e($revision['title']) ?></h2>
        <div class="card">
            <div class="card-body wiki-content"><?= $html /* HTML від App\Core\Markdown, уже безпечний */ ?></div>
        </div>
    </div>
</div>
