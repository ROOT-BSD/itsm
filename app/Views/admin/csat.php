<div class="mb-3">
    <a href="/admin" class="text-decoration-none">&larr; Адмін-панель</a>
</div>

<h3 class="mb-2">Оцінка якості обслуговування (CSAT)</h3>
<p class="text-muted">
    Заявник оцінює звернення від 1 до 5 після того, як його вирішено чи закрито — на сторінці самого тікета
    (якщо є обліковий запис) або на сторінці відстеження з порталу самообслуговування (якщо немає).
    Оцінити можна лише один раз.
</p>

<?php if ((int)$summary['total'] === 0): ?>
    <div class="alert alert-light border">Оцінок ще немає.</div>
<?php else: ?>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-center p-3 h-100 shadow-sm">
            <h2><?= \App\Core\View::e(number_format((float)$summary['avg_score'], 2)) ?></h2>
            <div class="text-muted">Середня оцінка з 5</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center p-3 h-100 shadow-sm">
            <h2><?= (int)$summary['total'] ?></h2>
            <div class="text-muted">Усього оцінок</div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card p-3 h-100 shadow-sm">
            <div class="text-muted mb-1">Розподіл оцінок</div>
            <?php for ($i = 5; $i >= 1; $i--): $count = (int)$summary["c{$i}"]; $pct = (int)$summary['total'] > 0 ? round($count / (int)$summary['total'] * 100) : 0; ?>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="csat-dist-label"><?= $i ?></span>
                    <div class="progress flex-grow-1 csat-dist-bar" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" style="width: <?= $pct ?>%"></div>
                    </div>
                    <span class="text-muted csat-dist-count"><?= $count ?></span>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-4">
        <h5>По чергах</h5>
        <table class="table table-bordered bg-white align-middle">
            <thead><tr><th>Черга</th><th>Оцінок</th><th>Середня</th></tr></thead>
            <tbody>
            <?php foreach ($byQueue as $row): ?>
                <tr>
                    <td><?= \App\Core\View::e($row['queue_name']) ?></td>
                    <td><?= (int)$row['total'] ?></td>
                    <td><?= \App\Core\View::e(number_format((float)$row['avg_score'], 2)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="col-md-6 mb-4">
        <h5>По операторах</h5>
        <table class="table table-bordered bg-white align-middle">
            <thead><tr><th>Оператор</th><th>Оцінок</th><th>Середня</th></tr></thead>
            <tbody>
            <?php if (empty($byOperator)): ?>
                <tr><td colspan="3" class="text-center text-muted">Немає оцінених тікетів із призначеним оператором</td></tr>
            <?php else: foreach ($byOperator as $row): ?>
                <tr>
                    <td><?= \App\Core\View::e($row['operator_name']) ?></td>
                    <td><?= (int)$row['total'] ?></td>
                    <td><?= \App\Core\View::e(number_format((float)$row['avg_score'], 2)) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<h5>Останні оцінки</h5>
<table class="table table-bordered bg-white align-middle">
    <thead><tr><th>#</th><th>Тема</th><th>Черга</th><th>Оператор</th><th>Оцінка</th><th>Коли</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
        <tr>
            <td><a href="/tickets/<?= (int)$r['id'] ?>">#<?= (int)$r['id'] ?></a></td>
            <td><?= \App\Core\View::e($r['subject']) ?></td>
            <td><?= \App\Core\View::e($r['queue_name']) ?></td>
            <td><?= \App\Core\View::e($r['operator_name'] ?? '— не призначено —') ?></td>
            <td><span class="badge bg-<?= (int)$r['csat_score'] >= 4 ? 'success' : ((int)$r['csat_score'] === 3 ? 'warning text-dark' : 'danger') ?>"><?= (int)$r['csat_score'] ?> з 5</span></td>
            <td class="text-nowrap"><?= \App\Core\View::e($r['updated_at']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php endif; ?>
