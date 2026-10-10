<div class="mb-3">
    <?php if (!empty($unitMode)) { require __DIR__ . '/../unit/_nav.php'; } else { require __DIR__ . '/_back.php'; } ?>
</div>

<h3 class="mb-4">Проєкти</h3>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= \App\Core\View::e($success) ?></div>
<?php endif; ?>

<div class="alert alert-warning small">
    <?php if (!empty($unitMode)): ?>Ви бачите проєкти свого підрозділу. Видалити можна проєкт, який ви створили самі, або такий, у якому всі причетні люди (автор, відповідальний, учасники, виконавці задач, ті, хто вів облік часу) — з вашого підрозділу; решту видаляє адміністратор системи.<br><?php endif; ?>
    Видалення проєкту незворотне і видаляє <strong>всі</strong> повʼязані задачі, коментарі,
    вкладення та облік часу. Для підтвердження потрібно ввести точну назву проєкту.
</div>

<?php $base = $basePath ?? '/admin/projects'; ?>
<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr><th>Назва</th><th>Статус</th><th>Відкритих задач</th><th>Створив</th><th class="col-min-320">Дія</th></tr>
    </thead>
    <tbody>
    <?php
    $statusLabels = ['active' => 'Активний', 'archived' => 'Архівний', 'closed' => 'Закритий'];
    $statusColors = ['active' => 'success', 'archived' => 'secondary', 'closed' => 'dark'];
    ?>
    <?php foreach ($projects as $p): ?>
        <tr>
            <td>
                <a href="/projects/<?= (int)$p['id'] ?>"><?= \App\Core\View::e($p['name']) ?></a>
                <?php if (!empty($p['parent_id'])): ?>
                    <div class="small text-muted">↳ підпроєкт: <?= \App\Core\View::e($p['parent_name']) ?></div>
                <?php endif; ?>
                <?php if (in_array((int)$p['id'], $overdueProjectIds, true)): ?>
                    <span class="badge bg-danger">⚠️ Прострочено етап</span>
                <?php endif; ?>
            </td>
            <td>
                <form method="post" action="<?= $base ?>/<?= (int)$p['id'] ?>/status" class="d-flex gap-1">
                    <?= \App\Core\Csrf::field() ?>
                    <select name="status" class="form-select form-select-sm bg-<?= $statusColors[$p['status']] ?? 'secondary' ?> text-white auto-submit-select">
                        <?php foreach ($statusLabels as $code => $label): ?>
                            <option value="<?= $code ?>" <?= $p['status'] === $code ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </td>
            <td><?= (int)$p['open_tasks_count'] ?></td>
            <td><?= \App\Core\View::e($p['created_by_name']) ?></td>
            <td>
                <?php if (!empty($unitMode) && empty($p['can_delete'])): ?>
                    <span class="small text-muted">Є учасники з інших підрозділів, і створено не вами — видалити може лише адміністратор системи.</span>
                <?php else: ?>
                <form method="post" action="<?= $base ?>/<?= (int)$p['id'] ?>/delete"
                      class="d-flex gap-1"
                      data-confirm="Остаточно видалити проєкт «<?= \App\Core\View::e($p['name']) ?>» та всі його задачі?">
    <?= \App\Core\Csrf::field() ?>
                    <input type="text" name="confirm_name" class="form-control form-control-sm"
                           placeholder="Введіть назву проєкту для підтвердження" required>
                    <button type="submit" class="btn btn-sm btn-danger text-nowrap">Видалити</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
