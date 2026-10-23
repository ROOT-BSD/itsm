<?php
$e = static fn($v) => \App\Core\View::e($v);
?>
<div class="mb-3"><a href="/reports/schedules" class="text-decoration-none">&larr; Звіти за розкладом</a></div>
<h3 class="mb-3">Редагування розкладу</h3>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if ($isAdmin && (int) $sch['user_id'] !== (int) \App\Core\Auth::id()): ?>
    <p class="text-muted">Власник розкладу: <strong><?= $e($sch['owner_name']) ?></strong> (<?= $e($sch['owner_email']) ?>). Звіт будується з його правами доступу.</p>
<?php endif; ?>
<div class="card shadow-sm mb-4"><div class="card-body">
    <?php $action = '/reports/schedules/' . (int) $sch['id']; $submitLabel = 'Зберегти зміни'; include __DIR__ . '/_schedule_form.php'; ?>
</div></div>
