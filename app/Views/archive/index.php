<div class="mb-3">
    <a href="/" class="text-decoration-none">&larr; Дашборд</a>
</div>

<h3 class="mb-2">Архів</h3>
<p class="text-muted">
    Закриті тікети, задачі й проєкти — прибрані з головних списків, щоб ті показували лише актуальну роботу.
    Тут зібрано все закрите, до чого у вас є доступ.
</p>

<h5 class="mt-4">Закриті проєкти</h5>
<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr><th>Назва</th><th>Відповідальний</th></tr>
    </thead>
    <tbody>
    <?php if (empty($projects)): ?>
        <tr><td colspan="2" class="text-center text-muted">Закритих проєктів немає</td></tr>
    <?php else: foreach ($projects as $p): ?>
        <tr>
            <td>
                <a href="/projects/<?= (int)$p['id'] ?>"><?= \App\Core\View::e($p['name']) ?></a>
                <?php if (!empty($p['parent_id'])): ?>
                    <div class="small text-muted">↳ підпроєкт: <?= \App\Core\View::e($p['parent_name']) ?></div>
                <?php endif; ?>
            </td>
            <td><?= \App\Core\View::e($p['responsible_name'] ?? '— не призначено —') ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

<h5 class="mt-4">Закриті тікети</h5>
<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr><th>#</th><th>Тема</th><th>Черга</th><th>Проєкт</th><th>Заявник</th><th>Оператор</th></tr>
    </thead>
    <tbody>
    <?php if (empty($tickets)): ?>
        <tr><td colspan="6" class="text-center text-muted">Закритих тікетів немає</td></tr>
    <?php else: foreach ($tickets as $t): ?>
        <tr>
            <td><a href="/tickets/<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
            <td><?= \App\Core\View::e($t['subject']) ?></td>
            <td><?= \App\Core\View::e($t['queue_name']) ?></td>
            <td>
                <?php if (!empty($t['project_id'])): ?>
                    <a href="/projects/<?= (int)$t['project_id'] ?>"><?= \App\Core\View::e($t['project_name'] ?? '') ?></a>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td><?= \App\Core\View::e($t['requester_name']) ?></td>
            <td><?= \App\Core\View::e($t['operator_name'] ?? '— не призначено —') ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

<h5 class="mt-4">Закриті задачі</h5>
<table class="table table-bordered bg-white align-middle">
    <thead>
        <tr><th>#</th><th>Назва</th><th>Проєкт</th><th>Виконавець</th><th>Пріоритет</th></tr>
    </thead>
    <tbody>
    <?php if (empty($tasks)): ?>
        <tr><td colspan="5" class="text-center text-muted">Закритих задач немає</td></tr>
    <?php else: foreach ($tasks as $t): ?>
        <tr>
            <td><a href="/tasks/<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
            <td><?= \App\Core\View::e($t['title']) ?></td>
            <td><a href="/projects/<?= (int)$t['project_id'] ?>"><?= \App\Core\View::e($t['project_name']) ?></a></td>
            <td><?= \App\Core\View::e($t['assignee_name'] ?? '— не призначено —') ?></td>
            <td><?= \App\Core\View::e($t['priority']) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
