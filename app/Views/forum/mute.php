<?php $e = static fn($v) => \App\Core\View::e($v); require __DIR__ . '/_header.php'; ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/forum">Форум</a></li><li class="breadcrumb-item"><a href="/forum/topics/<?= (int) $topic['id'] ?>"><?= $e($topic['title']) ?></a></li><li class="breadcrumb-item active">Сповіщення</li></ol></nav>
<div class="card card-body shadow-sm">
    <h5>Тема «<?= $e($topic['title']) ?>»</h5>
    <?php if (!$mutesReady): ?>
        <p class="text-muted mb-0">Відписку від тем ще не налаштовано: адміністратор має виконати update.sh (міграція 035).</p>
    <?php elseif ($isMuted): ?>
        <p>Листи про відповіді в цій темі вам <strong>не надсилаються</strong>.</p>
        <form method="post" action="/forum/topics/<?= (int) $topic['id'] ?>/unmute"><?= \App\Core\Csrf::field() ?><button class="btn btn-primary">Знову сповіщати</button></form>
    <?php else: ?>
        <p>Ви отримуєте листи про нові відповіді в цій темі, бо створили її або писали в ній. Вимкнути їх лише для цієї теми?</p>
        <form method="post" action="/forum/topics/<?= (int) $topic['id'] ?>/mute"><?= \App\Core\Csrf::field() ?><button class="btn btn-primary">Не сповіщати про цю тему</button></form>
    <?php endif; ?>
</div>
