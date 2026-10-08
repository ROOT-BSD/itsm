<?php $e = static fn($v) => \App\Core\View::e($v); $searchQuery = $query; require __DIR__ . '/_header.php'; ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/forum">Форум</a></li><li class="breadcrumb-item active">Пошук</li></ol></nav>
<?php if ($query === ''): ?>
    <div class="alert alert-light border">Введіть слово чи частину фрази для пошуку в назвах тем і текстах повідомлень.</div>
<?php elseif (empty($topics)): ?>
    <div class="alert alert-light border">За запитом «<?= $e($query) ?>» нічого не знайдено.</div>
<?php else: ?>
    <h5 class="mb-3">Знайдено тем за запитом «<?= $e($query) ?>»: <?= (int) $total ?></h5>
    <?php $showBoard = true; require __DIR__ . '/_topics_table.php'; $pagerBase = '/forum/search?q=' . rawurlencode($query); require __DIR__ . '/_pager.php'; ?>
<?php endif; ?>
