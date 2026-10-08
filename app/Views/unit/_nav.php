<?php
$__path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/unit', PHP_URL_PATH) ?: '/unit', '/');
$__tabs = ['/unit' => 'Користувачі', '/projects' => 'Проєкти', '/tasks' => 'Задачі', '/tickets' => 'Тікети', '/unit/board' => 'Канбан', '/unit/gantt' => 'Гант', '/unit/time' => 'Облік часу', '/unit/queues' => 'Черги тікетів', '/unit/csat' => 'CSAT', '/unit/audit' => 'Журнал аудиту'];
?>
<ul class="nav nav-tabs">
    <?php foreach ($__tabs as $__url => $__title): ?>
        <li class="nav-item"><a class="nav-link <?= $__path === $__url ? 'active' : '' ?>" href="<?= $__url ?>"><?= \App\Core\View::e($__title) ?></a></li>
    <?php endforeach; ?>
</ul>
