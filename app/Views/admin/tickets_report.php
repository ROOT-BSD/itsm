<?php
$e = static fn($v) => \App\Core\View::e($v);
$d = static fn(?float $m) => \App\Services\TicketWorkReport::duration($m);
$s = $report['summary'];
$base = ['date_from' => $f['date_from'], 'date_to' => $f['date_to'], 'queue_id' => $f['queue_id'], 'operator_id' => $f['operator_id'], 'status' => $f['status']];
$qs = static fn(array $extra = []) => http_build_query(array_filter($extra + $base, static fn($v) => $v !== null && $v !== ''));
?>
<div class="mb-3">
    <?php if (!empty($unitMode)) { require __DIR__ . '/../unit/_nav.php'; } else { require __DIR__ . '/_back.php'; } ?>
</div>

<h3 class="mb-2">Звіт по тікетах<?= !empty($unitMode) ? ' — мій підрозділ' : '' ?></h3>
<p class="text-muted">Облік часу роботи з тікетами, <strong>створеними в обраний період</strong>: скільки звернень надійшло й вирішено, за який час оператори вперше відповіли
    (від створення до першої відповіді) і за який вирішили (від створення до статусу «Вирішено»/«Закрито»).<?= !empty($unitMode) ? ' Враховуються лише тікети підрозділу.' : '' ?>
    Час — календарний (без урахування вихідних і робочих годин).</p>

<form method="get" class="card card-body shadow-sm mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Створено з</label><input type="date" name="date_from" class="form-control form-control-sm" value="<?= $e($f['date_from']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">по</label><input type="date" name="date_to" class="form-control form-control-sm" value="<?= $e($f['date_to']) ?>"></div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Черга</label>
            <select name="queue_id" class="form-select form-select-sm">
                <option value="">— усі —</option>
                <?php foreach ($queues as $q): ?><option value="<?= (int) $q['id'] ?>" <?= (int) $f['queue_id'] === (int) $q['id'] ? 'selected' : '' ?>><?= $e($q['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Оператор</label>
            <select name="operator_id" class="form-select form-select-sm user-select">
                <option value="">— усі —</option>
                <?php foreach ($operators as $o): ?><option value="<?= (int) $o['id'] ?>" <?= (int) $f['operator_id'] === (int) $o['id'] ? 'selected' : '' ?>><?= $e($o['full_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">Статус</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">— усі —</option>
                <?php foreach (\App\Models\Ticket::STATUS_LABELS as $code => $label): ?><option value="<?= $e($code) ?>" <?= $f['status'] === $code ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1"><button class="btn btn-sm btn-primary w-100" type="submit">Показати</button></div>
    </div>
</form>

<?php if ((int) $s['total'] === 0): ?>
    <div class="alert alert-light border">За обраними фільтрами тікетів немає.</div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="card text-center p-3 h-100 shadow-sm"><h2><?= (int) $s['total'] ?></h2><div class="text-muted">Створено</div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-3 h-100 shadow-sm"><h2><?= (int) $s['resolved'] ?></h2><div class="text-muted">Вирішено / закрито<br><small>(ще відкрито: <?= (int) $s['open'] ?>)</small></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-3 h-100 shadow-sm"><h4 class="mt-1"><?= $e($d($s['response']['avg'])) ?></h4><div class="text-muted">Середній час до першої відповіді<br><small>медіана: <?= $e($d($s['response']['median'])) ?>, найдовше: <?= $e($d($s['response']['max'])) ?> (тікетів: <?= (int) $s['response']['n'] ?>)</small></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-3 h-100 shadow-sm"><h4 class="mt-1"><?= $e($d($s['resolution']['avg'])) ?></h4><div class="text-muted">Середній час до вирішення<br><small>медіана: <?= $e($d($s['resolution']['median'])) ?>, найдовше: <?= $e($d($s['resolution']['max'])) ?> (тікетів: <?= (int) $s['resolution']['n'] ?>)</small></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-3 h-100 shadow-sm"><h2><?= $e(number_format((float) $s['hours'], 2, ',', ' ')) ?></h2><div class="text-muted">Витрачено годин<br><small>за записами обліку часу в тікетах</small></div></div></div>
</div>

<p class="small">
    <?php foreach ($report['byStatus'] as $st): ?><span class="badge bg-light text-dark border me-1"><?= $e($st['label']) ?>: <?= (int) $st['count'] ?></span><?php endforeach; ?>
</p>

<?php foreach ([['По чергах', 'byQueue', 'Черга'], ['По операторах', 'byOperator', 'Оператор']] as [$title, $key, $col]): ?>
    <h5 class="mt-4"><?= $e($title) ?></h5>
    <table class="table table-bordered bg-white table-sm align-middle">
        <thead><tr><th><?= $e($col) ?></th><th>Створено</th><th>Вирішено</th><th>Відкрито</th><th>Витрачено, год</th><th>Перша відповідь (середній / медіана)</th><th>Вирішення (середній / медіана)</th></tr></thead>
        <tbody>
        <?php foreach ($report[$key] as $r): ?>
            <tr>
                <td><?= $e($r['name']) ?></td>
                <td><?= (int) $r['total'] ?></td><td><?= (int) $r['resolved'] ?></td><td><?= (int) $r['open'] ?></td>
                <td><?= $e(number_format((float) $r['hours'], 2, ',', ' ')) ?></td>
                <td><?= $e($d($r['response']['avg'])) ?> / <?= $e($d($r['response']['median'])) ?></td>
                <td><?= $e($d($r['resolution']['avg'])) ?> / <?= $e($d($r['resolution']['median'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endforeach; ?>

<div class="d-flex justify-content-between align-items-center mt-4 mb-2">
    <h5 class="mb-0">Тікети за період (<?= (int) $rowCount ?><?= $rowCount > count($rows) ? ', показано ' . count($rows) : '' ?>)</h5>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-danger" href="<?= $e($exportBase . '?' . $qs(['format' => 'pdf'])) ?>">Експорт PDF</a>
        <a class="btn btn-sm btn-outline-primary" href="<?= $e($exportBase . '?' . $qs(['format' => 'xlsx'])) ?>">Експорт XLSX</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= $e($exportBase . '?' . $qs(['format' => 'csv'])) ?>">Експорт CSV</a>
    </div>
</div>
<table class="table table-bordered bg-white table-sm align-middle">
    <thead><tr><th>№</th><th>Тема</th><th>Черга</th><th>Оператор</th><th>Статус</th><th>Створено</th><th>До першої відповіді</th><th>До вирішення</th><th>Витрачено, год</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $t): ?>
        <tr>
            <td><a href="/tickets/<?= (int) $t['id'] ?>">#<?= (int) $t['id'] ?></a></td>
            <td><?= $e($t['subject']) ?></td>
            <td><?= $e($t['queue_name']) ?></td>
            <td><?= $e($t['operator_name'] ?? '—') ?></td>
            <td><?= $e(\App\Models\Ticket::statusLabel((string) $t['status'])) ?></td>
            <td class="text-nowrap"><?= $e(date('d.m.Y H:i', strtotime($t['created_at']))) ?></td>
            <td class="text-nowrap"><?= $e($d($t['response_min'] !== null ? (float) $t['response_min'] : null)) ?></td>
            <td class="text-nowrap"><?= $e($d($t['resolution_min'] !== null ? (float) $t['resolution_min'] : null)) ?></td>
            <td class="text-nowrap"><?= (float) $t['spent_hours'] > 0 ? $e(number_format((float) $t['spent_hours'], 2, ',', ' ')) : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
