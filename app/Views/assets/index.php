<?php
$e = static fn($v) => \App\Core\View::e((string) $v);
$qs = static fn(array $over = []) => http_build_query(array_filter(array_merge($f, $over), static fn($v) => $v !== '' && $v !== 0 && $v !== null));
$typeLabels = \App\Models\Asset::TYPES;
$statusClass = ['in_use' => 'success', 'in_stock' => 'info', 'repair' => 'warning', 'written_off' => 'secondary'];
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h3 class="mb-0">Реєстр обладнання й ПЗ</h3>
    <?php if (!empty($ready)): ?>
    <div class="d-flex gap-2">
        <a class="btn btn-primary" href="/it-assets/create">+ Новий актив</a>
        <a class="btn btn-outline-secondary" href="/it-assets/licenses">🔑 Ліцензії ПЗ</a>
        <a class="btn btn-outline-secondary" href="/it-assets/import">Імпорт</a>
        <a class="btn btn-outline-secondary" href="/it-assets/export?<?= $e($qs(['format' => 'xlsx'])) ?>">Експорт XLSX</a>
        <a class="btn btn-outline-secondary" href="/it-assets/export?<?= $e($qs(['format' => 'csv'])) ?>">Експорт CSV</a>
    </div>
    <?php endif; ?>
</div>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>
<?php if (empty($ready)): ?>
    <div class="alert alert-warning">Реєстр ще не налаштовано — виконайте <code>sudo bash update.sh</code> (міграція 047).</div>
<?php return; endif; ?>

<form method="get" action="/it-assets" class="row g-2 mb-3">
    <div class="col-md-3"><input type="search" name="q" class="form-control" placeholder="Пошук: назва, інв. №, серійний №, місце, власник…" value="<?= $e($f['q']) ?>"></div>
    <div class="col-md-2"><select name="type" class="form-select"><option value="">Усі типи</option>
        <?php foreach ($typeLabels as $k => $l): ?><option value="<?= $e($k) ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><select name="status" class="form-select"><option value="">Усі статуси</option>
        <?php foreach (\App\Models\Asset::STATUSES as $k => $l): ?><option value="<?= $e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><select name="owner_id" class="form-select"><option value="">Усі власники</option>
        <?php foreach ($owners as $o): ?><option value="<?= (int) $o['id'] ?>" <?= (int) $f['owner_id'] === (int) $o['id'] ? 'selected' : '' ?>><?= $e($o['full_name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><select name="warranty" class="form-select">
        <option value="">Гарантія: будь-яка</option>
        <option value="expiring" <?= $f['warranty'] === 'expiring' ? 'selected' : '' ?>>Спливає за 90 днів</option>
        <option value="expired" <?= $f['warranty'] === 'expired' ? 'selected' : '' ?>>Вже закінчилась</option></select></div>
    <div class="col-md-1"><button class="btn btn-outline-primary w-100" type="submit">Знайти</button></div>
</form>

<div class="text-muted small mb-2">Знайдено: <?= (int) $total ?></div>
<table class="table table-bordered bg-white table-sm align-middle">
    <thead><tr><th>№</th><th>Назва</th><th>Тип</th><th>Інв. №</th><th>Серійний №</th><th>Власник</th><th>ІП</th><th>Місце</th><th>Статус</th><th>Гарантія до</th></tr></thead>
    <tbody>
    <?php if (!$assets): ?><tr><td colspan="10" class="text-center text-muted">Нічого не знайдено</td></tr>
    <?php else: foreach ($assets as $a):
        $w = $a['warranty_until'];
        $wCls = $w && $a['status'] !== 'written_off' ? ($w < date('Y-m-d') ? 'text-danger' : ($w <= date('Y-m-d', strtotime('+90 days')) ? 'text-warning' : '')) : ''; ?>
        <tr>
            <td><a href="/it-assets/<?= (int) $a['id'] ?>">#<?= (int) $a['id'] ?></a></td>
            <td><a href="/it-assets/<?= (int) $a['id'] ?>"><?= $e($a['name']) ?></a><div class="small text-muted"><?= $e(trim(($a['manufacturer'] ?? '') . ' ' . ($a['model'] ?? ''))) ?></div></td>
            <td><?= $e($typeLabels[$a['asset_type']] ?? $a['asset_type']) ?></td>
            <td><?= $e($a['inventory_number'] ?? '—') ?></td>
            <td><?= $e($a['serial_number'] ?? '—') ?></td>
            <td><?= $e($a['owner_name'] ?? '—') ?></td>
            <td class="text-nowrap"><?= $e(($a['network_id'] ?? '') !== '' ? $a['network_id'] : '—') ?></td>
            <td><?= $e($a['location'] ?? '—') ?></td>
            <td><span class="badge text-bg-<?= $statusClass[$a['status']] ?? 'secondary' ?>"><?= $e(\App\Models\Asset::STATUSES[$a['status']] ?? $a['status']) ?></span></td>
            <td class="text-nowrap <?= $wCls ?>"><?= $w ? $e(date('d.m.Y', strtotime($w))) : '—' ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
<?php if ($pages > 1): ?>
<nav><ul class="pagination pagination-sm">
    <?php for ($p = 1; $p <= $pages; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="/it-assets?<?= $e($qs(['page' => $p])) ?>"><?= $p ?></a></li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>
