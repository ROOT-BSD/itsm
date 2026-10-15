<?php
$e = static fn($v) => \App\Core\View::e($v);
$badge = ['ok' => ['success', 'OK'], 'warn' => ['warning text-dark', 'Увага'], 'fail' => ['danger', 'Проблема'], 'info' => ['secondary', 'Інфо']];
$banner = [
    'ok' => ['success', 'Усе гаразд: критичних зауважень немає.'],
    'warn' => ['warning', 'Є зауваження, які варто переглянути (позначені «Увага»).'],
    'fail' => ['danger', 'Є проблеми, що можуть порушувати роботу системи (позначені «Проблема»).'],
][$overall];
?>
<div class="mb-3"><?php require __DIR__ . '/_back.php'; ?></div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h3 class="mb-0">Стан системи</h3>
    <div class="d-flex gap-2">
        <?php if (!$withSizes): ?><a href="/admin/status?sizes=1" class="btn btn-outline-secondary btn-sm">Порахувати розміри каталогів</a><?php endif; ?>
        <a href="/admin/status" class="btn btn-outline-primary btn-sm">Оновити</a>
    </div>
</div>

<div class="alert alert-<?= $banner[0] ?>"><?= $e($banner[1]) ?></div>
<p class="text-muted small">Сторінка лише читає стан і нічого не змінює. Перевірено: <?= $e(date('d.m.Y H:i:s')) ?>.</p>

<?php foreach ($sections as $s): ?>
    <h5 class="mt-4"><?= $e($s['title']) ?></h5>
    <?php
    $shown = $s['rows'];
    $folded = [];
    if (!empty($s['collapse_ok'])) {
        $shown = array_values(array_filter($s['rows'], fn($r) => $r['level'] !== 'ok' || $r['label'] === 'Підсумок'));
        $folded = array_values(array_filter($s['rows'], fn($r) => $r['level'] === 'ok' && $r['label'] !== 'Підсумок'));
    }
    ?>
    <table class="table table-sm table-bordered bg-white align-middle">
        <tbody>
        <?php foreach ($shown as $r): [$cls, $txt] = $badge[$r['level']] ?? $badge['info']; ?>
            <tr>
                <td style="width:34%"><?= $e($r['label']) ?></td>
                <td>
                    <?= $e($r['value']) ?>
                    <?php if ($r['hint'] !== '' && in_array($r['level'], ['warn', 'fail'], true)): ?>
                        <div class="small text-muted"><?= $e($r['hint']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="width:90px" class="text-center"><span class="badge bg-<?= $cls ?>"><?= $txt ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($folded): ?>
        <details class="mb-2">
            <summary class="small text-muted">Показати застосовані (<?= count($folded) ?>)</summary>
            <table class="table table-sm table-bordered bg-white align-middle mt-2">
                <tbody>
                <?php foreach ($folded as $r): ?>
                    <tr><td style="width:34%"><?= $e($r['label']) ?></td><td><?= $e($r['value']) ?></td><td style="width:90px" class="text-center"><span class="badge bg-success">OK</span></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    <?php endif; ?>
<?php endforeach; ?>
