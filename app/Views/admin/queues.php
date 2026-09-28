<div class="mb-3">
    <a href="/admin" class="text-decoration-none">&larr; Адмін-панель</a>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">Черги тікетів</h3>
    <a href="/admin/queues/create" class="btn btn-primary">+ Нова черга</a>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= \App\Core\View::e($success) ?></div>
<?php endif; ?>

<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr><th>Назва</th><th>Опис</th><th>Тікетів у черзі</th><th>SLA: перша відповідь / вирішення (хв)</th></tr>
    </thead>
    <tbody>
    <?php if (empty($queues)): ?>
        <tr><td colspan="4" class="text-center text-muted">Черг ще немає</td></tr>
    <?php else: foreach ($queues as $q): ?>
        <tr>
            <td><?= \App\Core\View::e($q['name']) ?></td>
            <td><?= \App\Core\View::e($q['description'] ?? '—') ?></td>
            <td><?= (int)$q['tickets_count'] ?></td>
            <td>
                <form method="post" action="/admin/queues/<?= (int)$q['id'] ?>/sla" class="d-flex gap-1 align-items-center">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="number" name="first_response_minutes" class="form-control form-control-sm sla-minutes-input"
                           min="1" value="<?= $q['first_response_minutes'] !== null ? (int)$q['first_response_minutes'] : '' ?>" required>
                    <span class="text-muted">/</span>
                    <input type="number" name="resolution_minutes" class="form-control form-control-sm sla-minutes-input"
                           min="1" value="<?= $q['resolution_minutes'] !== null ? (int)$q['resolution_minutes'] : '' ?>" required>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Зберегти</button>
                </form>
                <?php if ($q['first_response_minutes'] === null): ?>
                    <div class="form-text text-warning">Норматив ще не налаштовано — прострочення для цієї черги не рахується.</div>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
