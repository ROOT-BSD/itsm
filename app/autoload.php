<?php
/**
 * Мінімальний автозавантажувач класів простору імен App\*
 * без залежності від Composer (мережевий доступ до Packagist
 * у цільовому середовищі розгортання не гарантований).
 */

spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
