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

        <?php
        // Нативні <input type="week"> і <input type="month"> в Safari (macOS) не
        // підтримуються взагалі — поле просто не показується. Тому замість них —
        // звичайні <select>, які працюють однаково в усіх браузерах.
        $years = range($currentYear - 1, $currentYear + 1);
        ?>
        <div id="week_field" class="row g-2">
            <div class="col-6 col-sm-4">
                <select id="week_year" class="form-select" onchange="updateWeekValue()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $y === $currentYear ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-4">
                <select id="week_number" class="form-select" onchange="updateWeekValue()">
                    <?php for ($w = 1; $w <= 53; $w++): ?>
                        <option value="<?= $w ?>" <?= $w === $currentWeek ? 'selected' : '' ?>>Тиждень <?= $w ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <input type="hidden" name="week" id="week_hidden" required>
        </div>

        <div id="month_field" class="row g-2 d-none">
            <div class="col-6 col-sm-4">
                <select id="month_year" class="form-select" onchange="updateMonthValue()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $y === $currentYear ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-4">
                <?php
                $monthNames = [1 => 'Січень', 2 => 'Лютий', 3 => 'Березень', 4 => 'Квітень', 5 => 'Травень', 6 => 'Червень',
                               7 => 'Липень', 8 => 'Серпень', 9 => 'Вересень', 10 => 'Жовтень', 11 => 'Листопад', 12 => 'Грудень'];
                ?>
                <select id="month_number" class="form-select" onchange="updateMonthValue()">
                    <?php foreach ($monthNames as $num => $name): ?>
                        <option value="<?= $num ?>" <?= $num === $currentMonth ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="month" id="month_hidden">
        </div>
    </div>

    <script>
        // Прихований інпут з required все одно бере участь у валідації форми
        // (навіть коли display:none) — браузер тоді мовчки блокує сабміт без
        // видимого повідомлення. Тому required вмикаємо ЛИШЕ на активному полі.
        function setPeriodType(type) {
            const weekField = document.getElementById('week_field');
            const monthField = document.getElementById('month_field');
            const weekHidden = document.getElementById('week_hidden');
            const monthHidden = document.getElementById('month_hidden');

            if (type === 'week') {
                weekField.classList.remove('d-none');
                monthField.classList.add('d-none');
                weekHidden.required = true;
                monthHidden.required = false;
            } else {
                monthField.classList.remove('d-none');
                weekField.classList.add('d-none');
                monthHidden.required = true;
                weekHidden.required = false;
            }
        }

        function updateWeekValue() {
            const year = document.getElementById('week_year').value;
            const week = document.getElementById('week_number').value.padStart(2, '0');
            document.getElementById('week_hidden').value = year + '-W' + week;
        }

        function updateMonthValue() {
            const year = document.getElementById('month_year').value;
            const month = document.getElementById('month_number').value.padStart(2, '0');
            document.getElementById('month_hidden').value = year + '-' + month;
        }

        // Початкові значення прихованих полів одразу при завантаженні сторінки
        updateWeekValue();
        updateMonthValue();
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
