<div class="mb-3">
    <a href="/tickets" class="text-decoration-none">&larr; Тікети</a>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= \App\Core\View::e($success) ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h3><?= \App\Core\View::e($ticket['subject']) ?> <small class="text-muted">#<?= (int)$ticket['id'] ?></small></h3>
        <p><?= \App\Core\View::richText($ticket['description'], $attachments ?? [], fn($id) => '/attachments/' . $id) ?></p>
        <table class="table table-sm w-auto">
            <tr><th>Черга</th><td><?= \App\Core\View::e($ticket['queue_name']) ?></td></tr>
            <?php if (!empty($ticket['project_id'])): ?>
            <tr><th>Проєкт</th><td><a href="/projects/<?= (int)$ticket['project_id'] ?>"><?= \App\Core\View::e($ticket['project_name']) ?></a></td></tr>
            <?php endif; ?>
            <tr><th>Заявник</th><td><?= \App\Core\View::e($ticket['requester_name']) ?> (<?= \App\Core\View::e($ticket['requester_email']) ?>)</td></tr>
            <tr><th>Створено</th><td><?= \App\Core\View::e($ticket['created_at']) ?></td></tr>
        </table>

        <?php if ($sla['has_policy']): ?>
            <?php if ($sla['response_overdue']): ?>
                <div class="alert alert-danger py-2 mb-2">⚠️ Прострочено норматив першої відповіді (мала бути до <?= \App\Core\View::e($sla['response_due_at']) ?>)</div>
            <?php elseif (empty($ticket['first_response_at'])): ?>
                <div class="alert alert-warning py-2 mb-2">Норматив першої відповіді — до <?= \App\Core\View::e($sla['response_due_at']) ?></div>
            <?php endif; ?>
            <?php if ($sla['resolution_overdue']): ?>
                <div class="alert alert-danger py-2 mb-2">⚠️ Прострочено норматив вирішення (мало бути до <?= \App\Core\View::e($sla['resolution_due_at']) ?>)</div>
            <?php elseif (!in_array($ticket['status'], ['resolved', 'closed'], true)): ?>
                <div class="alert alert-info py-2 mb-2">Норматив вирішення — до <?= \App\Core\View::e($sla['resolution_due_at']) ?></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (\App\Core\Access::canAssignTicketOperator(\App\Core\Auth::role())): ?>
        <form method="post" action="/tickets/<?= (int)$ticket['id'] ?>/operator" class="d-flex gap-2 align-items-center mb-2">
    <?= \App\Core\Csrf::field() ?>
            <label class="form-label mb-0">Оператор (виконавець тікета):</label>
            <select name="operator_id" class="form-select w-auto user-select">
                <option value="">— не призначено —</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)($ticket['assigned_operator_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= \App\Core\View::e($u['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary" type="submit">Призначити</button>
        </form>
        <?php else: ?>
        <p class="text-muted small mb-2">
            Оператор: <?= \App\Core\View::e($ticket['operator_name'] ?? 'не призначено') ?>
        </p>
        <?php endif; ?>

        <form method="post" action="/tickets/<?= (int)$ticket['id'] ?>/status" class="d-flex gap-2 align-items-center">
    <?= \App\Core\Csrf::field() ?>
            <label class="form-label mb-0">Статус:</label>
            <select name="status" class="form-select w-auto">
                <?php
                $statusLabels = \App\Models\Ticket::STATUS_LABELS;
                foreach ($statusLabels as $code => $label): ?>
                    <option value="<?= $code ?>" <?= $ticket['status'] === $code ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($links['duplicates']) && \App\Core\Access::canAssignTicketOperator(\App\Core\Auth::role())): ?>
                <div class="form-check mb-0 ms-2">
                    <input class="form-check-input" type="checkbox" name="with_duplicates" id="with_duplicates" value="1">
                    <label class="form-check-label small" for="with_duplicates">і для дублів (<?= count($links['duplicates']) ?>)</label>
                </div>
            <?php endif; ?>
            <?php if (!empty($canLogTime)): ?>
                <input type="number" name="spent_minutes" min="0" max="1440" step="1" class="form-control form-control-sm w-auto" style="width:7rem" placeholder="хв" title="Витрачено часу, хв — записується при встановленні статусу «Вирішено» або «Закрито»" aria-label="Витрачено часу, хв">
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-primary" type="submit">Оновити</button>
        </form>
        <?php if (!empty($canLogTime)): ?><div class="form-text">Поле «хв» — витрачений час; записується, коли статус змінюється на «Вирішено» або «Закрито».</div><?php endif; ?>
    </div>
</div>

<?php
$linksReady = \App\Models\TicketLink::ready();
$canLink = $linksReady && \App\Core\Access::canAssignTicketOperator(\App\Core\Auth::role());
$hasLinks = !empty($links['original']) || !empty($links['duplicates']) || !empty($links['related']) || !empty($links['hidden']);
$renderLink = function (array $row, ?string $note = null) use ($ticket, $canLink): void { ?>
    <li class="list-group-item d-flex align-items-center gap-2">
        <a href="/tickets/<?= (int)$row['id'] ?>">#<?= (int)$row['id'] ?> <?= \App\Core\View::e($row['subject']) ?></a>
        <span class="badge bg-secondary"><?= \App\Core\View::e(\App\Models\Ticket::statusLabel((string)$row['status'])) ?></span>
        <?php if ($note): ?><span class="text-muted small"><?= \App\Core\View::e($note) ?></span><?php endif; ?>
        <?php if ($canLink): ?>
            <form method="post" action="/tickets/<?= (int)$ticket['id'] ?>/links/<?= (int)$row['link_id'] ?>/delete" class="ms-auto" data-confirm="Прибрати зв'язок із тікетом №<?= (int)$row['id'] ?>?">
                <?= \App\Core\Csrf::field() ?>
                <button class="btn btn-sm btn-outline-danger" type="submit">Прибрати</button>
            </form>
        <?php endif; ?>
    </li>
<?php }; ?>
<?php if ($linksReady && ($hasLinks || $canLink)): ?>
<div class="card mt-3">
    <div class="card-header">Пов'язані та дубльовані тікети</div>
    <?php if ($hasLinks): ?>
    <ul class="list-group list-group-flush">
        <?php if (!empty($links['original'])): ?><?php $renderLink($links['original'], 'це дубль цього тікета-оригіналу'); ?><?php endif; ?>
        <?php foreach ($links['duplicates'] as $row): ?><?php $renderLink($row, 'дубль'); ?><?php endforeach; ?>
        <?php foreach ($links['related'] as $row): ?><?php $renderLink($row, 'пов\'язаний'); ?><?php endforeach; ?>
        <?php if (!empty($links['hidden'])): ?>
            <li class="list-group-item text-muted small">Ще пов'язаних тікетів, до яких немає доступу: <?= (int)$links['hidden'] ?></li>
        <?php endif; ?>
    </ul>
    <?php endif; ?>
    <?php if ($canLink): ?>
    <div class="card-body">
        <form method="post" action="/tickets/<?= (int)$ticket['id'] ?>/links" class="row g-2 align-items-end">
            <?= \App\Core\Csrf::field() ?>
            <div class="col-md-5">
                <label class="form-label small mb-1">Зв'язок</label>
                <select name="kind" class="form-select form-select-sm">
                    <option value="related">пов'язаний з тікетом</option>
                    <option value="duplicate_of">цей тікет — дубль тікета</option>
                    <option value="has_duplicate">тікет — дубль цього</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">№ тікета</label>
                <input type="number" name="other_id" min="1" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100" type="submit">Додати</button></div>
        </form>
        <div class="form-text">Масовий інцидент: відкрийте тікет-оригінал, додайте дублі й закрийте оригінал із галочкою «і для дублів».</div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$attachmentOwnerType = 'ticket';
$attachmentOwnerId = (int) $ticket['id'];
require __DIR__ . '/../attachments/_panel.php';
?>

<?php if (!empty($canLogTime) || !empty($timeLogs)): ?>
<?php $timeTotal = array_sum(array_map(fn($l) => (float) $l['hours'], $timeLogs)); ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between">
        <span>Облік часу</span>
        <span class="text-muted small">Разом: <?= \App\Core\View::e(\App\Services\TicketWorkReport::duration(round($timeTotal * 60))) ?> (<?= number_format($timeTotal, 2, ',', ' ') ?> год.)</span>
    </div>
    <?php if ($timeLogs): ?>
    <ul class="list-group list-group-flush">
        <?php foreach ($timeLogs as $l): ?>
            <li class="list-group-item d-flex flex-wrap gap-2 align-items-center small">
                <span class="text-nowrap"><?= \App\Core\View::e($l['log_date']) ?></span>
                <strong><?= \App\Core\View::e(\App\Services\TicketWorkReport::duration(round((float) $l['hours'] * 60))) ?></strong>
                <span><?= \App\Core\View::e($l['user_name']) ?></span>
                <?php if (!empty($l['activity_category'])): ?><span class="badge text-bg-light"><?= \App\Core\View::e($l['activity_category']) ?></span><?php endif; ?>
                <span class="text-muted"><?= \App\Core\View::e($l['comment'] ?? '') ?></span>
                <?php if (!empty($canLogTime) && ((int) $l['user_id'] === (int) \App\Core\Auth::id() || \App\Core\Auth::hasRole(['admin']))): ?>
                <form method="post" action="/tickets/<?= (int)$ticket['id'] ?>/time/<?= (int)$l['id'] ?>/delete" class="ms-auto" data-confirm="Видалити запис часу?">
                    <?= \App\Core\Csrf::field() ?>
                    <button class="btn btn-sm btn-outline-danger py-0" type="submit">Видалити</button>
                </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if (!empty($canLogTime)): ?>
    <div class="card-body">
        <form method="post" action="/tickets/<?= (int)$ticket['id'] ?>/time" class="row g-2 align-items-end">
            <?= \App\Core\Csrf::field() ?>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Годин</label>
                <input type="number" name="hours" step="0.25" min="0.25" max="24" class="form-control form-control-sm" required>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">Дата</label>
                <input type="date" name="log_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Категорія</label>
                <input type="text" name="activity_category" maxlength="100" list="ticket-time-cats" class="form-control form-control-sm" placeholder="напр. діагностика">
                <datalist id="ticket-time-cats"><?php foreach ($timeCategories as $c): ?><option value="<?= \App\Core\View::e($c) ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Коментар</label>
                <input type="text" name="comment" maxlength="255" class="form-control form-control-sm">
            </div>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary" type="submit">Записати час</button></div>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">Листування</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($comments as $c): ?>
        <li class="list-group-item">
            <strong><?= \App\Core\View::e($c['author_name'] ?? $ticket['requester_name']) ?></strong>
            <span class="badge bg-light text-dark border"><?= $c['author_type'] === 'operator' ? 'оператор' : 'заявник' ?></span>
            <span class="text-muted small"><?= \App\Core\View::e($c['created_at']) ?></span>
            <p class="mb-0"><?= \App\Core\View::richText($c['body'], $attachments ?? [], fn($id) => '/attachments/' . $id) ?></p>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="card-body">
        <form method="post" action="/tickets/<?= (int)$ticket['id'] ?>/comments">
    <?= \App\Core\Csrf::field() ?>
            <textarea name="body" class="form-control mb-2" rows="2" placeholder="Написати відповідь..." required></textarea>
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <button class="btn btn-sm btn-primary" type="submit">Надіслати</button>
                <?php if (!empty($canLogTime)): ?>
                <label class="d-flex gap-2 align-items-center small mb-0">Витрачено, хв
                    <input type="number" name="spent_minutes" value="5" min="0" max="1440" step="1" class="form-control form-control-sm" style="width:6rem">
                </label>
                <span class="text-muted small">0 — не записувати час</span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php $isFinished = in_array($ticket['status'], ['resolved', 'closed'], true); ?>
<?php if ($isFinished && (int)($ticket['requester_user_id'] ?? 0) === \App\Core\Auth::id()): ?>
    <div class="card mt-3">
        <div class="card-body">
            <?php if ($ticket['csat_score'] !== null): ?>
                <p class="mb-0">Дякуємо, ви вже оцінили це звернення: <strong><?= (int)$ticket['csat_score'] ?> з 5</strong>.</p>
            <?php else: ?>
                <p class="mb-2">Наскільки ви задоволені вирішенням звернення?</p>
                <form method="post" action="/tickets/<?= (int)$ticket['id'] ?>/csat" class="d-flex gap-2">
                    <?= \App\Core\Csrf::field() ?>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <button type="submit" name="csat_score" value="<?= $i ?>" class="btn btn-outline-primary"><?= $i ?></button>
                    <?php endfor; ?>
                </form>
                <div class="form-text mt-1">1 — незадовільно, 5 — відмінно</div>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($ticket['csat_score'] !== null): ?>
    <div class="card mt-3">
        <div class="card-body text-muted">
            Оцінка заявника: <strong><?= (int)$ticket['csat_score'] ?> з 5</strong>
        </div>
    </div>
<?php endif; ?>
