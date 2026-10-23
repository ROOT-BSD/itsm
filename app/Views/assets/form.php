<?php
$e = static fn($v) => \App\Core\View::e((string) $v);
$src = $old ?? ($a ?? []);
$v = static fn(string $k, $d = '') => $src[$k] ?? $d;
$action = $a ? '/it-assets/' . (int) $a['id'] : '/it-assets';
?>
<div class="mb-3"><a href="<?= $a ? '/it-assets/' . (int) $a['id'] : '/it-assets' ?>" class="text-decoration-none">&larr; Назад</a></div>
<h3 class="mb-3"><?= $a ? 'Зміна активу #' . (int) $a['id'] : 'Новий актив' ?></h3>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<form method="post" action="<?= $e($action) ?>" class="card p-4 shadow-sm form-card-md">
    <?= \App\Core\Csrf::field() ?>
    <div class="row g-3">
        <div class="col-md-8"><label class="form-label">Назва *</label>
            <input type="text" name="name" maxlength="200" class="form-control" required value="<?= $e($v('name')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Тип</label>
            <select name="asset_type" class="form-select"><?php foreach (\App\Models\Asset::TYPES as $k => $l): ?>
                <option value="<?= $e($k) ?>" <?= $v('asset_type', 'other') === $k ? 'selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Інвентарний №</label>
            <input type="text" name="inventory_number" maxlength="80" class="form-control" value="<?= $e($v('inventory_number')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Серійний №</label>
            <input type="text" name="serial_number" maxlength="120" class="form-control" value="<?= $e($v('serial_number')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Статус</label>
            <select name="status" class="form-select"><?php foreach (\App\Models\Asset::STATUSES as $k => $l): ?>
                <option value="<?= $e($k) ?>" <?= $v('status', 'in_use') === $k ? 'selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Виробник</label>
            <input type="text" name="manufacturer" maxlength="120" class="form-control" value="<?= $e($v('manufacturer')) ?>"></div>
        <div class="col-md-6"><label class="form-label">Модель</label>
            <input type="text" name="model" maxlength="120" class="form-control" value="<?= $e($v('model')) ?>"></div>
        <div class="col-md-6"><label class="form-label">Власник (відповідальний)</label>
            <select name="owner_user_id" class="form-select user-select"><option value="">— немає —</option>
                <?php foreach ($owners as $o): ?><option value="<?= (int) $o['id'] ?>" <?= (int) $v('owner_user_id', 0) === (int) $o['id'] ? 'selected' : '' ?>><?= $e($o['full_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Місце (кабінет, склад…)</label>
            <input type="text" name="location" maxlength="200" class="form-control" value="<?= $e($v('location')) ?>"></div>
        <div class="col-md-6"><label class="form-label">ІП (IP-адреса, ім'я хоста, MAC)</label>
            <input type="text" name="network_id" maxlength="120" class="form-control" value="<?= $e($v('network_id')) ?>"></div>
        <div class="col-md-3"><label class="form-label">Дата придбання</label>
            <input type="date" name="purchase_date" class="form-control" value="<?= $e($v('purchase_date')) ?>"></div>
        <div class="col-md-3"><label class="form-label"><span data-asset-warranty-label>Гарантія до</span></label>
            <input type="date" name="warranty_until" class="form-control" value="<?= $e($v('warranty_until')) ?>"></div>
        <div class="col-md-3" data-asset-seats hidden><label class="form-label">Кількість місць (ліцензій)</label>
            <input type="number" name="license_seats" min="0" max="1000000" step="1" class="form-control" value="<?= $e($v('license_seats')) ?>">
            <div class="form-text">Скільки пристроїв можна встановити. Порожньо — не облікувати.</div></div>
        <div class="col-12"><label class="form-label">Примітки</label>
            <textarea name="notes" rows="3" class="form-control"><?= $e($v('notes')) ?></textarea></div>
    </div>
    <div class="mt-3"><button class="btn btn-primary" type="submit">Зберегти</button></div>
</form>
<script src="<?= $e(\App\Core\View::asset('/assets/js/asset-form.js')) ?>" defer></script>
