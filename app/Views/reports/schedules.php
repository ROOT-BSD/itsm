<?php
$e = static fn($v) => \App\Core\View::e($v);
$wd = \App\Models\ReportSchedule::WEEKDAYS;
$fm = \App\Models\ReportSchedule::FORMATS;
?>
<div class="mb-3"><a href="/reports" class="text-decoration-none">&larr; Звіти</a></div>

<h3 class="mb-3">Звіти за розкладом</h3>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>

<p class="text-muted">Звіт обліку часу надходитиме на пошту вкладенням (PDF, Excel чи CSV) автоматично: раз на тиждень — за попередній тиждень (пн–нд),
    раз на місяць — за попередній місяць. Звіт будується з правами власника розкладу: лише з проєктів, до яких у нього є доступ.</p>

<?php if (!$ready): ?>
    <div class="alert alert-warning">Таблиці розкладів ще немає в базі даних. Виконайте <code>sudo bash update.sh</code> (міграція 042).</div>
<?php else: ?>
<?php if (!$mailOn): ?>
    <div class="alert alert-warning">Надсилання пошти не налаштовано (SMTP у <code>.env</code>) — розклади збережуться, але листи не підуть, доки адміністратор не налаштує пошту.</div>
<?php endif; ?>

<div class="card shadow-sm mb-4"><div class="card-body">
    <h5 class="card-title">Новий розклад</h5>
    <?php $sch = null; $action = '/reports/schedules'; $submitLabel = 'Створити розклад'; include __DIR__ . '/_schedule_form.php'; ?>
</div></div>

<h5>Розклади</h5>
<table class="table table-bordered bg-white align-middle">
    <thead><tr><th>Назва</th><th>Коли</th><th>Фільтри</th><th>Одержувачі</th><th>Остання відправка</th><th style="width:340px"></th></tr></thead>
    <tbody>
    <?php if (empty($schedules)): ?>
        <tr><td colspan="6" class="text-center text-muted">Розкладів ще немає</td></tr>
    <?php else: foreach ($schedules as $s): ?>
        <tr class="<?= (int) $s['is_active'] ? '' : 'text-muted' ?>">
            <td><strong><?= $e($s['name']) ?></strong><?= (int) $s['is_active'] ? '' : ' <span class="badge bg-secondary">вимкнено</span>' ?>
                <?php if ($isAdmin): ?><div class="small text-muted"><?= $e($s['owner_name']) ?></div><?php endif; ?></td>
            <td class="small"><?= $s['frequency'] === 'monthly' ? 'щомісяця, ' . (int) $s['month_day'] . '-го' : 'щотижня, ' . $e($wd[(int) $s['weekday']] ?? '') ?>
                після <?= sprintf('%02d:00', (int) $s['send_hour']) ?><br><?= $e($fm[$s['format']] ?? $s['format']) ?></td>
            <td class="small">Проєкт: <?= $e($s['project_name'] ?: 'усі') ?><br>Користувач: <?= $e($s['log_user_name'] ?: 'усі') ?><?= $s['category'] ? '<br>Категорія: ' . $e($s['category']) : '' ?></td>
            <td class="small"><?= $e(implode(', ', \App\Services\ReportScheduleService::recipients($s))) ?></td>
            <td class="small"><?= $e($s['last_status'] ?: '—') ?></td>
            <td class="text-end">
                <form method="post" action="/reports/schedules/<?= (int) $s['id'] ?>/run" class="d-inline" data-confirm="Надіслати звіт за попередній період одержувачам зараз?">
                    <?= \App\Core\Csrf::field() ?><button type="submit" class="btn btn-sm btn-outline-primary" <?= $mailOn ? '' : 'disabled' ?>>Надіслати зараз</button>
                </form>
                <a href="/reports/schedules/<?= (int) $s['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">Редагувати</a>
                <form method="post" action="/reports/schedules/<?= (int) $s['id'] ?>/toggle" class="d-inline">
                    <?= \App\Core\Csrf::field() ?><button type="submit" class="btn btn-sm btn-outline-secondary"><?= (int) $s['is_active'] ? 'Вимкнути' : 'Увімкнути' ?></button>
                </form>
                <form method="post" action="/reports/schedules/<?= (int) $s['id'] ?>/delete" class="d-inline" data-confirm="Видалити цей розклад?">
                    <?= \App\Core\Csrf::field() ?><button type="submit" class="btn btn-sm btn-outline-danger">Видалити</button>
                </form>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

<p class="small text-muted">Листи надсилає скрипт <code>bin/send-reports.php</code>, який адміністратор запускає з cron щогодини:
    <code>5 * * * *  www-data  php /var/www/itsm-system/bin/send-reports.php</code>.
    <?= $lastRun ? 'Останній запуск: ' . $e(date('d.m.Y H:i', strtotime($lastRun))) . '.' : 'Запусків ще не було.' ?>
    Пропущений запуск наздоганяється, невдала відправка повторюється наступним запуском.</p>

<?php endif; ?>
