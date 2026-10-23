<?php
// Спільна форма створення/редагування розкладу. Очікує: $sch (масив або null), $action, $submitLabel, $projects, $users, $categories, $isAdmin.
$e = static fn($v) => \App\Core\View::e($v);
$wd = \App\Models\ReportSchedule::WEEKDAYS;
$fm = \App\Models\ReportSchedule::FORMATS;
$isMonthly = ($sch['frequency'] ?? '') === 'monthly';
?>
    <form method="post" action="<?= $e($action) ?>" class="row g-3" id="schedule-form">
        <?= \App\Core\Csrf::field() ?>
        <div class="col-md-6">
            <label class="form-label" for="sch-name">Назва</label>
            <input type="text" name="name" id="sch-name" class="form-control" maxlength="150" required value="<?= $e($sch['name'] ?? '') ?>" placeholder="напр. Облік часу команди — щотижня">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="sch-freq">Періодичність</label>
            <select name="frequency" id="sch-freq" class="form-select">
                <option value="weekly" <?= ($sch['frequency'] ?? 'weekly') === 'weekly' ? 'selected' : '' ?>>Щотижня</option>
                <option value="monthly" <?= ($sch['frequency'] ?? '') === 'monthly' ? 'selected' : '' ?>>Щомісяця</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="sch-format">Формат файлу</label>
            <select name="format" id="sch-format" class="form-select">
                <?php foreach ($fm as $k => $v): ?><option value="<?= $e($k) ?>" <?= ($sch['format'] ?? 'pdf') === $k ? 'selected' : '' ?>><?= $e($v) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 <?= $isMonthly ? 'd-none' : '' ?>" id="sch-weekday-box">
            <label class="form-label" for="sch-weekday">День тижня</label>
            <select name="weekday" id="sch-weekday" class="form-select">
                <?php foreach ($wd as $k => $v): ?><option value="<?= (int) $k ?>" <?= (int) ($sch['weekday'] ?? 1) === (int) $k ? 'selected' : '' ?>><?= $e($v) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 <?= $isMonthly ? '' : 'd-none' ?>" id="sch-monthday-box">
            <label class="form-label" for="sch-monthday">Число місяця</label>
            <select name="month_day" id="sch-monthday" class="form-select">
                <?php for ($d = 1; $d <= 28; $d++): ?><option value="<?= $d ?>" <?= (int) ($sch['month_day'] ?? 1) === $d ? 'selected' : '' ?>><?= $d ?></option><?php endfor; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="sch-hour">Не раніше (година)</label>
            <select name="send_hour" id="sch-hour" class="form-select">
                <?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>" <?= $h === (int) ($sch['send_hour'] ?? 8) ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="sch-project">Проєкт</label>
            <select name="project_id" id="sch-project" class="form-select">
                <option value="">— усі доступні проєкти —</option>
                <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($sch['project_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= $e($p['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="sch-user">Користувач (чий облік часу)</label>
            <select name="user_id" id="sch-user" class="form-select user-select">
                <option value="">— усі користувачі —</option>
                <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) ($sch['log_user_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= $e($u['full_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="sch-cat">Категорія активності</label>
            <input type="text" name="category" id="sch-cat" class="form-control" list="sch-cats" maxlength="100" value="<?= $e($sch['category'] ?? '') ?>" placeholder="необов'язково">
            <datalist id="sch-cats"><?php foreach ($categories as $c): ?><option value="<?= $e($c) ?>"><?php endforeach; ?></datalist>
        </div>
        <div class="col-12">
            <label class="form-label" for="sch-extra">Додаткові одержувачі (email через кому; ви отримуватимете звіт завжди)</label>
            <input type="text" name="extra_recipients" id="sch-extra" class="form-control" value="<?= $e($sch['extra_recipients'] ?? '') ?>" placeholder="<?= $isAdmin ? 'будь-які адреси' : 'лише адреси користувачів системи' ?>">
        </div>
        <div class="col-12"><button type="submit" class="btn btn-primary"><?= $e($submitLabel) ?></button><?php if ($sch): ?> <a href="/reports/schedules" class="btn btn-outline-secondary">Скасувати</a><?php endif; ?></div>
    </form>
<script nonce="<?= \App\Core\Csp::nonce() ?>">
document.getElementById('sch-freq')?.addEventListener('change', function () {
    const monthly = this.value === 'monthly';
    document.getElementById('sch-weekday-box').classList.toggle('d-none', monthly);
    document.getElementById('sch-monthday-box').classList.toggle('d-none', !monthly);
});
</script>
