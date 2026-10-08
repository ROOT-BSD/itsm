<?php
// Імпорт гайдів з docs/*.md у вікі: гайд користувача (бачать усі), гайд адміністратора (лише admin) і гайд з інтеграції REST API
// та вебхуків (персонал: усі, крім заявників).
//
// Запуск (install.sh і update.sh роблять це самі; вручну — за потреби):
//
//   php bin/import-wiki-docs.php            # безпечний імпорт
//   php bin/import-wiki-docs.php --force    # перезаписати навіть сторінки, які редагували вручну
//
// Правила, щоб імпорт НІКОЛИ не затирав чужу роботу:
//   • сторінки ще немає → створюється;
//   • сторінка є, текст такий самий, як при минулому імпорті (збігається хеш) → її ніхто не правив,
//     тож вона оновлюється до нової версії документації (якщо та змінилась);
//   • сторінку відредагували у вікі (хеш не збігається) → пропускається; --force перезаписує її новою версією
//     (стара лишається в історії версій);
//   • адреса зайнята сторінкою, створеною вручну (не з імпорту), → пропускається.
// Видимість і порядок у списку задаються лише при створенні — адміністратор може змінити їх у вікі, і імпорт цього не скасує.
//
// Код виходу: 0 — успіх (навіть якщо щось пропущено), 1 — помилка (немає файлу, збій БД).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

use App\Models\WikiPage;

$force = in_array('--force', $argv, true);
$root = dirname(__DIR__);
$say = static fn(string $msg) => print('[' . date('Y-m-d H:i:s') . '] [itsm-wiki-import] ' . $msg . "\n");

$sources = [
    ['file' => 'docs/USER_GUIDE.md', 'slug' => 'user-guide', 'visibility' => 'all', 'sort' => 10],
    ['file' => 'docs/ADMIN_GUIDE.md', 'slug' => 'admin-guide', 'visibility' => 'admin', 'sort' => 20],
    ['file' => 'docs/API_GUIDE.md', 'slug' => 'api-guide', 'visibility' => 'staff', 'sort' => 30],
];

$failed = false;
try {
    foreach ($sources as $src) {
        $path = $root . '/' . $src['file'];
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            $say("ПОМИЛКА: не знайдено {$src['file']}");
            $failed = true;
            continue;
        }

        // Назва сторінки — перший заголовок «# …»; сам заголовок із тексту прибираємо (вікі показує назву окремо).
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        if (!preg_match('/\A\s*#[ ]+(.+?)[ ]*\n/u', $raw, $m)) {
            $say("ПОМИЛКА: у {$src['file']} немає заголовка першого рівня «# …»");
            $failed = true;
            continue;
        }
        $title = trim($m[1]);
        $content = rtrim(ltrim(substr($raw, strlen($m[0])), "\n")) . "\n";
        $hash = sha1($content);

        $page = WikiPage::findBySlug($src['slug']);
        if ($page === null) {
            WikiPage::create($src['slug'], $title, $content, $src['visibility'], $src['sort'], null, 'docs', $hash);
            $say("створено: /wiki/{$src['slug']} — «{$title}» ({$src['file']})");
            continue;
        }
        if (($page['source'] ?? null) !== 'docs') {
            $say("пропущено: /wiki/{$src['slug']} — адресу зайнято сторінкою, створеною вручну");
            continue;
        }

        $untouched = $page['imported_hash'] !== null && hash_equals((string) $page['imported_hash'], sha1($page['content']));
        if ($page['imported_hash'] === $hash && $untouched) {
            $say("без змін: /wiki/{$src['slug']}");
            continue;
        }
        if (!$untouched && !$force) {
            $say("пропущено: /wiki/{$src['slug']} — сторінку відредаговано у вікі (щоб перезаписати новою версією документації: --force)");
            continue;
        }

        // Назву й видимість лишаємо як є: їх могли змінити свідомо, а імпорт оновлює лише текст.
        $ok = WikiPage::update((int) $page['id'], (int) $page['version'], $page['title'], $content, $page['visibility'], (int) $page['sort_order'], null, $hash, true);
        $say($ok ? "оновлено: /wiki/{$src['slug']} → версія " . ((int) $page['version'] + 1) . ($untouched ? '' : ' (--force: ручні правки лишились в історії)')
                 : "пропущено: /wiki/{$src['slug']} — сторінку змінили під час імпорту, повторіть запуск");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '[itsm-wiki-import] Неочікувана помилка: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}

exit($failed ? 1 : 0);
