<?php

namespace App\Core;

class View
{
    public static function render(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $viewFile = __DIR__ . '/../Views/' . $template . '.php';

        if (!file_exists($viewFile)) {
            throw new \RuntimeException("Шаблон не знайдено: {$template}");
        }

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        require __DIR__ . '/../Views/layout/main.php';
    }

    /**
     * URL статичного файлу з версією за часом його зміни: "/assets/css/app.css" -> "/assets/css/app.css?v=1759650000".
     * Після оновлення файлу адреса змінюється, і браузер гарантовано бере нову копію, а не тримає старий кеш —
     * незалежно від того, чи змінився номер версії застосунку. Без цього сторінка могла б отримати новий
     * скрипт зі старими стилями (так з'явилось б одночасно поле пошуку й старий список вибору).
     */
    public static function asset(string $path): string
    {
        $file = __DIR__ . '/../../public' . $path;
        $version = is_file($file) ? (string) filemtime($file) : (string) Config::get('app.version', '0');
        return $path . '?v=' . $version;
    }

    /** Екранування виводу для захисту від XSS */
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}
