<?php $e = static fn($v) => \App\Core\View::e((string) $v); ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h3 class="mb-0">Сповіщення <?php if ($unread > 0): ?><span class="badge text-bg-danger"><?= (int) $unread ?></span><?php endif; ?></h3>
    <?php if (!empty($items)): ?>
    <div class="d-flex gap-2">
        <form method="post" action="/notifications/read-all"><?= \App\Core\Csrf::field() ?><button class="btn btn-outline-secondary btn-sm" type="submit">Позначити всі прочитаними</button></form>
        <form method="post" action="/notifications/clear" data-confirm="Видалити всі прочитані сповіщення?"><?= \App\Core\Csrf::field() ?><button class="btn btn-outline-danger btn-sm" type="submit">Прибрати прочитані</button></form>
    </div>
    <?php endif; ?>
</div>
<?php if (empty($ready)): ?>
    <div class="alert alert-warning">Центр сповіщень ще не налаштовано — виконайте <code>sudo bash update.sh</code> (міграція 054).</div>
<?php elseif (empty($items)): ?>
    <div class="alert alert-light border">Сповіщень немає. Тут з'являтимуться повідомлення від автоматичних правил черги тікетів та інших подій.</div>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($items as $n): ?>
            <a href="/notifications/<?= (int) $n['id'] ?>/open" class="list-group-item list-group-item-action<?= $n['read_at'] === null ? ' list-group-item-primary' : '' ?>">
                <div class="d-flex justify-content-between gap-2">
                    <strong><?= $n['read_at'] === null ? '● ' : '' ?><?= $e($n['title']) ?></strong>
                    <small class="text-muted text-nowrap"><?= $e(date('d.m.Y H:i', strtotime((string) $n['created_at']))) ?></small>
                </div>
                <?php if (!empty($n['body'])): ?><div class="small"><?= $e($n['body']) ?></div><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
