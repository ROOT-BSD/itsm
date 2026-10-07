<?php

use App\Core\Auth;

?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ITSM System</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/icons/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/icons/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= \App\Core\View::e(\App\Core\View::asset('/assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="bg-light">
<?php if (Auth::check()): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="/">ITSM System</a>
        <div class="collapse navbar-collapse">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="/">Дашборд</a></li>
                <li class="nav-item"><a class="nav-link" href="/projects">Проєкти</a></li>
                <li class="nav-item"><a class="nav-link" href="/calendar">Календар</a></li>
                <li class="nav-item"><a class="nav-link" href="/tickets">Тікети</a></li>
                <li class="nav-item"><a class="nav-link" href="/archive">Архів</a></li>
                <li class="nav-item"><a class="nav-link" href="/wiki">Вікі</a></li>
                <?php if (Auth::hasRole(['admin'])): ?>
                <li class="nav-item"><a class="nav-link<?= \App\Core\AdminNav::currentSection() !== null ? ' active' : '' ?>" href="/admin"<?= \App\Core\AdminNav::currentSection() !== null ? ' aria-current="page"' : '' ?>>Адмін-панель</a></li>
                <?php endif; ?>
            </ul>
            <span class="navbar-text text-light me-3">
                <a href="/profile" class="text-light text-decoration-none">
                    <?= \App\Core\View::e(Auth::name()) ?> (<?= \App\Core\View::e(Auth::role()) ?>)
                </a>
            </span>
            <a href="/logout" class="btn btn-outline-light btn-sm">Вийти</a>
        </div>
    </div>
</nav>
<?php endif; ?>

<main class="container py-4">
    <?= $content ?>
</main>

<footer class="text-center text-muted small py-3">
    ITSM System v<?= \App\Core\View::e(\App\Core\Config::get('app.version', '0.0.0')) ?>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script nonce="<?= \App\Core\Csp::nonce() ?>">
// CSP блокує inline-обробники (onclick="" тощо) так само, як сторонній <script> —
// тому замість них в усьому застосунку два делеговані обробники тут, в одному
// nonce'd блоці, активному на кожній сторінці.

// <form data-confirm="текст"> — підтверджувальне діалогове вікно перед сабмітом
// (було: onsubmit="return confirm('текст')" або onclick="return confirm(...)" на кнопці).
document.addEventListener('submit', function (e) {
    const form = e.target.closest('form[data-confirm]');
    if (form && !confirm(form.dataset.confirm)) {
        e.preventDefault();
    }
});

// <select class="auto-submit-select"> — одразу надсилає форму при зміні значення
// (було: onchange="this.form.requestSubmit()").
document.addEventListener('change', function (e) {
    if (e.target.matches('select.auto-submit-select')) {
        e.target.form.requestSubmit();
    }
});
</script>
<script src="<?= \App\Core\View::e(\App\Core\View::asset('/assets/js/user-select.js')) ?>" defer></script>
<script src="<?= \App\Core\View::e(\App\Core\View::asset('/assets/js/paste-images.js')) ?>" defer></script>
<script src="<?= \App\Core\View::e(\App\Core\View::asset('/assets/js/wiki-editor.js')) ?>" defer></script>
</body>
</html>
