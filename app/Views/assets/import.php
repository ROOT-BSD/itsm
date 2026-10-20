<?php $e = static fn($v) => \App\Core\View::e((string) $v); ?>
<div class="mb-3"><a href="/it-assets" class="text-decoration-none">&larr; Реєстр</a></div>
<h3 class="mb-3">Імпорт активів</h3>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($result)): ?>
    <div class="alert alert-<?= $result['errors'] ? 'warning' : 'success' ?>">
        Файл «<?= $e($result['file']) ?>»: створено — <strong><?= (int) $result['created'] ?></strong>, оновлено — <strong><?= (int) $result['updated'] ?></strong>, з помилками — <strong><?= count($result['errors']) ?></strong>.
        <?php if ($result['errors']): ?>
            <ul class="mb-0 mt-2"><?php foreach (array_slice($result['errors'], 0, 50) as [$line, $msg]): ?><li>рядок <?= (int) $line ?>: <?= $e($msg) ?></li><?php endforeach; ?></ul>
            <?php if (count($result['errors']) > 50): ?><div class="small mt-1">… і ще <?= count($result['errors']) - 50 ?>.</div><?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
<div class="card p-4 shadow-sm form-card-md">
    <p>Завантажте файл <strong>.xlsx</strong> або <strong>.csv</strong> (UTF-8 чи Windows-1251, роздільник «;» або «,»). Перший рядок — заголовки. Обов'язкова колонка — <strong>Назва</strong>.
        Найзручніше почати з <a href="/it-assets/template?format=xlsx">шаблону XLSX</a> / <a href="/it-assets/template?format=csv">CSV</a> або з експорту наявного реєстру.</p>
    <p class="small text-muted mb-2">Колонки: <?= $e(implode(', ', \App\Services\AssetImport::HEADERS)) ?>.
        Тип — із довідника (<?= $e(implode(', ', \App\Models\Asset::TYPES)) ?>), статус — <?= $e(implode(', ', \App\Models\Asset::STATUSES)) ?>. Дати — РРРР-ММ-ДД або ДД.ММ.РРРР.
        Власник — email користувача. <strong>Рядок із уже наявним інвентарним номером оновлює ту картку</strong> (колонки, яких немає у файлі, не змінюються); інакше створюється нова. До <?= (int) \App\Services\AssetImport::MAX_ROWS ?> рядків.</p>
    <form method="post" action="/it-assets/import" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap">
        <?= \App\Core\Csrf::field() ?>
        <input type="hidden" name="kind" value="hardware">
        <input type="file" name="file" accept=".xlsx,.csv,.txt" class="form-control w-auto" required>
        <button class="btn btn-primary" type="submit">Імпортувати</button>
    </form>
</div>

<div class="card p-4 shadow-sm form-card-md mt-3">
    <h5>Імпорт ПЗ</h5>
    <p class="small">Окремий файл для програмного забезпечення: усі рядки створюються з типом «ПЗ». Обов'язкова колонка — <strong>Назва</strong>.
        Шаблон: <a href="/it-assets/template-software?format=xlsx">XLSX</a> / <a href="/it-assets/template-software?format=csv">CSV</a>.</p>
    <p class="small text-muted mb-2">Колонки: <?= $e(implode(', ', \App\Services\AssetImport::HEADERS_SW)) ?>.
        Версія записується в поле «Модель», «Ліцензія до» — у «Гарантія до» (за ним працює фільтр строків). Якщо вказано інв. № пристрою, ПЗ автоматично пов'язується зв'язком «встановлено на». Рядок із наявним інв. № ПЗ оновлюється.</p>
    <form method="post" action="/it-assets/import" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap">
        <?= \App\Core\Csrf::field() ?>
        <input type="hidden" name="kind" value="software">
        <input type="file" name="file" accept=".xlsx,.csv,.txt" class="form-control w-auto" required>
        <button class="btn btn-primary" type="submit">Імпортувати ПЗ</button>
    </form>
</div>
