<?php
/** Спільна шапка форуму: назва, пошук, повідомлення. Очікує: $error, $success, $canModerate; необов'язково $searchQuery. */
$e = static fn($v) => \App\Core\View::e($v);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h3 class="mb-0"><a href="/forum" class="text-reset text-decoration-none">Форум</a></h3>
    <form method="get" action="/forum/search" class="d-flex gap-2">
        <input type="search" name="q" class="form-control form-control-sm" maxlength="100" placeholder="Пошук на форумі…" value="<?= $e($searchQuery ?? '') ?>" aria-label="Пошук на форумі">
        <button class="btn btn-sm btn-outline-secondary">Знайти</button>
    </form>
</div>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>
