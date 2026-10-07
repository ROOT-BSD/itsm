<div class="row justify-content-center">
    <div class="col-md-6">
        <h3 class="mb-2">Звернення до ІТ-підтримки</h3>
        <p class="text-muted">Не маєте облікового запису? Заповніть форму нижче — після відправлення отримаєте персональне посилання для відстеження статусу.</p>

        <?php if (!empty($_GET['sent'])): ?>
            <div class="alert alert-success">Звернення надіслано.</div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= \App\Core\View::e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/support" enctype="multipart/form-data" class="card p-4 shadow-sm">
            <?= \App\Core\Csrf::field() ?>

            <!-- Пастка для ботів: звичайна людина цього поля не бачить і не заповнює. -->
            <div class="position-absolute" style="left:-9999px" aria-hidden="true">
                <label>Залиште порожнім<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            <div class="mb-3">
                <label class="form-label">Ваше ім'я</label>
                <input type="text" name="requester_name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="requester_email" class="form-control" required>
                <div class="form-text">На цю адресу ми б надсилали оновлення статусу — поки що (без email-сповіщень) збережіть посилання, яке отримаєте на наступному кроці.</div>
            </div>
            <div class="mb-3">
                <label class="form-label">Черга</label>
                <select name="queue_id" class="form-select" required>
                    <option value="">— оберіть —</option>
                    <?php foreach ($queues as $q): ?>
                        <option value="<?= (int)$q['id'] ?>"><?= \App\Core\View::e($q['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Тема звернення</label>
                <input type="text" name="subject" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Опис проблеми / запиту</label>
                <textarea name="description" class="form-control" rows="4"></textarea>
            </div>
            <?php if (\App\Core\Config::get('attachments.portal_enabled', true)): ?>
                <?php
                $pickerMaxFiles = (int) \App\Core\Config::get('attachments.portal_max_files', 3);
                $pickerMaxBytes = (int) \App\Core\Config::get('attachments.portal_max_bytes', 5 * 1048576);
                require __DIR__ . '/../attachments/_picker.php';
                ?>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">Надіслати звернення</button>
        </form>

        <p class="text-center text-muted small mt-3">
            Є обліковий запис? <a href="/login">Увійдіть</a>, щоб бачити звернення у звичайному списку.
        </p>
    </div>
</div>
