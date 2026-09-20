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
                <li class="nav-item"><a class="nav-link" href="/tickets">Тікети</a></li>
                <?php if (Auth::hasRole(['admin'])): ?>
                <li class="nav-item"><a class="nav-link" href="/admin">Адмін-панель</a></li>
                <?php endif; ?>
            </ul>
            <span class="navbar-text text-light me-3">
                <?= \App\Core\View::e(Auth::name()) ?> (<?= \App\Core\View::e(Auth::role()) ?>)
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
</body>
</html>
