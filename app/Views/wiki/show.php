<?php $e = static fn($v) => \App\Core\View::e($v); ?>
<div class="row">
    <div class="col-lg-3 mb-3"><?php require __DIR__ . '/_sidebar.php'; ?></div>
    <div class="col-lg-9">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= $e($error) ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= $e($success) ?></div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
            <h2 class="mb-0"><?= $e($page['title']) ?></h2>
            <div class="d-flex gap-2">
                <a href="/wiki/<?= $e($page['slug']) ?>/history" class="btn btn-sm btn-outline-secondary">Історія</a>
                <?php if ($canEdit): ?>
                    <a href="/wiki/<?= $e($page['slug']) ?>/edit" class="btn btn-sm btn-primary">Редагувати</a>
                <?php endif; ?>
                <?php if (\App\Models\WikiPage::canDelete($role, $page)): ?>
                    <form method="post" action="/wiki/<?= $e($page['slug']) ?>/delete"
                          data-confirm="Видалити сторінку «<?= $e($page['title']) ?>» разом з усією її історією? Це незворотно.">
                        <?= \App\Core\Csrf::field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">Видалити</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="text-muted small mb-3">
            Версія <?= (int)$page['version'] ?>
            · змінено <?= $e(date('d.m.Y H:i', strtotime($page['updated_at']))) ?><?= !empty($page['updated_by_name']) ? ' · ' . $e($page['updated_by_name']) : '' ?>
            <?php if ($page['visibility'] !== 'all'): ?>
                · <span class="badge bg-secondary"><?= $e(\App\Models\WikiPage::VISIBILITIES[$page['visibility']]) ?></span>
            <?php endif; ?>
        </div>

        <?php if (count($toc) >= 3): ?>
            <details class="wiki-toc card card-body mb-3"<?= count($toc) <= 12 ? ' open' : '' ?>>
                <summary class="fw-semibold">Зміст (<?= count($toc) ?>)</summary>
                <ul class="list-unstyled mb-0 mt-2">
                    <?php foreach ($toc as $h): ?>
                        <li class="<?= $h['level'] === 3 ? 'ms-3 small' : '' ?>"><a href="#<?= $e($h['id']) ?>"><?= $e($h['text']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>

        <div class="card">
            <div class="card-body wiki-content">
                <?= $html /* HTML від App\Core\Markdown: увесь текст екранований, посилання проходять білий список */ ?>
            </div>
        </div>
    </div>
</div>
