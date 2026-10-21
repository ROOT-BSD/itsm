<h3 class="mb-4">Дашборд</h3>

<div class="row mb-4">
    <div class="col-md-3">
        <a href="/projects" class="text-decoration-none text-reset">
            <div class="card text-center p-3 h-100 shadow-sm"><h2><?= (int)$stats['active_projects'] ?></h2><div class="text-muted">Активні проєкти</div></div>
        </a>
    </div>
    <div class="col-md-3">
        <div class="card text-center p-3 h-100 shadow-sm"><h2><?= (int)$stats['active_subprojects'] ?></h2><div class="text-muted">Підпроєкти</div></div>
    </div>
    <div class="col-md-3">
        <a href="/tasks" class="text-decoration-none text-reset">
            <div class="card text-center p-3 h-100 shadow-sm"><h2><?= (int)$stats['open_tasks'] ?></h2><div class="text-muted">Відкриті задачі</div></div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="/tickets" class="text-decoration-none text-reset">
            <div class="card text-center p-3 h-100 shadow-sm"><h2><?= (int)$stats['open_tickets'] ?></h2><div class="text-muted">Відкриті тікети</div></div>
        </a>
    </div>
</div>

<h5>Мої відкриті задачі</h5>
<table class="table table-bordered table-sm table-compact-rows bg-white">
    <thead><tr><th>#</th><th>Назва</th><th>Проєкт</th><th>Пріоритет</th><th>Статус</th><th>Термін</th><th>Створено</th></tr></thead>
    <tbody>
    <?php if (empty($myOpenTasks)): ?>
        <tr><td colspan="7" class="text-center text-muted">Немає призначених відкритих задач</td></tr>
    <?php else:
        // Групи за проєктом (у межах групи — за терміном, далі за статусом); кожен проєкт має власний стійкий пастельний колір.
        $prevProject = null;
        foreach ($myOpenTasks as $t):
            $pid = (int) $t['project_id'];
            $hue = ($pid * 47) % 360;
            $rowBg = '--bs-table-bg: hsl(' . $hue . ', 55%, 94%);';
            if ($prevProject !== $pid): $prevProject = $pid; ?>
        <tr style="--bs-table-bg: hsl(<?= $hue ?>, 45%, 86%);">
            <td colspan="7" class="fw-semibold"><a href="/projects/<?= $pid ?>" class="text-decoration-none text-body"><?= \App\Core\View::e($t['project_name']) ?></a></td>
        </tr>
        <?php endif; ?>
        <tr style="<?= $rowBg ?>">
            <td><a href="/tasks/<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
            <td><?= \App\Core\View::e($t['title']) ?></td>
            <td><a href="/projects/<?= $pid ?>"><?= \App\Core\View::e($t['project_name']) ?></a></td>
            <td><span class="badge prio-<?= \App\Core\View::e($t['priority']) ?>"><?= \App\Core\View::e($t['priority']) ?></span></td>
            <td><?= \App\Core\View::e($t['status_name']) ?></td>
            <td class="<?= ($u = \App\Core\View::urgencyClass($t['due_date'] ?? null)) !== '' ? 'dash-due-' . substr($u, -1) : '' ?>">
                <?php if (!empty($t['due_date']) && $t['due_date'] < date('Y-m-d')): ?>
                    <span class="badge bg-danger">⚠️ <?= \App\Core\View::e($t['due_date']) ?></span>
                <?php elseif (!empty($t['due_date'])): ?>
                    <?= \App\Core\View::e($t['due_date']) ?>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td><?= \App\Core\View::e($t['created_at_formatted']) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
