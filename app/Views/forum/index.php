<?php $e = static fn($v) => \App\Core\View::e($v); require __DIR__ . '/_header.php'; ?>

<?php if (empty($boards)): ?>
    <div class="alert alert-light border">
        Розділів форуму ще немає.
        <?php if ($canModerate): ?><a href="/forum/boards/new">Створити перший розділ</a>.<?php endif; ?>
    </div>
<?php else: ?>
    <?php if ($canModerate): ?><div class="mb-3"><a href="/forum/boards/new" class="btn btn-sm btn-primary">+ Розділ</a></div><?php endif; ?>
    <table class="table table-bordered bg-white align-middle">
        <thead><tr><th>Розділ</th><th class="text-center">Тем</th><th class="text-center">Повідомлень</th><th class="text-nowrap">Остання активність</th></tr></thead>
        <tbody>
        <?php foreach ($boards as $b): ?>
            <tr>
                <td>
                    <a href="/forum/boards/<?= (int) $b['id'] ?>" class="fw-semibold"><?= $e($b['name']) ?></a>
                    <?php if ($b['visibility'] !== 'all'): ?><span class="badge bg-secondary" title="<?= $e(\App\Models\Forum::visibilities()[$b['visibility']] ?? '') ?>"><?= $b['visibility'] === 'admin' ? 'адмін' : 'персонал' ?></span><?php endif; ?>
                    <?php if ($b['is_locked']): ?><span class="badge bg-secondary" title="Закрито для нових тем">🔒</span><?php endif; ?>
                    <?php if (!empty($b['description'])): ?><div class="small text-muted"><?= $e($b['description']) ?></div><?php endif; ?>
                </td>
                <td class="text-center"><?= (int) $b['topic_count'] ?></td>
                <td class="text-center"><?= (int) $b['post_count'] ?></td>
                <td class="small text-nowrap"><?= $b['last_activity'] ? $e(date('d.m.Y H:i', strtotime($b['last_activity']))) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (!empty($recent)): ?>
        <h5 class="mt-4">Нещодавня активність</h5>
        <ul class="list-group bg-white">
            <?php foreach ($recent as $t): ?>
                <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                    <span><a href="/forum/topics/<?= (int) $t['id'] ?>"><?= $e($t['title']) ?></a> <span class="text-muted small">· <?= $e($t['board_name']) ?></span></span>
                    <span class="small text-muted"><?= (int) $t['reply_count'] ?> відп. · <?= $e($t['last_post_by_name'] ?? '—') ?>, <?= $e(date('d.m.Y H:i', strtotime($t['last_post_at']))) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>
