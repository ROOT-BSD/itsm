<?php
/**
 * Головна сторінка розділу адмін-панелі («Керування» чи «Налаштування системи»). Очікує: $section.
 * Вміст карток і приналежність сторінок до розділу — в App\Core\AdminNav.
 */
$e = static fn($v) => \App\Core\View::e($v);
$nav = $navClass ?? \App\Core\AdminNav::class; // AdminNav або UnitNav — однакові SECTIONS і groups()
$current = $nav::SECTIONS[$section];
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($nav::SECTIONS as $key => $tab): ?>
        <li class="nav-item">
            <a class="nav-link<?= $key === $section ? ' active' : '' ?>" href="<?= $e($tab['url']) ?>"<?= $key === $section ? ' aria-current="page"' : '' ?>>
                <?= $e($tab['title']) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<h3 class="mb-1"><?= $e($current['heading']) ?></h3>
<p class="text-muted mb-4"><?= $e($current['lead']) ?></p>
<?php if (isset($unitLabel)): ?>
    <?php if ($unitLabel === ''): ?>
        <div class="alert alert-warning">Підрозділ не визначено: у вашого облікового запису немає підрозділу (AD OU або, для локального акаунта, підрозділу, який задає адміністратор системи). Роль діє лише в межах підрозділу — зверніться до адміністратора системи.</div>
    <?php else: ?>
        <p class="small mb-4">Підрозділ: <strong><?= $e($unitLabel) ?></strong> (разом із вкладеними).</p>
    <?php endif; ?>
<?php endif; ?>

<?php foreach ($nav::groups($section) as $group): ?>
    <h6 class="text-uppercase text-muted small fw-semibold mb-2"><?= $e($group['title']) ?></h6>
    <div class="row mb-3">
        <?php foreach ($group['cards'] as $card): ?>
            <div class="col-md-4 mb-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><?= $e($card['title']) ?></h5>
                        <p class="card-text text-muted small"><?= $e($card['text']) ?></p>
                        <a href="<?= $e($card['url']) ?>" class="btn <?= $e($card['style']) ?> btn-sm"><?= $e($card['button']) ?></a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
