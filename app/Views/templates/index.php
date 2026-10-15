<?php use App\Core\View; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">Шаблони проєктів і задач</h3>
    <?php if ($canManage): ?>
        <span>
            <a href="/templates/new?kind=project" class="btn btn-primary btn-sm">+ Шаблон проєкту</a>
            <a href="/templates/new?kind=task" class="btn btn-outline-primary btn-sm">+ Шаблон задачі</a>
        </span>
    <?php endif; ?>
</div>
<?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= View::e($_GET['error']) ?></div><?php endif; ?>
<?php if (!empty($_GET['success'])): ?><div class="alert alert-success"><?= View::e($_GET['success']) ?></div><?php endif; ?>

<p class="text-muted">Шаблон проєкту — набір типових задач (з чек-листами й підзадачами), який застосовується при створенні проєкту чи на сторінці наявного. Шаблон задачі — одна типова задача, яку додають у проєкт. Терміни в шаблонах задаються в днях від дати старту.</p>

<?php foreach (['project' => 'Шаблони проєктів', 'task' => 'Шаблони задач'] as $kind => $label): ?>
    <h5 class="mt-4"><?= $label ?></h5>
    <?php $rows = array_filter($templates, fn($t) => $t['kind'] === $kind); ?>
    <?php if (!$rows): ?>
        <p class="text-muted">Поки немає<?= $canManage ? '. Створіть шаблон кнопкою вгорі або збережіть наявний проєкт/задачу як шаблон.' : '.' ?></p>
    <?php else: ?>
        <table class="table table-sm align-middle">
            <thead><tr><th>Назва</th><th>Опис</th><th><?= $kind === 'project' ? 'Задач' : '' ?></th><th>Автор</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $t): ?>
                <tr>
                    <td><a href="/templates/<?= (int)$t['id'] ?>"><?= View::e($t['name']) ?></a></td>
                    <td class="text-muted"><?= View::e($t['description'] ?? '') ?></td>
                    <td><?= $kind === 'project' ? (int)$t['items_count'] : '' ?></td>
                    <td><?= View::e($t['author_name'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endforeach; ?>
