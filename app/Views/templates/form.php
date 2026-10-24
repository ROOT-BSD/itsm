<?php
use App\Core\View;
$isNew = empty($tpl['id']);
$isProject = $tpl['kind'] === 'project';
$readOnly = !empty($readOnly);
// Для нового шаблону — порожні рядки (3 для проєкту, 1 для задачі); для наявного — наявні плюс 3 порожні для доповнення.
$blank = ['title' => '', 'body' => '', 'type_id' => null, 'priority' => 'normal', 'assignee_role' => '', 'start_offset' => '', 'due_offset' => '', 'checklist' => '', 'subtasks' => ''];
$rows = $items;
if (!$readOnly) {
    $extra = $isProject ? 3 : (empty($rows) ? 1 : 0);
    for ($i = 0; $i < $extra; $i++) { $rows[] = $blank; }
}
$prioLabels = ['low' => 'Низький', 'normal' => 'Звичайний', 'high' => 'Високий', 'critical' => 'Критичний'];
$dis = $readOnly ? ' disabled' : '';
?>
<div class="mb-3"><a href="/templates" class="text-decoration-none">&larr; Усі шаблони</a></div>
<h3 class="mb-3"><?= $isNew ? 'Новий ' : '' ?><?= $isProject ? 'шаблон проєкту' : 'шаблон задачі' ?><?= $isNew ? '' : ': ' . View::e($tpl['name']) ?></h3>
<?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= View::e($_GET['error']) ?></div><?php endif; ?>
<?php if (!empty($_GET['success'])): ?><div class="alert alert-success"><?= View::e($_GET['success']) ?></div><?php endif; ?>

<form method="post" action="<?= $isNew ? '/templates' : '/templates/' . (int)$tpl['id'] ?>">
    <?= \App\Core\Csrf::field() ?>
    <input type="hidden" name="kind" value="<?= View::e($tpl['kind']) ?>">
    <div class="card mb-3"><div class="card-body">
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label">Назва шаблону</label>
                <input type="text" name="name" class="form-control" maxlength="150" required value="<?= View::e($tpl['name']) ?>"<?= $dis ?>>
            </div>
            <div class="col-md-7">
                <label class="form-label">Короткий опис (коли застосовувати)</label>
                <input type="text" name="description" class="form-control" maxlength="500" value="<?= View::e($tpl['description'] ?? '') ?>"<?= $dis ?>>
            </div>
            <?php if ($isProject): ?>
            <div class="col-12">
                <label class="form-label">Опис нового проєкту (підставляється, якщо при створенні проєкту опис порожній)</label>
                <textarea name="project_description" class="form-control" rows="2"<?= $dis ?>><?= View::e($tpl['project_description'] ?? '') ?></textarea>
            </div>
            <?php endif; ?>
        </div>
    </div></div>

    <h5><?= $isProject ? 'Задачі шаблону' : 'Задача' ?></h5>
    <p class="text-muted small">«Початок» і «Термін» — у днях від дати старту (0 — день старту, 7 — через тиждень); порожньо — без дати. У чек-листі й підзадачах кожен рядок — окремий пункт. Виконавців шаблон не призначає. Порожні рядки ігноруються<?= $isProject ? '; щоб додати більше задач, збережіть шаблон — з\'являться нові порожні рядки' : '' ?>.</p>
    <?php foreach ($rows as $n => $it): ?>
        <div class="card mb-2"><div class="card-body">
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label small mb-1">Назва задачі</label>
                    <input type="text" name="items[<?= $n ?>][title]" class="form-control form-control-sm" maxlength="255" value="<?= View::e($it['title']) ?>"<?= $dis ?>>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Тип</label>
                    <select name="items[<?= $n ?>][type_id]" class="form-select form-select-sm"<?= $dis ?>>
                        <option value="">— перший —</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"<?= (int)($it['type_id'] ?? 0) === (int)$t['id'] ? ' selected' : '' ?>><?= View::e($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Пріоритет</label>
                    <select name="items[<?= $n ?>][priority]" class="form-select form-select-sm"<?= $dis ?>>
                        <?php foreach ($prioLabels as $k => $l): ?>
                            <option value="<?= $k ?>"<?= ($it['priority'] ?? 'normal') === $k ? ' selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Роль виконавця</label>
                    <input type="text" name="items[<?= $n ?>][assignee_role]" maxlength="60" class="form-control form-control-sm" placeholder="напр., Керівник проєкту" value="<?= View::e($it['assignee_role'] ?? '') ?>"<?= $dis ?>>
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">Початок</label>
                    <input type="number" name="items[<?= $n ?>][start_offset]" class="form-control form-control-sm" value="<?= View::e((string)($it['start_offset'] ?? '')) ?>"<?= $dis ?>>
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">Термін</label>
                    <input type="number" name="items[<?= $n ?>][due_offset]" class="form-control form-control-sm" value="<?= View::e((string)($it['due_offset'] ?? '')) ?>"<?= $dis ?>>
                </div>
                <div class="col-12">
                    <label class="form-label small mb-1">Опис задачі</label>
                    <textarea name="items[<?= $n ?>][body]" class="form-control form-control-sm" rows="2"<?= $dis ?>><?= View::e($it['body'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-1">Чек-лист (рядок = пункт)</label>
                    <textarea name="items[<?= $n ?>][checklist]" class="form-control form-control-sm" rows="3"<?= $dis ?>><?= View::e($it['checklist'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-1">Підзадачі (рядок = підзадача)</label>
                    <textarea name="items[<?= $n ?>][subtasks]" class="form-control form-control-sm" rows="3"<?= $dis ?>><?= View::e($it['subtasks'] ?? '') ?></textarea>
                </div>
            </div>
        </div></div>
    <?php endforeach; ?>
    <?php if (!$readOnly): ?>
        <button class="btn btn-primary" type="submit">Зберегти шаблон</button>
    <?php endif; ?>
</form>

<?php if (!$isNew && !$readOnly): ?>
    <form method="post" action="/templates/<?= (int)$tpl['id'] ?>/delete" class="mt-3" data-confirm="Видалити шаблон «<?= View::e($tpl['name']) ?>»? Уже створені за ним проєкти й задачі не зміняться.">
        <?= \App\Core\Csrf::field() ?>
        <button class="btn btn-sm btn-outline-danger" type="submit">🗑️ Видалити шаблон</button>
    </form>
<?php endif; ?>
