<div class="mb-3">
    <a href="/" class="text-decoration-none">&larr; Дашборд</a>
</div>

<h3 class="mb-4">Звіти</h3>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
<?php endif; ?>

<p class="text-muted">
    Сформуйте PDF-звіт обліку часу за тиждень чи місяць, з фільтрами за проєктом,
    користувачем і категорією активності. Показуються лише дані з проєктів, до яких у вас є доступ.
</p>

<form method="get" action="/reports/pdf" class="card p-4 shadow-sm form-card-md" target="_blank">
    <div class="mb-3">
        <label class="form-label">Період</label>
        <div class="d-flex gap-3 mb-2">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="period_type" id="period_week" value="week" checked
                       onchange="setPeriodType('week')">
                <label class="form-check-label" for="period_week">Тиждень</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="period_type" id="period_month" value="month"
                       onchange="setPeriodType('month')">
                <label class="form-check-label" for="period_month">Місяць</label>
            </div>
        </div>
        <div id="week_field">
            <input type="week" name="week" class="form-control" required>
        </div>
        <div id="month_field" class="d-none">
            <input type="month" name="month" class="form-control">
        </div>
    </div>

    <script>
        // Прихований інпут з required все одно бере участь у валідації форми
        // (навіть коли display:none) — браузер тоді мовчки блокує сабміт без
        // видимого повідомлення. Тому required вмикаємо ЛИШЕ на активному полі.
        function setPeriodType(type) {
            const weekField = document.getElementById('week_field');
            const monthField = document.getElementById('month_field');
            const weekInput = weekField.querySelector('input[name="week"]');
            const monthInput = monthField.querySelector('input[name="month"]');

            if (type === 'week') {
                weekField.classList.remove('d-none');
                monthField.classList.add('d-none');
                weekInput.required = true;
                monthInput.required = false;
            } else {
                monthField.classList.remove('d-none');
                weekField.classList.add('d-none');
                monthInput.required = true;
                weekInput.required = false;
            }
        }
    </script>

    <div class="mb-3">
        <label class="form-label">Проєкт</label>
        <select name="project_id" class="form-select">
            <option value="">— усі доступні проєкти —</option>
            <?php foreach ($projects as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $selectedProjectId === (int)$p['id'] ? 'selected' : '' ?>><?= \App\Core\View::e($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label">Користувач</label>
        <select name="user_id" class="form-select">
            <option value="">— усі користувачі —</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= \App\Core\View::e($u['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label">Категорія активності (необов'язково)</label>
        <input type="text" name="category" class="form-control" list="category-options" placeholder="напр. розробка">
        <datalist id="category-options">
            <?php foreach ($categories as $c): ?>
                <option value="<?= \App\Core\View::e($c) ?>">
            <?php endforeach; ?>
        </datalist>
    </div>

    <button type="submit" class="btn btn-primary">Сформувати PDF</button>
</form>
