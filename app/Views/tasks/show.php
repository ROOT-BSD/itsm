<div class="mb-3">
    <a href="/projects/<?= (int)$task['project_id'] ?>" class="text-decoration-none">&larr; <?= \App\Core\View::e($task['project_name']) ?></a>
</div>

<?php if (!empty($parentTask)): ?>
    <div class="mb-3 text-muted">Підзадача задачі <a href="/tasks/<?= (int)$parentTask['id'] ?>">#<?= (int)$parentTask['id'] ?> <?= \App\Core\View::e($parentTask['title']) ?></a></div>
<?php endif; ?>

<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($_GET['error']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['success'])): ?>
    <div class="alert alert-success"><?= \App\Core\View::e($_GET['success']) ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h3><?= \App\Core\View::e($task['title']) ?> <small class="text-muted">#<?= (int)$task['id'] ?></small></h3>
        <details class="mb-3">
            <summary class="text-muted small">✏️ Змінити назву</summary>
            <form method="post" action="/tasks/<?= (int)$task['id'] ?>/title" class="d-flex gap-2 mt-2">
                <?= \App\Core\Csrf::field() ?>
                <input type="text" name="title" class="form-control" maxlength="255" required value="<?= \App\Core\View::e($task['title']) ?>">
                <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit">Зберегти назву</button>
            </form>
        </details>

        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/description" class="mb-3">
            <?= \App\Core\Csrf::field() ?>
            <label class="form-label">Опис</label>
            <textarea name="description" class="form-control mb-2" rows="4"><?= \App\Core\View::e($task['description'] ?? '') ?></textarea>
            <button class="btn btn-sm btn-outline-primary" type="submit">Зберегти опис</button>
        </form>

        <?php if (\App\Models\Task::isOverdue($task)): ?>
            <div class="alert alert-danger py-2">⚠️ Прострочено термін виконання (мав бути готовий до <?= \App\Core\View::e($task['due_date']) ?>)</div>
        <?php endif; ?>

        <table class="table table-sm w-auto">
            <tr><th>Тип</th><td><?= \App\Core\View::e($task['type_name']) ?></td></tr>
            <tr><th>Пріоритет</th><td><?= \App\Core\View::e($task['priority']) ?></td></tr>
            <tr><th>Автор</th><td><?= \App\Core\View::e($task['author_name']) ?></td></tr>
            <?php
            // Початкові значення (міграція 043) показуємо, лише коли поточні від них відрізняються.
            $planned = static function (?string $orig, ?string $cur): string {
                $orig = $orig ? substr($orig, 0, 10) : null;
                $cur = $cur ? substr($cur, 0, 10) : null;
                if ($orig === null || $orig === $cur) {
                    return '';
                }
                $shift = '';
                if ($cur !== null) {
                    $days = (int) round((strtotime($cur) - strtotime($orig)) / 86400);
                    $shift = ' <span class="badge ' . ($days > 0 ? 'bg-danger' : 'bg-success') . '">' . ($days > 0 ? '+' : '') . $days . ' дн.</span>';
                }
                return '<div class="small text-muted">початково: ' . \App\Core\View::e($orig) . $shift . '</div>';
            };
            ?>
            <tr><th>Дата початку</th><td><?= \App\Core\View::e($task['start_date'] ?? '—') ?><?= $planned($task['planned_start_date'] ?? null, $task['start_date'] ?? null) ?></td></tr>
            <tr><th>Термін</th><td><?= \App\Core\View::e($task['due_date'] ?? '—') ?><?= $planned($task['planned_due_date'] ?? null, $task['due_date'] ?? null) ?></td></tr>
            <tr><th>Витрачено годин</th><td><?= \App\Core\View::e((string)$task['actual_hours']) ?></td></tr>
        </table>

        <details class="mb-3">
            <summary class="text-muted small">📅 Змінити терміни</summary>
            <form method="post" action="/tasks/<?= (int)$task['id'] ?>/schedule" class="row g-2 align-items-end mt-1">
                <?= \App\Core\Csrf::field() ?>
                <div class="col-auto">
                    <label class="form-label small mb-1">Дата початку</label>
                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?= \App\Core\View::e(substr((string) ($task['start_date'] ?? ''), 0, 10)) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">Термін</label>
                    <input type="date" name="due_date" class="form-control form-control-sm" value="<?= \App\Core\View::e(substr((string) ($task['due_date'] ?? ''), 0, 10)) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1">Причина зміни (необов'язково)</label>
                    <input type="text" name="reason" class="form-control form-control-sm" maxlength="255" placeholder="напр. чекаємо на обладнання">
                </div>
                <div class="col-auto"><button class="btn btn-sm btn-outline-primary" type="submit">Зберегти терміни</button></div>
            </form>
            <p class="text-muted small mt-1 mb-0">Початкові значення зберігаються й далі показуються поруч із поточними; кожна зміна потрапляє в історію.</p>
        </details>
        <?php if (!empty($dateChanges)): ?>
            <details class="mb-3">
                <summary class="text-muted small">🕘 Історія змін термінів (<?= count($dateChanges) ?>)</summary>
                <table class="table table-sm mt-2 mb-0">
                    <thead><tr><th>Коли</th><th>Хто</th><th>Початок</th><th>Термін</th><th>Причина</th></tr></thead>
                    <tbody>
                    <?php foreach ($dateChanges as $c): ?>
                        <tr>
                            <td class="text-nowrap"><?= \App\Core\View::e(date('d.m.Y H:i', strtotime($c['created_at']))) ?></td>
                            <td><?= \App\Core\View::e($c['user_name'] ?? '—') ?></td>
                            <td class="text-nowrap"><?= \App\Core\View::e($c['old_start_date'] ?? '—') ?> → <?= \App\Core\View::e($c['new_start_date'] ?? '—') ?></td>
                            <td class="text-nowrap"><?= \App\Core\View::e($c['old_due_date'] ?? '—') ?> → <?= \App\Core\View::e($c['new_due_date'] ?? '—') ?></td>
                            <td><?= \App\Core\View::e($c['reason'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>

        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/assignee" class="d-flex gap-2 align-items-center mb-2">
    <?= \App\Core\Csrf::field() ?>
            <label class="form-label mb-0">Виконавець:</label>
            <select name="assignee_id" class="form-select w-auto user-select">
                <option value="">— не призначено —</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)($task['assignee_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= \App\Core\View::e($u['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary" type="submit">Призначити</button>
        </form>

        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/milestone" class="d-flex gap-2 align-items-center mb-2">
            <?= \App\Core\Csrf::field() ?>
            <label class="form-label mb-0">Етап:</label>
            <select name="milestone_id" class="form-select w-auto auto-submit-select">
                <option value="">— не прив'язано —</option>
                <?php foreach ($milestones as $m): ?>
                    <option value="<?= (int)$m['id'] ?>" <?= (int)($task['milestone_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
                        <?= \App\Core\View::e($m['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/status" class="d-flex gap-2 align-items-center mb-2">
    <?= \App\Core\Csrf::field() ?>
            <label class="form-label mb-0">Статус:</label>
            <select name="status_id" class="form-select w-auto">
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === (int)$task['status_id']) ? 'selected' : '' ?>><?= \App\Core\View::e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary" type="submit">Оновити</button>
        </form>

        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/project" class="d-flex gap-2 align-items-center mb-3">
            <?= \App\Core\Csrf::field() ?>
            <label class="form-label mb-0">Проєкт:</label>
            <select name="project_id" class="form-select w-auto">
                <?php foreach ($projects as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (int)$task['project_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= \App\Core\View::e($p['name']) ?><?= !empty($p['parent_id']) ? ' (підпроєкт «' . \App\Core\View::e($p['parent_name']) . '»)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary" type="submit">Перенести</button>
        </form>
        <p class="text-muted small">
            Перенесення в інший проєкт знімає прив'язку до етапу дорожньої карти й видаляє зв'язки з задачами, що лишаються в іншому проєкті — залежність можлива лише між задачами одного проєкту.
        </p>

        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/delete"
              data-confirm="Остаточно видалити задачу «<?= \App\Core\View::e($task['title']) ?>»? Коментарі, чек-лист, записи обліку часу та зв'язки з іншими задачами буде видалено разом з нею<?= !empty($subtasks) ? '; підзадачі лишаться як самостійні задачі' : '' ?>. Дію не можна скасувати.">
            <?= \App\Core\Csrf::field() ?>
            <button class="btn btn-sm btn-outline-danger" type="submit">🗑️ Видалити задачу</button>
        </form>
        <?php if (!empty($parentTask)): ?>
            <form method="post" action="/tasks/<?= (int)$task['id'] ?>/detach" class="mt-2" data-confirm="Відв'язати підзадачу від батьківської задачі? Вона стане самостійною задачею.">
                <?= \App\Core\Csrf::field() ?>
                <button class="btn btn-sm btn-outline-secondary" type="submit">Відв'язати від батьківської задачі</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($checklistReady) && \App\Models\Template::ready() && \App\Core\Access::canManageProjects(\App\Core\Auth::role())): ?>
    <form method="post" action="/tasks/<?= (int)$task['id'] ?>/save-template" class="mb-3">
        <?= \App\Core\Csrf::field() ?>
        <button class="btn btn-sm btn-outline-secondary" type="submit">Зберегти як шаблон задачі</button>
    </form>
<?php endif; ?>

<?php if (!empty($checklistReady)): ?>
<?php
$clTotal = count($checklist);
$clDone = count(array_filter($checklist, fn($i) => (int)$i['is_done'] === 1));
$clPct = $clTotal > 0 ? (int) round($clDone / $clTotal * 100) : 0;
$stTotal = count($subtasks);
$stDone = count(array_filter($subtasks, fn($s) => (int)$s['is_closed'] === 1));
$stPct = $stTotal > 0 ? (int) round($stDone / $stTotal * 100) : 0;
?>
<div class="card mb-4" id="checklist">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Чек-лист<?= $clTotal ? ' (' . $clDone . '/' . $clTotal . ')' : '' ?></span>
        <?php if ($clDone > 0): ?>
            <form method="post" action="/tasks/<?= (int)$task['id'] ?>/checklist/done/delete" class="mb-0" data-confirm="Видалити всі виконані пункти чек-листа?">
                <?= \App\Core\Csrf::field() ?>
                <button class="btn btn-sm btn-link text-muted p-0" type="submit">прибрати виконані</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($clTotal > 0): ?>
            <div class="progress mb-3" style="height:8px" role="progressbar" aria-valuenow="<?= $clPct ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar bg-success" style="width:<?= $clPct ?>%"></div>
            </div>
            <ul class="list-unstyled mb-3">
                <?php foreach ($checklist as $item): $done = (int)$item['is_done'] === 1; ?>
                    <li class="d-flex align-items-start gap-2 mb-1">
                        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/checklist/<?= (int)$item['id'] ?>/toggle" class="mb-0">
                            <?= \App\Core\Csrf::field() ?>
                            <button type="submit" class="btn btn-sm p-0 border-0 bg-transparent fs-5 lh-1" title="<?= $done ? 'Зняти позначку' : 'Позначити виконаним' ?>" aria-label="<?= $done ? 'Виконано — зняти позначку' : 'Не виконано — позначити' ?>"><?= $done ? '☑' : '☐' ?></button>
                        </form>
                        <span class="flex-grow-1<?= $done ? ' text-decoration-line-through text-muted' : '' ?>"><?= \App\Core\View::e($item['body']) ?>
                            <?php if ($done && !empty($item['done_by_name'])): ?>
                                <small class="text-muted">— <?= \App\Core\View::e($item['done_by_name']) ?>, <?= \App\Core\View::e(date('d.m.Y H:i', strtotime($item['done_at']))) ?></small>
                            <?php endif; ?>
                        </span>
                        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/checklist/<?= (int)$item['id'] ?>/delete" class="mb-0">
                            <?= \App\Core\Csrf::field() ?>
                            <button type="submit" class="btn btn-sm btn-link text-muted p-0" title="Видалити пункт" aria-label="Видалити пункт">✕</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="text-muted">Пунктів ще немає. Чек-лист — перелік кроків, які треба зробити в межах цієї задачі.</p>
        <?php endif; ?>
        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/checklist">
            <?= \App\Core\Csrf::field() ?>
            <textarea name="items" class="form-control mb-2" rows="2" maxlength="5000" placeholder="Новий пункт. Можна вставити кілька — кожен з нового рядка"></textarea>
            <button class="btn btn-sm btn-outline-primary" type="submit">Додати до чек-листа</button>
        </form>
    </div>
</div>

<div class="card mb-4" id="subtasks">
    <div class="card-header">Підзадачі<?= $stTotal ? ' (' . $stDone . '/' . $stTotal . ' закрито)' : '' ?></div>
    <div class="card-body">
        <?php if ($stTotal > 0): ?>
            <div class="progress mb-3" style="height:8px" role="progressbar" aria-valuenow="<?= $stPct ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar bg-success" style="width:<?= $stPct ?>%"></div>
            </div>
            <table class="table table-sm align-middle">
                <thead><tr><th>#</th><th>Назва</th><th>Статус</th><th>Виконавець</th><th>Термін</th></tr></thead>
                <tbody>
                <?php foreach ($subtasks as $s): ?>
                    <tr<?= (int)$s['is_closed'] === 1 ? ' class="text-muted"' : '' ?>>
                        <td><a href="/tasks/<?= (int)$s['id'] ?>">#<?= (int)$s['id'] ?></a></td>
                        <td<?= (int)$s['is_closed'] === 1 ? ' class="text-decoration-line-through"' : '' ?>><?= \App\Core\View::e($s['title']) ?></td>
                        <td><span class="badge bg-secondary"><?= \App\Core\View::e($s['status_name']) ?></span></td>
                        <td><?= \App\Core\View::e($s['assignee_name'] ?? '—') ?></td>
                        <td><?= \App\Core\View::e($s['due_date'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if (!empty($parentTask)): ?>
            <p class="text-muted small mb-0">Це сама підзадача: вкладених підзадач не буває. Пункти чек-листа можна вести тут, вище.</p>
        <?php else: ?>
            <form method="post" action="/tasks/<?= (int)$task['id'] ?>/subtasks" class="row g-2 align-items-end">
                <?= \App\Core\Csrf::field() ?>
                <div class="col-md-5">
                    <label class="form-label small mb-1">Нова підзадача</label>
                    <input type="text" name="title" class="form-control form-control-sm" maxlength="255" placeholder="Назва підзадачі" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Виконавець</label>
                    <select name="assignee_id" class="form-select form-select-sm user-select">
                        <option value="">— не призначено —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= \App\Core\View::e($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Термін</label>
                    <input type="date" name="due_date" class="form-control form-control-sm">
                </div>
                <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100" type="submit">Додати</button></div>
            </form>
            <p class="text-muted small mt-2 mb-0">Підзадача — окрема задача в тому ж проєкті (з власним виконавцем, статусом і терміном). Батьківську задачу не можна закрити, поки є незакриті підзадачі.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php
$attachmentOwnerType = 'task';
$attachmentOwnerId = (int) $task['id'];
require __DIR__ . '/../attachments/_panel.php';
?>

<div class="card mb-4">
    <div class="card-header">Облік часу</div>
    <div class="card-body">
        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/time" class="row g-2 align-items-end">
    <?= \App\Core\Csrf::field() ?>
            <div class="col-auto">
                <label class="form-label">Годин</label>
                <input type="number" step="0.25" min="0.25" name="hours" class="form-control" required>
            </div>
            <div class="col-auto">
                <label class="form-label">Дата</label>
                <input type="date" name="log_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-auto">
                <label class="form-label">Категорія</label>
                <input type="text" name="category" class="form-control" placeholder="напр. діагностика">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" type="submit">Додати</button>
            </div>
        </form>

        <?php if (!empty($timeLogs)): ?>
            <table class="table table-sm mt-3 mb-0">
                <thead><tr><th>Дата</th><th>Хто</th><th>Годин</th><th>Категорія</th></tr></thead>
                <tbody>
                <?php foreach ($timeLogs as $log): ?>
                    <tr>
                        <td><?= \App\Core\View::e($log['log_date']) ?></td>
                        <td><?= \App\Core\View::e($log['user_name']) ?></td>
                        <td><?= \App\Core\View::e((string)$log['hours']) ?></td>
                        <td class="text-muted"><?= \App\Core\View::e($log['activity_category'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="text-muted small mt-3 mb-0">Ще жодного запису обліку часу для цієї задачі.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">Коментарі</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($comments as $c): ?>
        <li class="list-group-item">
            <strong><?= \App\Core\View::e($c['author_name']) ?></strong>
            <span class="text-muted small"><?= \App\Core\View::e($c['created_at']) ?></span>
            <p class="mb-0"><?= nl2br(\App\Core\View::e($c['body'])) ?></p>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="card-body">
        <form method="post" action="/tasks/<?= (int)$task['id'] ?>/comments">
    <?= \App\Core\Csrf::field() ?>
            <textarea name="body" class="form-control mb-2" rows="2" placeholder="Написати коментар..." required></textarea>
            <button class="btn btn-sm btn-primary" type="submit">Додати коментар</button>
        </form>
    </div>
</div>
