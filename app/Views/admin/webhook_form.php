<?php
$e = static fn($v) => \App\Core\View::e($v);
$isNew = $endpoint === null;
$allSelected = $form['events'] === ['*'];
$groups = [];
foreach (\App\Models\WebhookEndpoint::EVENTS as $code => [$group, $label]) { $groups[$group][$code] = $label; }
?>
<div class="mb-3"><a href="/admin/webhooks" class="text-decoration-none">&larr; Вебхуки</a></div>
<h3 class="mb-3"><?= $isNew ? 'Новий вебхук' : 'Вебхук: ' . $e($endpoint['name']) ?></h3>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>

<?php if (!empty($secret)): ?>
    <div class="alert alert-success border-success">
        <h5 class="alert-heading">Секрет підпису</h5>
        <p class="mb-2"><strong>Скопіюйте його зараз</strong> і вкажіть в одержувача: за ним він перевіряє підпис. Далі в системі видно лише кінець секрету; при потребі його можна замінити новим.</p>
        <input type="text" class="form-control font-monospace" readonly value="<?= $e($secret) ?>" onclick="this.select()">
    </div>
<?php endif; ?>

<?php if (!$isNew && !$endpoint['is_active']): ?>
    <div class="alert alert-warning">Вебхук <strong>вимкнений</strong>: <?= $e($endpoint['disabled_reason'] ?? '') ?> Нові події на нього не надсилаються.</div>
<?php endif; ?>

<form method="post" action="<?= $isNew ? '/admin/webhooks' : '/admin/webhooks/' . (int) $endpoint['id'] ?>" class="card p-4 shadow-sm mb-4">
    <?= \App\Core\Csrf::field() ?>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="wh-name">Назва</label>
            <input type="text" id="wh-name" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" maxlength="100" required value="<?= $e($form['name']) ?>" placeholder="Сповіщення в месенджер">
            <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= $e($errors['name']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-8">
            <label class="form-label" for="wh-url">Адреса одержувача (URL)</label>
            <input type="text" id="wh-url" name="url" class="form-control font-monospace <?= isset($errors['url']) ? 'is-invalid' : '' ?>" maxlength="500" required value="<?= $e($form['url']) ?>" placeholder="https://hooks.example.org/itsm">
            <?php if (isset($errors['url'])): ?><div class="invalid-feedback"><?= $e($errors['url']) ?></div><?php endif; ?>
            <div class="form-text">http або https. Система не виконує перенаправлень і не надсилає на адреси link-local (169.254.x.x).</div>
        </div>
    </div>

    <div class="mt-3">
        <label class="form-label">Події</label>
        <?php if (isset($errors['events'])): ?><div class="text-danger small mb-1"><?= $e($errors['events']) ?></div><?php endif; ?>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="events_all" value="1" id="ev-all" <?= $allSelected ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="ev-all">Усі події (зокрема ті, що з'являться в майбутніх версіях)</label>
        </div>
        <div class="row">
            <?php foreach ($groups as $group => $items): ?>
                <div class="col-md-4 mb-2">
                    <div class="small text-uppercase text-muted fw-semibold"><?= $e($group) ?></div>
                    <?php foreach ($items as $code => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="events[]" value="<?= $e($code) ?>" id="ev-<?= $e($code) ?>" <?= (!$allSelected && in_array($code, $form['events'], true)) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="ev-<?= $e($code) ?>"><code><?= $e($code) ?></code><br><span class="text-muted"><?= $e($label) ?></span></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="form-text">Якщо обрано «Усі події», окремі позначки ігноруються.</div>
    </div>

    <div class="form-check mt-3">
        <input class="form-check-input" type="checkbox" name="verify_tls" value="1" id="wh-tls" <?= $form['verify_tls'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="wh-tls">Перевіряти TLS-сертифікат одержувача</label>
        <div class="form-text">Знімайте лише для внутрішніх сервісів із самопідписаним сертифікатом — інакше можлива підміна одержувача.</div>
    </div>

    <div class="mt-4 d-flex gap-2"><button type="submit" class="btn btn-primary"><?= $isNew ? 'Створити вебхук' : 'Зберегти' ?></button><a href="/admin/webhooks" class="btn btn-outline-secondary">Скасувати</a></div>
</form>

<?php if (!$isNew): ?>
    <div class="card p-4 shadow-sm mb-4">
        <h5>Секрет і перевірка</h5>
        <p class="mb-2">Секрет: <code><?= $e(\App\Models\WebhookEndpoint::maskSecret($endpoint['secret'])) ?></code></p>
        <div class="d-flex gap-2 flex-wrap">
            <form method="post" action="/admin/webhooks/<?= (int) $endpoint['id'] ?>/test"><?= \App\Core\Csrf::field() ?><button type="submit" class="btn btn-outline-primary">Надіслати тестову подію</button></form>
            <form method="post" action="/admin/webhooks/<?= (int) $endpoint['id'] ?>/rotate-secret" data-confirm="Створити новий секрет? Старий одразу перестане діяти — доведеться оновити його в одержувача."><?= \App\Core\Csrf::field() ?><button type="submit" class="btn btn-outline-secondary">Замінити секрет</button></form>
            <a href="/admin/webhooks/<?= (int) $endpoint['id'] ?>/deliveries" class="btn btn-outline-secondary">Журнал доставок</a>
            <form method="post" action="/admin/webhooks/<?= (int) $endpoint['id'] ?>/delete" data-confirm="Видалити вебхук «<?= $e($endpoint['name']) ?>» разом із журналом його доставок? Це незворотно."><?= \App\Core\Csrf::field() ?><button type="submit" class="btn btn-outline-danger">Видалити</button></form>
        </div>
    </div>
<?php endif; ?>

<div class="card p-4 shadow-sm">
    <h5>Як одержувачу перевірити підпис</h5>
    <p class="small text-muted mb-2">Кожен запит — <code>POST</code> із JSON у тілі та заголовками <code>X-ITSM-Event</code>, <code>X-ITSM-Event-Id</code>, <code>X-ITSM-Delivery</code>,
        <code>X-ITSM-Timestamp</code> і <code>X-ITSM-Signature: sha256=…</code>. Підпис = HMAC-SHA256 від рядка <code>«мітка_часу.тіло»</code> ключем-секретом.</p>
    <pre class="small mb-2">expected = "sha256=" + hex(hmac_sha256(secret, timestamp + "." + raw_body))
перевірте збіг (порівнюйте в сталий час) і що |now − timestamp| &lt; 5 хвилин</pre>
    <p class="small text-muted mb-0">Відповідайте кодом 2xx протягом 6 секунд. Невдалі доставки повторюються (через 1 хв, 5 хв, 30 хв, 2 год, 12 год); можливі дублі —
        дедуплікуйте за полем <code>id</code> події (воно однакове при повторах). Код 410 вимикає вебхук одразу.</p>
</div>
