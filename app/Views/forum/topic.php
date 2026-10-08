<?php
$e = static fn($v) => \App\Core\View::e($v);
$roleLabel = static fn(?string $r) => ['admin' => 'адміністратор', 'it_manager' => 'IT-менеджер'][$r] ?? null;
require __DIR__ . '/_header.php';
$tid = (int) $topic['id'];
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/forum">Форум</a></li>
    <li class="breadcrumb-item"><a href="/forum/boards/<?= (int) $topic['board_id'] ?>"><?= $e($topic['board_name']) ?></a></li>
    <li class="breadcrumb-item active"><?= $e($topic['title']) ?></li>
</ol></nav>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <h4 class="mb-0">
        <?php if ($topic['is_pinned']): ?><span title="Закріплена">📌</span><?php endif; ?>
        <?php if ($topic['is_locked']): ?><span title="Закрита для відповідей">🔒</span><?php endif; ?>
        <?= $e($topic['title']) ?>
    </h4>
    <?php if ($canModerate): ?>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <form method="post" action="/forum/topics/<?= $tid ?>/pin"><?= \App\Core\Csrf::field() ?><button class="btn btn-sm btn-outline-secondary"><?= $topic['is_pinned'] ? 'Відкріпити' : 'Закріпити' ?></button></form>
            <form method="post" action="/forum/topics/<?= $tid ?>/lock"><?= \App\Core\Csrf::field() ?><button class="btn btn-sm btn-outline-secondary"><?= $topic['is_locked'] ? 'Відкрити' : 'Закрити' ?></button></form>
            <?php if (!empty($moveTargets)): ?>
                <form method="post" action="/forum/topics/<?= $tid ?>/move" class="d-flex gap-1">
                    <?= \App\Core\Csrf::field() ?>
                    <select name="board_id" class="form-select form-select-sm" aria-label="Перенести до розділу">
                        <?php foreach ($moveTargets as $b): ?><option value="<?= (int) $b['id'] ?>"><?= $e($b['name']) ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-outline-secondary">Перенести</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php $pagerBase = '/forum/topics/' . $tid; require __DIR__ . '/_pager.php'; ?>

<?php foreach ($posts as $p): ?>
    <div class="card mb-3" id="post-<?= (int) $p['id'] ?>">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>
                <strong><?= $e($p['author_name'] ?? 'користувача видалено') ?></strong>
                <?php if ($roleLabel($p['author_role'] ?? null)): ?><span class="badge bg-info text-dark"><?= $e($roleLabel($p['author_role'])) ?></span><?php endif; ?>
            </span>
            <a href="/forum/posts/<?= (int) $p['id'] ?>" class="small text-muted text-decoration-none" title="Постійне посилання"><?= $e(date('d.m.Y H:i', strtotime($p['created_at']))) ?></a>
        </div>
        <div class="card-body wiki-content"><?= $p['html'] /* HTML від App\Core\Markdown: увесь текст екранований, посилання проходять білий список */ ?></div>
        <?php if ($p['edited_at'] !== null || $p['can_edit'] || $p['can_delete']): ?>
            <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2 small text-muted">
                <span><?php if ($p['edited_at'] !== null): ?>змінено <?= $e(date('d.m.Y H:i', strtotime($p['edited_at']))) ?><?= $p['edited_by_name'] !== null ? ', ' . $e($p['edited_by_name']) : '' ?><?php endif; ?></span>
                <span class="d-flex gap-2">
                    <?php if ($p['can_edit']): ?><a href="/forum/posts/<?= (int) $p['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">Редагувати</a><?php endif; ?>
                    <?php if ($p['can_delete']): ?>
                        <form method="post" action="/forum/posts/<?= (int) $p['id'] ?>/delete" data-confirm="<?= $p['is_first'] ? 'Видалити всю тему «' . $e($topic['title']) . '» разом з відповідями? Це незворотно.' : 'Видалити це повідомлення? Це незворотно.' ?>">
                            <?= \App\Core\Csrf::field() ?><button class="btn btn-sm btn-outline-danger"><?= $p['is_first'] ? 'Видалити тему' : 'Видалити' ?></button>
                        </form>
                    <?php endif; ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php $pagerBase = '/forum/topics/' . $tid; require __DIR__ . '/_pager.php'; ?>

<?php if ($canReply): ?>
    <form method="post" action="/forum/topics/<?= $tid ?>/reply" class="card card-body shadow-sm" id="reply">
        <?= \App\Core\Csrf::field() ?>
        <label class="form-label" for="forum-reply">Ваша відповідь</label>
        <textarea id="forum-reply" name="body" class="form-control" rows="6" maxlength="<?= \App\Models\Forum::MAX_BODY ?>" required><?= $e($replyBody) ?></textarea>
        <div class="form-text">Markdown: **жирний**, *курсив*, `код`, списки, &gt; цитата, [текст](https://посилання).</div>
        <div class="mt-3"><button class="btn btn-primary">Відповісти</button></div>
    </form>
<?php else: ?>
    <div class="alert alert-secondary">🔒 Тему закрито — нові відповіді неможливі.</div>
<?php endif; ?>
