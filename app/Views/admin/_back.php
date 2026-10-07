<?php
/** Посилання «назад» з підсторінки адмін-панелі до її розділу («Керування» чи «Налаштування системи») — розділ визначається за адресою. */
$__section = \App\Core\AdminNav::SECTIONS[\App\Core\AdminNav::currentSection() ?? 'manage'];
?>
<a href="<?= \App\Core\View::e($__section['url']) ?>" class="text-decoration-none">&larr; <?= \App\Core\View::e($__section['title']) ?></a>
