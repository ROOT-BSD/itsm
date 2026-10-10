<?php
/** Таблиця тем. Очікує: $topics; необов'язково $showBoard (показати колонку розділу). */
$e = static fn($v) => \App\Core\View::e($v);
?>
<table class="table table-bordered bg-white align-middle">
    <thead><tr><th>Тема</th><?php if (!empty($showBoard)): ?><th>Розділ</th><?php endif; ?><th class="text-center">Відповідей</th><th class="text-nowrap">Остання активність</th></tr></thead>
    <tbody>
    <?php foreach ($topics as $t): ?>
        <tr>
            <td>
                <?php if (!empty($t['is_pinned'])): ?><span class="badge bg-warning text-dark" title="Закріплена">📌</span><?php endif; ?>
                <?php if (!empty($t['is_locked'])): ?><span class="badge bg-secondary" title="Закрита для відповідей">🔒</span><?php endif; ?>
                <?php if (!empty($t['is_unread'])): ?><span class="badge bg-success" title="Є нові повідомлення">нове</span><?php endif; ?>
                <a href="/forum/topics/<?= (int) $t['id'] ?>" class="<?= !empty($t['is_unread']) ? 'fw-bold' : 'fw-semibold' ?>"><?= $e($t['title']) ?></a>
                <div class="small text-muted">автор: <?= $e($t['author_name'] ?? 'користувача видалено') ?>, <?= $e(date('d.m.Y', strtotime($t['created_at']))) ?></div>
            </td>
            <?php if (!empty($showBoard)): ?><td class="small"><?= $e($t['board_name']) ?></td><?php endif; ?>
            <td class="text-center"><?= (int) $t['reply_count'] ?></td>
            <td class="small text-nowrap"><?= $e(date('d.m.Y H:i', strtotime($t['last_post_at']))) ?><div class="text-muted"><?= $e($t['last_post_by_name'] ?? '—') ?></div></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
