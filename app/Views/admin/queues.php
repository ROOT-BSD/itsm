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
        <tr><th>Назва</th><th>Опис</th><th>Тікетів у черзі</th></tr>
    </thead>
    <tbody>
    <?php if (empty($queues)): ?>
        <tr><td colspan="3" class="text-center text-muted">Черг ще немає</td></tr>
    <?php else: foreach ($queues as $q): ?>
        <tr>
            <td><?= \App\Core\View::e($q['name']) ?></td>
            <td><?= \App\Core\View::e($q['description'] ?? '—') ?></td>
            <td><?= (int)$q['tickets_count'] ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
