<?php
/** Посилання «назад» з підсторінки до її розділу («Керування» чи «Налаштування системи») — як admin/_back.php. */
$__section = \App\Core\UnitNav::SECTIONS[\App\Core\UnitNav::currentSection() ?? 'manage'];
?>
<a href="<?= \App\Core\View::e($__section['url']) ?>" class="text-decoration-none">&larr; <?= \App\Core\View::e($__section['title']) ?></a>
