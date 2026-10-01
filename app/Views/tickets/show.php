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
        <p><?= nl2br(\App\Core\View::e($ticket['description'])) ?></p>
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

        <?php if (\App\Core\Auth::hasRole(['admin', 'it_manager', 'support_operator'])): ?>
        <form method="post" action="/tickets/<?= (int)$ticket['id'] ?>/operator" class="d-flex gap-2 align-items-center mb-2">
    <?= \App\Core\Csrf::field() ?>
            <label class="form-label mb-0">Оператор (виконавець тікета):</label>
            <select name="operator_id" class="form-select w-auto">
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
                $statusLabels = [
                    'new' => 'Новий', 'in_progress' => 'В роботі', 'waiting_customer' => 'Очікує відповіді заявника',
                    'resolved' => 'Вирішено', 'closed' => 'Закрито',
                ];
                foreach ($statusLabels as $code => $label): ?>
                    <option value="<?= $code ?>" <?= $ticket['status'] === $code ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary" type="submit">Оновити</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">Листування</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($comments as $c): ?>
        <li class="list-group-item">
            <strong><?= \App\Core\View::e($c['author_name'] ?? $ticket['requester_name']) ?></strong>
            <span class="badge bg-light text-dark border"><?= $c['author_type'] === 'operator' ? 'оператор' : 'заявник' ?></span>
            <span class="text-muted small"><?= \App\Core\View::e($c['created_at']) ?></span>
            <p class="mb-0"><?= nl2br(\App\Core\View::e($c['body'])) ?></p>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="card-body">
        <form method="post" action="/tickets/<?= (int)$ticket['id'] ?>/comments">
    <?= \App\Core\Csrf::field() ?>
            <textarea name="body" class="form-control mb-2" rows="2" placeholder="Написати відповідь..." required></textarea>
            <button class="btn btn-sm btn-primary" type="submit">Надіслати</button>
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
