<?php $e = static fn($v) => \App\Core\View::e($v); require __DIR__ . '/_header.php'; ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/forum">Форум</a></li><li class="breadcrumb-item active"><?= $e($board['name']) ?></li></ol></nav>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h4 class="mb-1"><?= $e($board['name']) ?> <?php if ($board['is_locked']): ?><span class="badge bg-secondary">🔒 закрито для нових тем</span><?php endif; ?></h4>
        <?php if (!empty($board['description'])): ?><div class="text-muted"><?= $e($board['description']) ?></div><?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <?php if (!empty($subscriptionsReady)): ?>
            <form method="post" action="/forum/boards/<?= (int) $board['id'] ?>/<?= $isSubscribed ? 'unsubscribe' : 'subscribe' ?>" class="m-0">
                <?= \App\Core\Csrf::field() ?>
                <button class="btn btn-sm <?= $isSubscribed ? 'btn-secondary' : 'btn-outline-secondary' ?>" type="submit"
                        title="<?= $isSubscribed ? 'Не отримувати листи про нові теми цього розділу' : 'Отримувати лист про кожну нову тему цього розділу' ?>">
                    <?= $isSubscribed ? '🔔 Ви підписані' : '🔕 Підписатися на нові теми' ?>
                </button>
            </form>
        <?php endif; ?>
        <?php if ($canStart): ?><a href="/forum/boards/<?= (int) $board['id'] ?>/topics/new" class="btn btn-sm btn-primary">+ Нова тема</a><?php endif; ?>
        <?php if ($canModerate): ?>
            <a href="/forum/boards/<?= (int) $board['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">Налаштування розділу</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($isSubscribed) && empty($notifyEnabled)): ?>
    <div class="alert alert-warning py-2 small">Ви підписані, але надсилання листів про форум вимкнено адміністратором (або не налаштовано пошту) — листи не приходитимуть, доки це не ввімкнено.</div>
<?php endif; ?>

<?php if (empty($topics)): ?>
    <div class="alert alert-light border">У цьому розділі ще немає тем.<?= $canStart ? ' <a href="/forum/boards/' . (int) $board['id'] . '/topics/new">Створіть першу</a>.' : '' ?></div>
<?php else: ?>
    <?php require __DIR__ . '/_topics_table.php'; $pagerBase = '/forum/boards/' . (int) $board['id']; require __DIR__ . '/_pager.php'; ?>
<?php endif; ?>
