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

    /**
     * Терміновість за датою виконання (Y-m-d): 4 — прострочено, 3 — сьогодні/завтра, 2 — до 3 днів,
     * 1 — до 7 днів, 0 — пізніше або терміну немає. Закриті задачі викликач не підсвічує.
     */
    public static function urgencyLevel(?string $due): int
    {
        if ($due === null || $due === '') {
            return 0;
        }
        $days = (int) floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400);
        return $days < 0 ? 4 : ($days <= 1 ? 3 : ($days <= 3 ? 2 : ($days <= 7 ? 1 : 0)));
    }

    /** CSS-клас рядка таблиці задач за терміном ('' якщо підсвічувати не треба). */
    public static function urgencyClass(?string $due): string
    {
        $l = self::urgencyLevel($due);
        return $l > 0 ? 'task-urgency-' . $l : '';
    }

    /**
     * Прогрес підзадач і чек-листа задачі — значки «⧉ 1/3» (закрито підзадач) і «☑ 2/5» (виконано пунктів чек-листа).
     * Потребує стовпців subtasks_*, checklist_* з TaskChecklist::progressSelect(); без них (або без підзадач і пунктів) повертає ''.
     * $bar = true додає тонку смужку сумарного прогресу (для карток).
     */
    public static function progress(array $t, bool $bar = false): string
    {
        $st = (int) ($t['subtasks_total'] ?? 0);
        $cl = (int) ($t['checklist_total'] ?? 0);
        if ($st + $cl === 0) {
            return '';
        }
        $html = '';
        if ($st > 0) {
            $html .= '<span class="badge bg-light text-dark border" title="Закрито підзадач: ' . (int) $t['subtasks_done'] . ' з ' . $st . '">⧉ ' . (int) $t['subtasks_done'] . '/' . $st . '</span> ';
        }
        if ($cl > 0) {
            $html .= '<span class="badge bg-light text-dark border" title="Виконано пунктів чек-листа: ' . (int) $t['checklist_done'] . ' з ' . $cl . '">☑ ' . (int) $t['checklist_done'] . '/' . $cl . '</span> ';
        }
        if ($bar) {
            $pct = (int) round(((int) $t['subtasks_done'] + (int) $t['checklist_done']) / ($st + $cl) * 100);
            $html .= '<div class="progress mt-1" style="height:4px" role="progressbar" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-success" style="width:' . $pct . '%"></div></div>';
        }
        return $html;
    }

    /** Те саме простим текстом — для підписів у календарі й діаграмі Ганта: «☑ 2/5 ⧉ 1/3» (порожньо, якщо нічого немає). */
    public static function progressText(array $t): string
    {
        $parts = [];
        if (!empty($t['checklist_total'])) {
            $parts[] = '☑ ' . (int) $t['checklist_done'] . '/' . (int) $t['checklist_total'];
        }
        if (!empty($t['subtasks_total'])) {
            $parts[] = '⧉ ' . (int) $t['subtasks_done'] . '/' . (int) $t['subtasks_total'];
        }
        return implode(' ', $parts);
    }

    /** Мітка «вставити зменшене зображення вкладення сюди»: [[img:123]]. Ставить обробка пошти; у тексті відображається мініатюрою. */
    public const IMAGE_TOKEN = '/\[\[img:(\d+)\]\]/';

    /**
     * Текст тікета/коментаря з мініатюрами: екранує, переносить рядки й замінює мітки [[img:N]] на зменшені зображення
     * (клік — повний розмір). Показуються лише вкладення зі списку $attachments (тобто ті, що належать цьому тікету й
     * доступні цьому глядачу); мітка на чуже, видалене чи не-зображення просто зникає.
     *
     * @param array<int, array<string, mixed>> $attachments
     * @param callable(int): string $urlFor адреса віддачі вкладення за id (для співробітників і для порталу вона різна)
     */
    public static function richText(?string $text, array $attachments, callable $urlFor): string
    {
        $images = [];
        foreach ($attachments as $a) {
            if (str_starts_with((string) $a['mime_type'], 'image/')) {
                $images[(int) $a['id']] = $a;
            }
        }
        $html = self::e($text);
        $html = preg_replace_callback(self::IMAGE_TOKEN, function (array $m) use ($images, $urlFor): string {
            $a = $images[(int) $m[1]] ?? null;
            if ($a === null) {
                return '';
            }
            $url = self::e($urlFor((int) $a['id']));
            return '<a href="' . $url . '" target="_blank" rel="noopener" class="d-inline-block me-2 mb-2"><img src="' . $url . '?thumb=1" alt="'
                . self::e($a['original_name']) . '" class="attachment-thumb" loading="lazy"></a>';
        }, $html) ?? $html;
        return nl2br($html);
    }

    /** Текст без міток зображень — для API, листів та експорту. */
    public static function withoutImageTokens(?string $text): string
    {
        return rtrim((string) preg_replace(self::IMAGE_TOKEN, '', (string) $text));
    }

    /** Екранування виводу для захисту від XSS */
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}
