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
    <form method="post" action="/reports/schedules" class="row g-3" id="schedule-form">
        <?= \App\Core\Csrf::field() ?>
        <div class="col-md-6">
            <label class="form-label" for="sch-name">Назва</label>
            <input type="text" name="name" id="sch-name" class="form-control" maxlength="150" required placeholder="напр. Облік часу команди — щотижня">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="sch-freq">Періодичність</label>
            <select name="frequency" id="sch-freq" class="form-select">
                <option value="weekly">Щотижня</option>
                <option value="monthly">Щомісяця</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="sch-format">Формат файлу</label>
            <select name="format" id="sch-format" class="form-select">
                <?php foreach ($fm as $k => $v): ?><option value="<?= $e($k) ?>"><?= $e($v) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3" id="sch-weekday-box">
            <label class="form-label" for="sch-weekday">День тижня</label>
            <select name="weekday" id="sch-weekday" class="form-select">
                <?php foreach ($wd as $k => $v): ?><option value="<?= (int) $k ?>"><?= $e($v) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-none" id="sch-monthday-box">
            <label class="form-label" for="sch-monthday">Число місяця</label>
            <select name="month_day" id="sch-monthday" class="form-select">
                <?php for ($d = 1; $d <= 28; $d++): ?><option value="<?= $d ?>"><?= $d ?></option><?php endfor; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="sch-hour">Не раніше (година)</label>
            <select name="send_hour" id="sch-hour" class="form-select">
                <?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>" <?= $h === 8 ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="sch-project">Проєкт</label>
            <select name="project_id" id="sch-project" class="form-select">
                <option value="">— усі доступні проєкти —</option>
                <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>"><?= $e($p['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="sch-user">Користувач (чий облік часу)</label>
            <select name="user_id" id="sch-user" class="form-select user-select">
                <option value="">— усі користувачі —</option>
                <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"><?= $e($u['full_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="sch-cat">Категорія активності</label>
            <input type="text" name="category" id="sch-cat" class="form-control" list="sch-cats" maxlength="100" placeholder="необов'язково">
            <datalist id="sch-cats"><?php foreach ($categories as $c): ?><option value="<?= $e($c) ?>"><?php endforeach; ?></datalist>
        </div>
        <div class="col-12">
            <label class="form-label" for="sch-extra">Додаткові одержувачі (email через кому; ви отримуватимете звіт завжди)</label>
            <input type="text" name="extra_recipients" id="sch-extra" class="form-control" placeholder="<?= $isAdmin ? 'будь-які адреси' : 'лише адреси користувачів системи' ?>">
        </div>
        <div class="col-12"><button type="submit" class="btn btn-primary">Створити розклад</button></div>
    </form>
</div></div>

<h5>Розклади</h5>
<table class="table table-bordered bg-white align-middle">
    <thead><tr><th>Назва</th><th>Коли</th><th>Фільтри</th><th>Одержувачі</th><th>Остання відправка</th><th style="width:260px"></th></tr></thead>
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

<script nonce="<?= \App\Core\Csp::nonce() ?>">
document.getElementById('sch-freq')?.addEventListener('change', function () {
    const monthly = this.value === 'monthly';
    document.getElementById('sch-weekday-box').classList.toggle('d-none', monthly);
    document.getElementById('sch-monthday-box').classList.toggle('d-none', !monthly);
});
</script>
<?php endif; ?>
