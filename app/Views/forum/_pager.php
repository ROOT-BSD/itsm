<?php
/** Нумерація сторінок. Очікує: $page, $totalPages, $pagerBase (адреса без ?page, може вже мати ?параметри). */
if ($totalPages > 1):
    $sep = str_contains($pagerBase, '?') ? '&' : '?';
?>
<nav aria-label="Сторінки"><ul class="pagination pagination-sm">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <li class="page-item<?= $p === $page ? ' active' : '' ?>"><a class="page-link" href="<?= \App\Core\View::e($pagerBase . ($p > 1 ? $sep . 'page=' . $p : '')) ?>"><?= $p ?></a></li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>
