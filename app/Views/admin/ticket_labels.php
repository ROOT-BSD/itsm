<?php $e = static fn($v) => \App\Core\View::e((string) $v); $colors = ['secondary' => 'сірий', 'primary' => 'синій', 'success' => 'зелений', 'danger' => 'червоний', 'warning' => 'жовтий', 'info' => 'блакитний', 'dark' => 'темний']; ?>
<div class="mb-3"><?php require __DIR__ . '/_back.php'; ?></div>
<h3 class="mb-1">Мітки тікетів</h3>
<p class="text-muted">Мітки призначають вручну на сторінці тікета (оператор, керівник ІТ, адміністратор) або автоматично правилами черги. За міткою можна відфільтрувати список тікетів.</p>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>
<table class="table table-bordered bg-white table-sm align-middle form-card-md">
    <thead><tr><th>Мітка</th><th>Колір</th><th>Тікетів</th><th></th></tr></thead>
    <tbody>
    <?php if (!$labels): ?><tr><td colspan="4" class="text-center text-muted">Міток ще немає</td></tr><?php endif; ?>
    <?php foreach ($labels as $l): ?>
        <tr>
            <td colspan="2">
                <form method="post" action="/admin/ticket-labels" class="d-flex gap-2 align-items-center"><?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                    <span class="badge text-bg-<?= $e($l['color']) ?>">&nbsp;</span>
                    <input type="text" name="name" maxlength="50" class="form-control form-control-sm" value="<?= $e($l['name']) ?>" required>
                    <select name="color" class="form-select form-select-sm w-auto"><?php foreach ($colors as $k => $n): ?><option value="<?= $k ?>" <?= $l['color'] === $k ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select>
                    <button class="btn btn-sm btn-outline-primary" type="submit">Зберегти</button>
                </form>
            </td>
            <td><?= (int) $l['used'] ?></td>
            <td><form method="post" action="/admin/ticket-labels/<?= (int) $l['id'] ?>/delete" data-confirm="Видалити мітку «<?= $e($l['name']) ?>»? Її буде знято з усіх тікетів."><?= \App\Core\Csrf::field() ?>
                <button class="btn btn-sm btn-outline-danger" type="submit">Видалити</button></form></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<div class="card shadow-sm form-card-md"><div class="card-header">Нова мітка</div><div class="card-body">
    <form method="post" action="/admin/ticket-labels" class="d-flex gap-2"><?= \App\Core\Csrf::field() ?>
        <input type="text" name="name" maxlength="50" class="form-control" placeholder="Назва" required>
        <select name="color" class="form-select w-auto"><?php foreach ($colors as $k => $n): ?><option value="<?= $k ?>"><?= $n ?></option><?php endforeach; ?></select>
        <button class="btn btn-primary" type="submit">Додати</button>
    </form>
</div></div>
