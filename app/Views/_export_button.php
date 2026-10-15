<?php
/**
 * Кнопка «Експорт» (CSV / XLSX). Очікує $exportUrl — адресу експорту; поточні параметри фільтра
 * (рядок запиту сторінки) додаються автоматично, $exportExtra — додаткові (наприклад, ['status' => 'closed']).
 */
$q = $_GET;
unset($q['page'], $q['format']);
$q = array_merge($q, $exportExtra ?? []);
$mk = fn(string $f): string => \App\Core\View::e($exportUrl . '?' . http_build_query($q + ['format' => $f]));
?>
<div class="btn-group">
    <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">Експорт</button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= $mk('xlsx') ?>">Excel (.xlsx)</a></li>
        <li><a class="dropdown-item" href="<?= $mk('csv') ?>">CSV (.csv)</a></li>
    </ul>
</div>
