<div class="mb-3">
    <a href="/admin" class="text-decoration-none">&larr; Адмін-панель</a>
</div>

<h3 class="mb-4">Проєкти</h3>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= \App\Core\View::e($success) ?></div>
<?php endif; ?>

<div class="alert alert-warning small">
    Видалення проєкту незворотне і видаляє <strong>всі</strong> повʼязані задачі, коментарі,
    вкладення та облік часу. Для підтвердження потрібно ввести точну назву проєкту.
</div>

<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr><th>Назва</th><th>Статус</th><th>Видимість</th><th>Відкритих задач</th><th>Створив</th><th class="col-min-320">Дія</th></tr>
    </thead>
    <tbody>
    <?php
    $visibilityLabels = ['public' => 'Публічний', 'private' => 'Приватний', 'restricted' => 'Обмежений доступ'];
    $statusLabels = ['active' => 'Активний', 'archived' => 'Архівний', 'closed' => 'Закритий'];
    $statusColors = ['active' => 'success', 'archived' => 'secondary', 'closed' => 'dark'];
    ?>
    <?php foreach ($projects as $p): ?>
        <tr>
            <td><a href="/projects/<?= (int)$p['id'] ?>"><?= \App\Core\View::e($p['name']) ?></a></td>
            <td>
                <form method="post" action="/admin/projects/<?= (int)$p['id'] ?>/status" class="d-flex gap-1">
                    <?= \App\Core\Csrf::field() ?>
                    <select name="status" class="form-select form-select-sm bg-<?= $statusColors[$p['status']] ?? 'secondary' ?> text-white" onchange="this.form.requestSubmit()">
                        <?php foreach ($statusLabels as $code => $label): ?>
                            <option value="<?= $code ?>" <?= $p['status'] === $code ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </td>
            <td>
                <form method="post" action="/admin/projects/<?= (int)$p['id'] ?>/visibility" class="d-flex gap-1">
                    <?= \App\Core\Csrf::field() ?>
                    <select name="visibility" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
                        <?php foreach ($visibilityLabels as $code => $label): ?>
                            <option value="<?= $code ?>" <?= $p['visibility'] === $code ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </td>
            <td><?= (int)$p['open_tasks_count'] ?></td>
            <td><?= \App\Core\View::e($p['created_by_name']) ?></td>
            <td>
                <form method="post" action="/admin/projects/<?= (int)$p['id'] ?>/delete"
                      class="d-flex gap-1"
                      onsubmit="return confirm('Остаточно видалити проєкт «<?= \App\Core\View::e($p['name']) ?>» та всі його задачі?');">
    <?= \App\Core\Csrf::field() ?>
                    <input type="text" name="confirm_name" class="form-control form-control-sm"
                           placeholder="Введіть назву проєкту для підтвердження" required>
                    <button type="submit" class="btn btn-sm btn-danger text-nowrap">Видалити</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
