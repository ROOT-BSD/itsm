<div class="mb-3">
    <a href="/tickets" class="text-decoration-none">&larr; Тікети</a>
</div>

<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($_GET['error']) ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h3><?= \App\Core\View::e($ticket['subject']) ?> <small class="text-muted">#<?= (int)$ticket['id'] ?></small></h3>
        <p><?= nl2br(\App\Core\View::e($ticket['description'])) ?></p>
        <table class="table table-sm w-auto">
            <tr><th>Черга</th><td><?= \App\Core\View::e($ticket['queue_name']) ?></td></tr>
            <tr><th>Заявник</th><td><?= \App\Core\View::e($ticket['requester_name']) ?> (<?= \App\Core\View::e($ticket['requester_email']) ?>)</td></tr>
            <tr><th>Створено</th><td><?= \App\Core\View::e($ticket['created_at']) ?></td></tr>
        </table>

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
