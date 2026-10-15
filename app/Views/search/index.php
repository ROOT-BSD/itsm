<?php
$e = static fn($v) => \App\Core\View::e($v);
$found = array_sum(array_column($sections, 'total'));
?>
<h3 class="mb-3">Пошук</h3>

<form method="get" action="/search" class="mb-4">
    <div class="input-group">
        <input type="search" name="q" value="<?= $e($query) ?>" class="form-control form-control-lg" maxlength="<?= (int) \App\Services\SearchService::MAX_LENGTH ?>"
               placeholder="Проєкти, задачі, тікети, вікі, документи, форум… або номер, наприклад #42" autofocus>
        <button type="submit" class="btn btn-primary">Шукати</button>
    </div>
    <div class="form-text">Шукає в назвах, описах і коментарях; показує лише те, до чого у вас є доступ.</div>
</form>

<?php if ($query === ''): ?>
    <p class="text-muted">Введіть слово чи фразу. Для переходу за номером введіть <code>#42</code> — знайде проєкт, задачу й тікет із таким номером.</p>
<?php elseif ($tooShort): ?>
    <div class="alert alert-warning">Введіть щонайменше <?= (int) \App\Services\SearchService::MIN_LENGTH ?> символи.</div>
<?php elseif ($found === 0): ?>
    <div class="alert alert-secondary">За запитом «<?= $e($query) ?>» нічого не знайдено. Спробуйте коротше слово або іншу форму слова.</div>
<?php else: ?>
    <p class="text-muted">Знайдено за запитом «<?= $e($query) ?>»: <?= (int) $found ?>.</p>
    <?php foreach ($sections as $s): if ($s['total'] === 0) { continue; } ?>
        <h5 class="mt-4 d-flex align-items-center gap-2">
            <?= $e($s['title']) ?>
            <span class="badge bg-secondary"><?= (int) $s['total'] ?><?= $s['more'] && $s['key'] === 'wiki' ? '+' : '' ?></span>
        </h5>
        <div class="list-group mb-2">
            <?php foreach ($s['items'] as $it): ?>
                <a class="list-group-item list-group-item-action" href="<?= $e($it['url']) ?>">
                    <div class="fw-semibold"><?= $it['title'] /* HTML уже екрановано у SearchService::mark() */ ?></div>
                    <div class="small text-muted"><?= $e($it['meta']) ?></div>
                    <?php if ($it['snippet'] !== ''): ?><div class="small"><?= $it['snippet'] /* HTML уже екрановано */ ?></div><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($s['more'] && $s['more_url']): ?>
            <a class="small" href="<?= $e($s['more_url']) ?>">Показати всі в розділі «<?= $e($s['title']) ?>» →</a>
        <?php elseif ($s['more']): ?>
            <div class="small text-muted">Показано перші <?= count($s['items']) ?>; уточніть запит, щоб побачити решту.</div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>
