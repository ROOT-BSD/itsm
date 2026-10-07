<?php $e = static fn($v) => \App\Core\View::e($v); ?>
<div class="row">
    <div class="col-lg-3 mb-3"><?php require __DIR__ . '/_sidebar.php'; ?></div>
    <div class="col-lg-9">
        <div class="mb-2"><a href="/wiki/<?= $e($page['slug']) ?>" class="text-decoration-none">&larr; <?= $e($page['title']) ?></a></div>
        <h3 class="mb-3">Історія змін</h3>

        <?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
        <?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>

        <table class="table table-bordered bg-white align-middle">
            <thead><tr><th>Версія</th><th>Коли</th><th>Хто</th><th>Назва</th><th class="text-end">Розмір</th><th>Дії</th></tr></thead>
            <tbody>
            <?php foreach ($revisions as $r): ?>
                <?php $isCurrent = (int)$r['version'] === (int)$page['version']; ?>
                <tr>
                    <td><?= (int)$r['version'] ?><?= $isCurrent ? ' <span class="badge bg-success">поточна</span>' : '' ?></td>
                    <td class="text-nowrap"><?= $e(date('d.m.Y H:i', strtotime($r['created_at']))) ?></td>
                    <td><?= $e($r['edited_by_name'] ?? 'система (імпорт документації)') ?></td>
                    <td><?= $e($r['title']) ?></td>
                    <td class="text-end text-nowrap"><?= number_format((int)$r['size_chars'], 0, '', ' ') ?> симв.</td>
                    <td class="text-nowrap">
                        <a href="/wiki/<?= $e($page['slug']) ?>/revisions/<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary">Переглянути</a>
                        <?php if ($canEdit && !$isCurrent): ?>
                            <form method="post" action="/wiki/<?= $e($page['slug']) ?>/revisions/<?= (int)$r['id'] ?>/restore" class="d-inline"
                                  data-confirm="Відновити вміст версії <?= (int)$r['version'] ?>? Він збережеться як НОВА версія; поточний текст лишиться в історії.">
                                <?= \App\Core\Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-primary">Відновити</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
