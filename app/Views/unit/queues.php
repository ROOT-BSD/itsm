<div class="mb-3"><?php require __DIR__ . '/_nav.php'; ?></div>

<h3 class="mb-1">Черги тікетів — мій підрозділ</h3>
<?php if ($ou === null): ?>
    <div class="alert alert-warning mt-3">Підрозділ не визначено — зверніться до адміністратора системи.</div>
<?php else: ?>
    <p class="text-muted">
        Тікети, де заявник або оператор — з підрозділу <strong><?= \App\Core\View::e($ouLabel) ?></strong>, у розрізі черг.
        Сам тікет відкривається й опрацьовується як зазвичай (призначення оператора, статус, коментарі).
        Створення черг, SLA та оператор за замовчуванням діють на всю систему — їх змінює лише адміністратор системи.
    </p>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr>
                <th>Черга</th><th class="text-end">Відкритих</th><th class="text-end">Без оператора</th><th class="text-end">Закритих</th>
                <th class="text-end">Усього</th><th>SLA (відповідь / вирішення)</th><th>Оператор за замовчуванням</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($queues as $q): ?>
                <tr>
                    <td><strong><?= \App\Core\View::e($q['name']) ?></strong>
                        <?php if (!empty($q['description'])): ?><div class="small text-muted"><?= \App\Core\View::e($q['description']) ?></div><?php endif; ?></td>
                    <td class="text-end"><?= (int) $q['open_count'] ?></td>
                    <td class="text-end"><?= (int) $q['unassigned_count'] ?></td>
                    <td class="text-end"><?= (int) $q['closed_count'] ?></td>
                    <td class="text-end"><?= (int) $q['total'] ?></td>
                    <td class="small"><?= $q['first_response_minutes'] !== null ? (int) $q['first_response_minutes'] . ' хв / ' . (int) $q['resolution_minutes'] . ' хв' : '—' ?></td>
                    <td class="small"><?= \App\Core\View::e($q['default_operator_name'] ?? '—') ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="/tickets?queue=<?= (int) $q['id'] ?>">Тікети</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
