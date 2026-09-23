<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="/projects/<?= (int)$project['id'] ?>" class="text-decoration-none">&larr; <?= \App\Core\View::e($project['name']) ?></a>
        <h3 class="mb-0 mt-1">Дорожня карта</h3>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= \App\Core\View::e($success) ?></div>
<?php endif; ?>

<?php
$statusLabels = ['planned' => 'Заплановано', 'in_progress' => 'У процесі', 'completed' => 'Завершено', 'delayed' => 'Затримується'];
$statusColors = ['planned' => 'secondary', 'in_progress' => 'primary', 'completed' => 'success', 'delayed' => 'danger'];
?>

<?php if (empty($milestones)): ?>
    <p class="text-muted">У проєкті ще немає етапів. Додайте перший нижче.</p>
<?php else: ?>
    <div class="roadmap-timeline mb-4">
        <?php foreach ($milestones as $milestone): ?>
            <?php
            $counts = $taskCounts[$milestone['id']] ?? ['total' => 0, 'closed' => 0];
            $progressPct = $counts['total'] > 0 ? (int) round($counts['closed'] / $counts['total'] * 100) : 0;
            $milestoneTasks = $tasksByMilestone[$milestone['id']] ?? [];
            ?>
            <div class="card mb-3 roadmap-milestone">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <span class="badge bg-<?= $statusColors[$milestone['status']] ?? 'secondary' ?> mb-1">
                                <?= \App\Core\View::e($statusLabels[$milestone['status']] ?? $milestone['status']) ?>
                            </span>
                            <h5 class="mb-0"><?= \App\Core\View::e($milestone['title']) ?></h5>
                            <?php if (!empty($milestone['target_date'])): ?>
                                <div class="small text-muted">Ціль: <?= \App\Core\View::e($milestone['target_date']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-2 align-items-start">
                            <form method="post" action="/projects/<?= (int)$project['id'] ?>/milestones/<?= (int)$milestone['id'] ?>/status" class="d-flex gap-1">
                                <?= \App\Core\Csrf::field() ?>
                                <select name="status" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
                                    <?php foreach ($statusLabels as $code => $label): ?>
                                        <option value="<?= $code ?>" <?= $milestone['status'] === $code ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <form method="post" action="/projects/<?= (int)$project['id'] ?>/milestones/<?= (int)$milestone['id'] ?>/delete"
                                  onsubmit="return confirm('Видалити етап «<?= \App\Core\View::e($milestone['title']) ?>»? Прив\'язані задачі НЕ видаляються, лише втратять прив\'язку до цього етапу.');">
                                <?= \App\Core\Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger">Видалити</button>
                            </form>
                        </div>
                    </div>

                    <?php if (!empty($milestone['description'])): ?>
                        <p class="mt-2 mb-2"><?= nl2br(\App\Core\View::e($milestone['description'])) ?></p>
                    <?php endif; ?>

                    <?php if ($counts['total'] > 0): ?>
                        <div class="progress mb-2 roadmap-progress">
                            <div class="progress-bar bg-success" style="width: <?= $progressPct ?>%"></div>
                        </div>
                        <div class="small text-muted mb-2"><?= $counts['closed'] ?> з <?= $counts['total'] ?> задач закрито (<?= $progressPct ?>%)</div>
                    <?php endif; ?>

                    <?php if (!empty($milestoneTasks)): ?>
                        <ul class="list-unstyled mb-0 small">
                            <?php foreach ($milestoneTasks as $t): ?>
                                <li>
                                    <a href="/tasks/<?= (int)$t['id'] ?>" class="<?= $t['is_closed'] ? 'text-decoration-line-through text-muted' : 'text-decoration-none' ?>">
                                        #<?= (int)$t['id'] ?> <?= \App\Core\View::e($t['title']) ?>
                                    </a>
                                    <span class="text-muted">— <?= \App\Core\View::e($t['status_name']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="small text-muted mb-0">До цього етапу ще не прив'язано жодної задачі. Прив'язати можна на сторінці самої задачі.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (\App\Core\Auth::hasRole(['admin', 'it_manager'])): ?>
<div class="card">
    <div class="card-header">Додати новий етап</div>
    <div class="card-body">
        <form method="post" action="/projects/<?= (int)$project['id'] ?>/milestones" class="row g-2 align-items-end">
            <?= \App\Core\Csrf::field() ?>
            <div class="col-md-4">
                <label class="form-label small mb-1">Назва етапу</label>
                <input type="text" name="title" class="form-control form-control-sm" required placeholder="напр. Реліз MVP">
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Опис (необов'язково)</label>
                <input type="text" name="description" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Цільова дата</label>
                <input type="date" name="target_date" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">Додати етап</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
