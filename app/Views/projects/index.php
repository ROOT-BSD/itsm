<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">Проєкти</h3>
    <?php if (\App\Core\Auth::hasRole(['admin', 'it_manager'])): ?>
        <a href="/projects/create" class="btn btn-primary">+ Новий проєкт</a>
    <?php endif; ?>
</div>

<div class="row">
<?php foreach ($projects as $p): ?>
    <div class="col-md-4 mb-3">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title"><?= \App\Core\View::e($p['name']) ?></h5>
                <p class="card-text text-muted small"><?= \App\Core\View::e(mb_strimwidth($p['description'] ?? '', 0, 100, '…')) ?></p>
                <span class="badge bg-secondary"><?= \App\Core\View::e($p['status']) ?></span>
                <span class="badge bg-info text-dark"><?= (int)$p['open_tasks_count'] ?> відкритих задач</span>
                <div class="small text-muted mt-2">Відповідальний: <?= \App\Core\View::e($p['responsible_name'] ?? 'не призначено') ?></div>
            </div>
            <div class="card-footer bg-white">
                <a href="/projects/<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-primary">Відкрити</a>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
