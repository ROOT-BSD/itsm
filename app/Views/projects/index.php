<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">Проєкти</h3>
    <?php if (\App\Core\Auth::hasRole(['admin', 'it_manager'])): ?>
        <a href="/projects/create" class="btn btn-primary">+ Новий проєкт</a>
    <?php endif; ?>
</div>

<div class="row">
<?php
$statusLabels = ['active' => 'Активний', 'archived' => 'Архівний', 'closed' => 'Закритий'];
$statusColors = ['active' => 'success', 'archived' => 'secondary', 'closed' => 'dark'];
// Українська плюралізація: 1 підпроєкт, 2–4 підпроєкти, 5+ (і 11–14) підпроєктів.
$subProjectsWord = static function (int $n): string {
    if ($n % 10 === 1 && $n % 100 !== 11) {
        return 'підпроєкт';
    }
    if (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true)) {
        return 'підпроєкти';
    }
    return 'підпроєктів';
};
?>
<?php foreach ($projects as $p): ?>
    <div class="col-md-4 mb-3">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title"><?= \App\Core\View::e($p['name']) ?></h5>
                <p class="card-text text-muted small"><?= \App\Core\View::e(mb_strimwidth($p['description'] ?? '', 0, 100, '…')) ?></p>
                <span class="badge bg-<?= $statusColors[$p['status']] ?? 'secondary' ?>"><?= \App\Core\View::e($statusLabels[$p['status']] ?? $p['status']) ?></span>
                <span class="badge bg-info text-dark"><?= (int)$p['open_tasks_count'] ?> відкритих задач</span>
                <?php if ((int)$p['sub_projects_count'] > 0): ?>
                    <span class="badge bg-light text-dark border">📁 <?= (int)$p['sub_projects_count'] ?> <?= $subProjectsWord((int)$p['sub_projects_count']) ?></span>
                <?php endif; ?>
                <?php if (in_array((int)$p['id'], $overdueProjectIds, true)): ?>
                    <span class="badge bg-danger">⚠️ Прострочено етап</span>
                <?php endif; ?>
                <div class="small text-muted mt-2">Відповідальний: <?= \App\Core\View::e($p['responsible_name'] ?? 'не призначено') ?></div>
            </div>
            <div class="card-footer bg-white">
                <a href="/projects/<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-primary">Відкрити</a>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
