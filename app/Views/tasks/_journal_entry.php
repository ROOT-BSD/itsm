<?php /** Один запис журналу робіт. Очікує: $c, $task, $attachments. */ ?>
    <li class="list-group-item" id="entry-<?= (int) $c['id'] ?>">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
            <strong><?= \App\Core\View::e($c['user_name']) ?></strong>
            <span class="text-muted small"><?= \App\Core\View::e(date('d.m.Y', strtotime($c['log_date']))) ?></span>
            <?php if ((float) $c['hours'] > 0): ?><span class="badge text-bg-primary"><?= \App\Core\View::e(\App\Models\Task::formatHours((float) $c['hours'])) ?></span><?php endif; ?>
            <?php if (!empty($c['activity_category'])): ?><span class="badge text-bg-light border"><?= \App\Core\View::e($c['activity_category']) ?></span><?php endif; ?>
            <?php if (!empty($c['edited_at'])): ?><span class="text-muted small fst-italic">змінено <?= \App\Core\View::e(date('d.m.Y H:i', strtotime($c['edited_at']))) ?></span><?php endif; ?>
        </div>
        <?php if (!empty($c['comment'])): ?>
            <div class="mb-1 wiki-content journal-text"><?= \App\Core\View::journalText($c['comment'], $attachments ?? [], fn($id) => '/attachments/' . $id) /* Markdown: увесь текст екранований рендерером */ ?></div>
        <?php endif; ?>
        <?php if (!empty($c['can_edit'])): ?>
            <div class="d-flex gap-3 align-items-center">
                <button type="button" class="btn btn-link btn-sm p-0" data-bs-toggle="collapse" data-bs-target="#edit-<?= (int) $c['id'] ?>" aria-expanded="false" aria-controls="edit-<?= (int) $c['id'] ?>">Редагувати</button>
                <form method="post" action="/tasks/<?= (int) $task['id'] ?>/journal/<?= (int) $c['id'] ?>/delete" data-journal-delete data-confirm-text="Видалити цей запис журналу? Витрачений час задачі буде перераховано.">
                    <?= \App\Core\Csrf::field() ?>
                    <button type="submit" class="btn btn-link btn-sm text-danger p-0">Видалити</button>
                </form>
            </div>
            <div class="collapse mt-2" id="edit-<?= (int) $c['id'] ?>">
                <?php $formEntry = $c; $formAction = '/tasks/' . (int) $task['id'] . '/journal/' . (int) $c['id']; $formId = 'je' . (int) $c['id']; $formSubmit = 'Зберегти зміни'; require __DIR__ . '/_journal_form.php'; ?>
            </div>
        <?php endif; ?>
    </li>
