<?php
$e = static fn($v) => \App\Core\View::e((string) $v);
$statusClass = ['in_use' => 'success', 'in_stock' => 'info', 'repair' => 'warning', 'written_off' => 'secondary'];
$id = (int) $a['id'];
$fmtDate = static fn($d) => $d ? date('d.m.Y', strtotime($d)) : '—';
$w = $a['warranty_until'];
$wCls = $w && $a['status'] !== 'written_off' ? ($w < date('Y-m-d') ? 'text-danger' : ($w <= date('Y-m-d', strtotime('+90 days')) ? 'text-warning' : '')) : '';
$fieldVal = static function (string $k, $v) use ($ownerNames, $fmtDate) {
    if ($v === null || $v === '') { return '—'; }
    return match ($k) {
        'asset_type' => \App\Models\Asset::TYPES[$v] ?? $v,
        'status' => \App\Models\Asset::STATUSES[$v] ?? $v,
        'owner_user_id' => $ownerNames[(int) $v] ?? ('#' . $v),
        'purchase_date', 'warranty_until' => $fmtDate($v),
        default => mb_strimwidth((string) $v, 0, 120, '…'),
    };
};
$actions = ['created' => 'Створено', 'updated' => 'Змінено', 'link_added' => 'Додано зв\'язок', 'link_removed' => 'Прибрано зв\'язок', 'ticket_linked' => 'Прив\'язано тікет', 'ticket_unlinked' => 'Прибрано тікет'];
?>
<div class="mb-3"><a href="/it-assets" class="text-decoration-none">&larr; Реєстр</a></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h3 class="mb-0"><?= $e($a['name']) ?> <small class="text-muted">#<?= $id ?></small></h3>
        <span class="badge text-bg-<?= $statusClass[$a['status']] ?? 'secondary' ?>"><?= $e(\App\Models\Asset::STATUSES[$a['status']] ?? $a['status']) ?></span>
        <span class="text-muted ms-1"><?= $e(\App\Models\Asset::TYPES[$a['asset_type']] ?? $a['asset_type']) ?></span>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" href="/it-assets/<?= $id ?>/edit">Змінити</a>
        <?php if ($canDelete): ?>
        <form method="post" action="/it-assets/<?= $id ?>/delete" data-confirm="Видалити актив «<?= $e($a['name']) ?>» разом зі зв'язками? Дію не можна скасувати.">
            <?= \App\Core\Csrf::field() ?><button class="btn btn-outline-danger" type="submit">Видалити</button></form>
        <?php endif; ?>
    </div>
</div>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>

<div class="card mb-4"><div class="card-body"><dl class="row mb-0">
    <dt class="col-sm-3">Інвентарний №</dt><dd class="col-sm-9"><?= $e($a['inventory_number'] ?? '—') ?></dd>
    <dt class="col-sm-3">Серійний №</dt><dd class="col-sm-9"><?= $e($a['serial_number'] ?? '—') ?></dd>
    <dt class="col-sm-3">Виробник / модель</dt><dd class="col-sm-9"><?= $e(trim(($a['manufacturer'] ?? '') . ' ' . ($a['model'] ?? '')) ?: '—') ?></dd>
    <dt class="col-sm-3">Власник</dt><dd class="col-sm-9"><?= $e($a['owner_name'] ?? '—') ?></dd>
    <dt class="col-sm-3">Місце</dt><dd class="col-sm-9"><?= $e($a['location'] ?? '—') ?></dd>
    <dt class="col-sm-3">ІП</dt><dd class="col-sm-9"><?= $e($a['network_id'] ?? '—') ?></dd>
    <dt class="col-sm-3">Придбано</dt><dd class="col-sm-9"><?= $fmtDate($a['purchase_date']) ?></dd>
    <dt class="col-sm-3">Гарантія до</dt><dd class="col-sm-9 <?= $wCls ?>"><?= $fmtDate($w) ?></dd>
    <?php if (!empty($a['notes'])): ?><dt class="col-sm-3">Примітки</dt><dd class="col-sm-9"><?= nl2br($e($a['notes'])) ?></dd><?php endif; ?>
</dl></div></div>

<div class="row g-4">
<div class="col-lg-6">
<div class="card mb-4">
    <div class="card-header">Зв'язки з іншими активами</div>
    <ul class="list-group list-group-flush">
        <?php if (!$links): ?><li class="list-group-item text-muted small">Зв'язків немає</li><?php endif; ?>
        <?php foreach ($links as $l): ?>
        <li class="list-group-item d-flex align-items-center gap-2 small">
            <span class="text-muted"><?= $e($l['label']) ?></span>
            <a href="/it-assets/<?= (int) $l['other_id'] ?>"><?= $e($l['other_name']) ?></a>
            <?php if (!empty($l['other_inventory'])): ?><span class="text-muted">(<?= $e($l['other_inventory']) ?>)</span><?php endif; ?>
            <form method="post" action="/it-assets/<?= $id ?>/links/<?= (int) $l['link_id'] ?>/delete" class="ms-auto" data-confirm="Прибрати зв'язок?">
                <?= \App\Core\Csrf::field() ?><button class="btn btn-sm btn-outline-danger py-0" type="submit">Прибрати</button></form>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="card-body">
        <form method="post" action="/it-assets/<?= $id ?>/links" class="row g-2 align-items-end">
            <?= \App\Core\Csrf::field() ?>
            <div class="col-md-5"><label class="form-label small mb-1">Цей актив…</label>
                <select name="relation" class="form-select form-select-sm"><?php foreach (\App\Models\Asset::RELATIONS as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l[0]) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-5"><label class="form-label small mb-1">№ картки чи інв. №</label>
                <input type="text" name="other" class="form-control form-control-sm" required></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100" type="submit">Додати</button></div>
        </form>
    </div>
</div>
</div>
<div class="col-lg-6">
<div class="card mb-4">
    <div class="card-header">Тікети по цьому активу</div>
    <ul class="list-group list-group-flush">
        <?php if (!$tickets): ?><li class="list-group-item text-muted small">Тікетів немає</li><?php endif; ?>
        <?php foreach ($tickets as $t): ?>
        <li class="list-group-item d-flex align-items-center gap-2 small">
            <a href="/tickets/<?= (int) $t['id'] ?>">#<?= (int) $t['id'] ?></a> <?= $e($t['subject']) ?>
            <span class="text-muted ms-1"><?= $e(\App\Models\Ticket::statusLabel((string) $t['status'])) ?>, <?= $e(date('d.m.Y', strtotime($t['created_at']))) ?></span>
            <form method="post" action="/it-assets/<?= $id ?>/tickets/<?= (int) $t['id'] ?>/delete" class="ms-auto" data-confirm="Прибрати прив'язку тікета?">
                <?= \App\Core\Csrf::field() ?><button class="btn btn-sm btn-outline-danger py-0" type="submit">Прибрати</button></form>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="card-body">
        <form method="post" action="/it-assets/<?= $id ?>/tickets" class="d-flex gap-2">
            <?= \App\Core\Csrf::field() ?>
            <input type="number" name="ticket_id" min="1" class="form-control form-control-sm" placeholder="№ тікета" required>
            <button class="btn btn-sm btn-outline-primary" type="submit">Прив'язати</button>
        </form>
    </div>
</div>
</div>
</div>

<div class="card mb-4">
    <div class="card-header">Історія змін</div>
    <ul class="list-group list-group-flush small">
        <?php foreach ($history as $h):
            $ch = $h['changes'] ? (json_decode($h['changes'], true) ?: []) : []; ?>
        <li class="list-group-item">
            <span class="text-muted"><?= $e(date('d.m.Y H:i', strtotime($h['created_at']))) ?></span>
            <strong><?= $e($h['user_name'] ?? 'система') ?></strong> — <?= $e($actions[$h['action']] ?? $h['action']) ?>
            <?php if ($h['action'] === 'updated' && !empty($ch['fields'])): ?>
                <ul class="mb-0 mt-1">
                <?php foreach ($ch['fields'] as $k => $pair): ?>
                    <li><?= $e(\App\Models\Asset::FIELD_LABELS[$k] ?? $k) ?>: <span class="text-muted"><?= $e($fieldVal($k, $pair[0] ?? null)) ?></span> → <?= $e($fieldVal($k, $pair[1] ?? null)) ?></li>
                <?php endforeach; ?>
                </ul>
            <?php elseif (in_array($h['action'], ['ticket_linked', 'ticket_unlinked'], true) && !empty($ch['ticket'])): ?>
                <a href="/tickets/<?= (int) $ch['ticket'] ?>">#<?= (int) $ch['ticket'] ?></a>
            <?php elseif ($h['action'] === 'link_added' && !empty($ch['asset'])): ?>
                («<?= $e($ch['relation'] ?? '') ?>» <a href="/it-assets/<?= (int) $ch['asset'] ?>">#<?= (int) $ch['asset'] ?></a>)
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
