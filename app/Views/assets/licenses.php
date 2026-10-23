<?php
$e = static fn($v) => \App\Core\View::e((string) $v);
$cls = ['over' => 'danger', 'expired' => 'danger', 'expiring' => 'warning', 'free' => 'info', 'ok' => 'success'];
$qs = static fn(array $o = []) => http_build_query(array_filter(array_merge(['q' => $q, 'state' => $state], $o), static fn($v) => $v !== ''));
?>
<div class="mb-3"><a href="/it-assets" class="text-decoration-none">&larr; Реєстр</a></div>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h3 class="mb-0">Облік ліцензій ПЗ</h3>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="/it-assets/licenses?<?= $e($qs(['format' => 'xlsx'])) ?>">Експорт XLSX</a>
        <a class="btn btn-outline-secondary" href="/it-assets/licenses?<?= $e($qs(['format' => 'csv'])) ?>">Експорт CSV</a>
    </div>
</div>
<p class="text-muted small">Місця задаються в картці ПЗ, «Встановлено» — кількість пристроїв зі зв'язком «встановлено на». Списане ПЗ у перевірках не враховується.</p>
<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-sm <?= $state === '' ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="/it-assets/licenses?<?= $e($qs(['state' => ''])) ?>">Усі (<?= (int) $total ?>)</a>
    <?php foreach ($states as $k => $l): if ($k === 'ok') { continue; } ?>
        <a class="btn btn-sm btn-outline-<?= $cls[$k] ?> <?= $state === $k ? 'active' : '' ?>" href="/it-assets/licenses?<?= $e($qs(['state' => $k])) ?>"><?= $e($l) ?> (<?= (int) $counts[$k] ?>)</a>
    <?php endforeach; ?>
</div>
<form method="get" action="/it-assets/licenses" class="row g-2 mb-3">
    <input type="hidden" name="state" value="<?= $e($state) ?>">
    <div class="col-md-4"><input type="search" name="q" class="form-control" placeholder="Пошук ПЗ…" value="<?= $e($q) ?>"></div>
    <div class="col-auto"><button class="btn btn-outline-primary" type="submit">Знайти</button></div>
</form>
<table class="table table-bordered bg-white table-sm align-middle">
    <thead><tr><th>ПЗ</th><th>Інв. №</th><th class="text-end">Місць</th><th class="text-end">Встановлено</th><th class="text-end">Вільно</th><th>Діє до</th><th>Стан</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted">Нічого не знайдено</td></tr>
    <?php else: foreach ($rows as $r): ?>
        <tr>
            <td><a href="/it-assets/<?= (int) $r['id'] ?>"><?= $e($r['name']) ?></a><div class="small text-muted"><?= $e(trim(($r['manufacturer'] ?? '') . ' ' . ($r['model'] ?? ''))) ?></div></td>
            <td><?= $e($r['inventory_number'] ?? '—') ?></td>
            <td class="text-end"><?= $r['license_seats'] !== null ? (int) $r['license_seats'] : '—' ?></td>
            <td class="text-end"><?= (int) $r['installed'] ?></td>
            <td class="text-end <?= $r['free'] !== null && $r['free'] < 0 ? 'text-danger fw-bold' : '' ?>"><?= $r['free'] !== null ? (int) $r['free'] : '—' ?></td>
            <td class="text-nowrap"><?= $r['warranty_until'] ? $e(date('d.m.Y', strtotime($r['warranty_until']))) : '—' ?></td>
            <td><span class="badge text-bg-<?= $cls[$r['state']] ?>"><?= $e($states[$r['state']]) ?></span></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
