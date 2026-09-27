<?php
$statusLabels = ['new' => 'Новий', 'in_progress' => 'В роботі', 'waiting_customer' => 'Очікує відповіді', 'resolved' => 'Вирішено', 'closed' => 'Закрито'];
$statusColors = ['new' => 'secondary', 'in_progress' => 'primary', 'waiting_customer' => 'warning text-dark', 'resolved' => 'success', 'closed' => 'dark'];
$isFinished = in_array($ticket['status'], ['resolved', 'closed'], true);
?>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="mb-3">
            <a href="/support" class="text-decoration-none">&larr; Подати нове звернення</a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= \App\Core\View::e($success) ?></div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-body">
                <h4><?= \App\Core\View::e($ticket['subject']) ?> <small class="text-muted">#<?= (int)$ticket['id'] ?></small></h4>
                <span class="badge bg-<?= $statusColors[$ticket['status']] ?? 'secondary' ?>"><?= \App\Core\View::e($statusLabels[$ticket['status']] ?? $ticket['status']) ?></span>
                <table class="table table-sm w-auto mt-3">
                    <tr><th>Черга</th><td><?= \App\Core\View::e($ticket['queue_name']) ?></td></tr>
                    <tr><th>Оператор</th><td><?= \App\Core\View::e($ticket['operator_name'] ?? '— ще не призначено —') ?></td></tr>
                    <tr><th>Створено</th><td><?= \App\Core\View::e($ticket['created_at']) ?></td></tr>
                </table>
                <?php if (!empty($ticket['description'])): ?>
                    <p class="mb-0"><?= nl2br(\App\Core\View::e($ticket['description'])) ?></p>
                <?php endif; ?>

                <?php if ($sla['has_policy']): ?>
                    <?php if ($sla['response_overdue']): ?>
                        <div class="alert alert-danger py-2 mt-3 mb-0">Норматив часу першої відповіді (<?= \App\Core\View::e($sla['response_due_at']) ?>) прострочено — вибачте за затримку, оператор скоро відповість.</div>
                    <?php elseif (empty($ticket['first_response_at'])): ?>
                        <div class="alert alert-light border py-2 mt-3 mb-0">Очікувана перша відповідь — до <?= \App\Core\View::e($sla['response_due_at']) ?>.</div>
                    <?php endif; ?>
                    <?php if ($sla['resolution_overdue']): ?>
                        <div class="alert alert-danger py-2 mt-2 mb-0">Норматив часу вирішення (<?= \App\Core\View::e($sla['resolution_due_at']) ?>) прострочено.</div>
                    <?php elseif (!$isFinished): ?>
                        <div class="alert alert-light border py-2 mt-2 mb-0">Очікуваний час вирішення — до <?= \App\Core\View::e($sla['resolution_due_at']) ?>.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Листування</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($comments as $c): ?>
                <li class="list-group-item">
                    <strong><?= $c['author_type'] === 'operator' ? \App\Core\View::e($c['author_name'] ?? 'Оператор') : \App\Core\View::e($ticket['requester_name']) ?></strong>
                    <span class="text-muted small"><?= \App\Core\View::e($c['created_at']) ?></span>
                    <p class="mb-0"><?= nl2br(\App\Core\View::e($c['body'])) ?></p>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="card-body">
                <form method="post" action="/support/track/<?= \App\Core\View::e($ticket['access_token']) ?>/comment">
                    <?= \App\Core\Csrf::field() ?>
                    <textarea name="body" class="form-control mb-2" rows="2" placeholder="Написати повідомлення..." required></textarea>
                    <button class="btn btn-sm btn-primary" type="submit">Надіслати</button>
                </form>
            </div>
        </div>

        <?php if ($isFinished): ?>
            <div class="card">
                <div class="card-body">
                    <?php if ($ticket['csat_score'] !== null): ?>
                        <p class="mb-0">Дякуємо, ви вже оцінили це звернення: <strong><?= (int)$ticket['csat_score'] ?> з 5</strong>.</p>
                    <?php else: ?>
                        <p class="mb-2">Наскільки ви задоволені вирішенням звернення?</p>
                        <form method="post" action="/support/track/<?= \App\Core\View::e($ticket['access_token']) ?>/rate" class="d-flex gap-2">
                            <?= \App\Core\Csrf::field() ?>
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <button type="submit" name="csat_score" value="<?= $i ?>" class="btn btn-outline-primary"><?= $i ?></button>
                            <?php endfor; ?>
                        </form>
                        <div class="form-text mt-1">1 — незадовільно, 5 — відмінно</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <p class="text-muted small mt-3">Збережіть це посилання, щоб повернутись сюди пізніше — без облікового запису повторно знайти звернення інакше неможливо.</p>
    </div>
</div>
