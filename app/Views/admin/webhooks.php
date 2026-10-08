<?php
$e = static fn($v) => \App\Core\View::e($v);
$fmt = static fn(?string $d) => $d ? date('d.m.Y H:i', strtotime($d)) : '—';
?>
<div class="mb-3"><?php require __DIR__ . '/_back.php'; ?></div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h3 class="mb-0">Вебхуки</h3>
    <?php if ($available): ?><a href="/admin/webhooks/new" class="btn btn-primary">+ Новий вебхук</a><?php endif; ?>
</div>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>

<?php if (!$available): ?>
    <div class="alert alert-warning"><strong>Таблиці вебхуків ще не створено.</strong> Запустіть на сервері <code>sudo bash update.sh</code> (крок «Перевірка таблиць REST API і вебхуків»).</div>
<?php else: ?>
    <p class="text-muted">Вебхук — це адреса, на яку система сама надсилає <strong>подію</strong> (новий тікет, зміна статусу, коментар тощо) у форматі JSON,
        підписану секретом (HMAC-SHA256). Так зовнішні системи — месенджери, моніторинг, інші ІТ-системи — дізнаються про зміни без постійного опитування API.</p>

    <?php
    $workerFresh = $lastRun && strtotime($lastRun) > time() - 600;
    if (!$immediate && !$workerFresh): ?>
        <div class="alert alert-warning">
            <strong>Потрібен cron.</strong> Цей сервер відправляє події лише з планувальника (PHP працює без FPM): додайте щохвилини
            <code>php <?= $e(dirname(__DIR__, 3)) ?>/bin/deliver-webhooks.php</code>. Без цього події накопичуватимуться в черзі й не доходитимуть.
        </div>
    <?php elseif ($immediate && !$workerFresh): ?>
        <div class="alert alert-info py-2 small">Нові події відправляються одразу після відповіді користувачу. Для <strong>повторів</strong> невдалих доставок додайте в cron щохвилини:
            <code>php <?= $e(dirname(__DIR__, 3)) ?>/bin/deliver-webhooks.php</code>
        </div>
    <?php endif; ?>
    <?php if ($due > 0): ?>
        <div class="alert alert-secondary py-2 small">У черзі очікують відправки: <strong><?= (int) $due ?></strong>. Планувальник востаннє працював: <?= $lastRun ? $e($fmt($lastRun)) : 'ще не запускався' ?>.</div>
    <?php endif; ?>

    <?php if (!$endpoints): ?>
        <div class="alert alert-light border">Вебхуків ще немає. Натисніть «+ Новий вебхук».</div>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table table-bordered bg-white align-middle">
            <thead><tr><th>Назва</th><th>Адреса</th><th>Події</th><th>Стан</th><th>Доставки</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($endpoints as $ep): $events = \App\Models\WebhookEndpoint::decodeEvents($ep['events']); ?>
                <tr class="<?= $ep['is_active'] ? '' : 'table-light text-muted' ?>">
                    <td><a href="/admin/webhooks/<?= (int) $ep['id'] ?>" class="fw-semibold"><?= $e($ep['name']) ?></a></td>
                    <td class="small text-break"><code><?= $e($ep['url']) ?></code></td>
                    <td class="small"><?= $events === ['*'] ? 'усі події' : count($events) . ' з ' . count(\App\Models\WebhookEndpoint::EVENTS) ?></td>
                    <td>
                        <?php if ($ep['is_active']): ?><span class="badge bg-success">активний</span>
                        <?php else: ?><span class="badge bg-secondary">вимкнений</span><div class="small mt-1"><?= $e($ep['disabled_reason'] ?? '') ?></div><?php endif; ?>
                        <?php if ((int) $ep['consecutive_failures'] > 0 && $ep['is_active']): ?><div class="small text-danger">невдач поспіль: <?= (int) $ep['consecutive_failures'] ?></div><?php endif; ?>
                    </td>
                    <td class="small">
                        востаннє успішно: <?= $e($fmt($ep['last_success_at'])) ?><br>
                        очікують: <?= (int) $ep['pending_count'] ?> · остаточно невдалі: <?= (int) $ep['failed_count'] ?>
                        · <a href="/admin/webhooks/<?= (int) $ep['id'] ?>/deliveries">журнал</a>
                    </td>
                    <td class="text-nowrap">
                        <form method="post" action="/admin/webhooks/<?= (int) $ep['id'] ?>/toggle" class="d-inline">
                            <?= \App\Core\Csrf::field() ?>
                            <button type="submit" class="btn btn-sm <?= $ep['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>"><?= $ep['is_active'] ? 'Вимкнути' : 'Увімкнути' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
