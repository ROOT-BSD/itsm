<?php if (!empty($project['parent_id'])): ?>
<div class="mb-2">
    <a href="/projects/<?= (int)$project['parent_id'] ?>" class="text-decoration-none">&larr; <?= \App\Core\View::e($project['parent_name']) ?></a>
    <span class="badge bg-light text-dark border ms-1">Підпроєкт</span>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h3 class="mb-0"><?= \App\Core\View::e($project['name']) ?></h3>
        <small class="text-muted">Створив: <?= \App\Core\View::e($project['created_by_name']) ?></small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="/projects/<?= (int)$project['id'] ?>/board" class="btn btn-outline-secondary">📋 Канбан-дошка</a>
        <a href="/projects/<?= (int)$project['id'] ?>/gantt" class="btn btn-outline-secondary">📊 Діаграма Ганта</a>
        <a href="/projects/<?= (int)$project['id'] ?>/roadmap" class="btn btn-outline-secondary">🗺️ Дорожня карта</a>
        <a href="/projects/<?= (int)$project['id'] ?>/time" class="btn btn-outline-secondary">⏱️ Облік часу</a>
        <a href="/projects/create?parent_id=<?= (int)$project['id'] ?>" class="btn btn-outline-secondary">+ Підпроєкт</a>
        <a href="/tickets/create?project_id=<?= (int)$project['id'] ?>" class="btn btn-outline-secondary">+ Тікет</a>
        <a href="/projects/<?= (int)$project['id'] ?>/tasks/create" class="btn btn-primary">+ Нова задача</a>
    </div>
</div>

<?php if (!empty($overdueMilestones)): ?>
<div class="alert alert-danger">
    ⚠️ Прострочено етап<?= count($overdueMilestones) > 1 ? 'и' : '' ?> дорожньої карти:
    <?php $parts = []; foreach ($overdueMilestones as $m): ?>
        <?php $parts[] = '«' . \App\Core\View::e($m['title']) . '» (мав бути готовий до ' . \App\Core\View::e($m['target_date']) . ')'; ?>
    <?php endforeach; ?>
    <?= implode(', ', $parts) ?>
</div>
<?php endif; ?>

<p><?= nl2br(\App\Core\View::e($project['description'])) ?></p>

<?php if (!empty($subProjects)): ?>
<h5>Підпроєкти</h5>
<?php
$statusLabels = ['active' => 'Активний', 'archived' => 'Архівний', 'closed' => 'Закритий'];
$statusColors = ['active' => 'success', 'archived' => 'secondary', 'closed' => 'dark'];
?>
<div class="row">
    <?php foreach ($subProjects as $sp): ?>
        <div class="col-md-4 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title"><?= \App\Core\View::e($sp['name']) ?></h5>
                    <p class="card-text text-muted small"><?= \App\Core\View::e(mb_strimwidth($sp['description'] ?? '', 0, 100, '…')) ?></p>
                    <span class="badge bg-<?= $statusColors[$sp['status']] ?? 'secondary' ?>"><?= \App\Core\View::e($statusLabels[$sp['status']] ?? $sp['status']) ?></span>
                    <span class="badge bg-info text-dark"><?= (int)$sp['open_tasks_count'] ?> відкритих задач</span>
                    <div class="small text-muted mt-2">Відповідальний: <?= \App\Core\View::e($sp['responsible_name'] ?? 'не призначено') ?></div>
                </div>
                <div class="card-footer bg-white">
                    <a href="/projects/<?= (int)$sp['id'] ?>" class="btn btn-sm btn-outline-primary">Відкрити</a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (\App\Core\Access::canManageProjects(\App\Core\Auth::role())): ?>
<form method="post" action="/projects/<?= (int)$project['id'] ?>/responsible" class="d-flex gap-2 align-items-center mb-3">
    <?= \App\Core\Csrf::field() ?>
    <label class="form-label mb-0">Відповідальний (виконавець проєкту):</label>
    <select name="responsible_user_id" class="form-select w-auto user-select">
        <option value="">— не призначено —</option>
        <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)($project['responsible_user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                <?= \App\Core\View::e($u['full_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-sm btn-outline-primary">Зберегти</button>
</form>
<?php else: ?>
<p class="text-muted small">
    Відповідальний за проєкт: <?= \App\Core\View::e($project['responsible_name'] ?? 'не призначено') ?>
</p>
<?php endif; ?>

<?php
// Блок «Доступ до проєкту»: автор і відповідальний мають доступ завжди; решті його надають явно (учасники).
// Керують складом автор, відповідальний і адміністратор ($canManageMembers); учасник лише бачить, хто ще має доступ.
$eMember = static fn($v) => \App\Core\View::e($v);
?>
<?php if (empty($membersAvailable)): ?>
    <?php if (!empty($canManageMembers)): ?>
    <div class="alert alert-warning" id="project-access">
        <strong>🔑 Надання доступу до проєкту ще не активовано.</strong>
        На сервері оновлено файли застосунку, але не виконано оновлення бази даних. Адміністратору потрібно запустити
        <code>sudo bash update.sh</code> (крок «Перевірка таблиці учасників проєктів» створить потрібну таблицю). До того проєкт
        бачать лише автор, відповідальний та адміністратор — як і раніше.
    </div>
    <?php endif; ?>
<?php else: ?>
<div class="card mb-3" id="project-access">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>🔑 Доступ до проєкту<?= !empty($members) ? ' (учасників: ' . count($members) . ')' : '' ?></span>
        <span class="text-muted small">Автор і відповідальний мають доступ завжди</span>
    </div>
    <div class="card-body">
        <?php if (!empty($success)): ?>
            <div class="alert alert-success py-2"><?= $eMember($success) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2"><?= $eMember($error) ?></div>
        <?php endif; ?>

        <?php if (empty($members)): ?>
            <p class="text-muted mb-3">Додаткових учасників ще немає — проєкт бачать лише автор, відповідальний та адміністратор системи.</p>
        <?php else: ?>
            <ul class="list-group mb-3">
                <?php foreach ($members as $m): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-3">
                        <div>
                            <span class="fw-semibold"><?= $eMember($m['full_name']) ?></span>
                            <?php if (!(int)$m['is_active']): ?><span class="badge bg-secondary">деактивовано</span><?php endif; ?>
                            <div class="small text-muted">
                                <?= $eMember($m['email']) ?>
                                · доступ надано <?= $eMember(date('d.m.Y', strtotime($m['created_at']))) ?><?= !empty($m['added_by_name']) ? ' (' . $eMember($m['added_by_name']) . ')' : '' ?>
                            </div>
                        </div>
                        <?php if ($canManageMembers): ?>
                            <form method="post" action="/projects/<?= (int)$project['id'] ?>/members/<?= (int)$m['id'] ?>/delete"
                                  data-confirm="Забрати доступ до проєкту в користувача «<?= $eMember($m['full_name']) ?>»? Він більше не бачитиме цей проєкт і його задачі.">
                                <?= \App\Core\Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger">Забрати доступ</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($canManageMembers): ?>
            <form method="post" action="/projects/<?= (int)$project['id'] ?>/members" class="d-flex gap-2 align-items-center flex-wrap">
                <?= \App\Core\Csrf::field() ?>
                <label class="form-label mb-0">Надати доступ користувачу:</label>
                <select name="user_id" class="form-select w-auto user-select">
                    <option value="">— оберіть користувача —</option>
                    <?php foreach ($memberCandidates as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= $eMember($u['full_name']) ?> (<?= $eMember($u['email']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-primary">Надати доступ</button>
            </form>
            <div class="form-text mt-2">
                Учасник бачить проєкт і всі його задачі та працює з ними так само, як автор чи відповідальний, але
                не може керувати доступом. Підпроєкти мають власний перелік учасників.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<table class="table table-bordered bg-white">
    <thead>
        <tr><th>#</th><th>Назва</th><th>Тип</th><th>Статус</th><th>Пріоритет</th><th>Виконавець</th><th>Термін</th></tr>
    </thead>
    <tbody>
    <?php if (empty($tasks)): ?>
        <tr><td colspan="7" class="text-center text-muted">Задач ще немає</td></tr>
    <?php else: foreach ($tasks as $t): ?>
        <tr>
            <td><a href="/tasks/<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
            <td><?= \App\Core\View::e($t['title']) ?></td>
            <td><?= \App\Core\View::e($t['type_name']) ?></td>
            <td><span class="badge bg-secondary"><?= \App\Core\View::e($t['status_name']) ?></span></td>
            <td><?= \App\Core\View::e($t['priority']) ?></td>
            <td><?= \App\Core\View::e($t['assignee_name'] ?? '—') ?></td>
            <td>
                <?php if (\App\Models\Task::isOverdue($t)): ?>
                    <span class="badge bg-danger">⚠️ <?= \App\Core\View::e($t['due_date']) ?></span>
                <?php elseif (!empty($t['due_date'])): ?>
                    <?= \App\Core\View::e($t['due_date']) ?>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

<?php if (!empty($linkedTickets)): ?>
<h5 class="mt-4">Пов'язані тікети</h5>
<table class="table table-bordered bg-white">
    <thead><tr><th>#</th><th>Тема</th><th>Черга</th><th>Статус</th><th>Оператор</th></tr></thead>
    <tbody>
    <?php
    $ticketStatusLabels = ['new' => 'Новий', 'in_progress' => 'В роботі', 'waiting_customer' => 'Очікує відповіді', 'resolved' => 'Вирішено', 'closed' => 'Закрито'];
    ?>
    <?php foreach ($linkedTickets as $tk): ?>
        <tr>
            <td><a href="/tickets/<?= (int)$tk['id'] ?>">#<?= (int)$tk['id'] ?></a></td>
            <td><?= \App\Core\View::e($tk['subject']) ?></td>
            <td><?= \App\Core\View::e($tk['queue_name']) ?></td>
            <td><span class="badge bg-secondary"><?= \App\Core\View::e($ticketStatusLabels[$tk['status']] ?? $tk['status']) ?></span></td>
            <td><?= \App\Core\View::e($tk['operator_name'] ?? '— не призначено —') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
