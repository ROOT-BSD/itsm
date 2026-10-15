<?php
// Застосовує міграції БД, яких ще немає в таблиці schema_migrations (див. app/Services/Migrator.php).
//
//   php bin/migrate.php              # застосувати все, чого бракує
//   php bin/migrate.php --status     # показати застосовані й незастосовані, нічого не змінюючи
//   php bin/migrate.php --dry-run    # показати, що буде застосовано
//   php bin/migrate.php --baseline   # позначити ВСІ наявні файли як застосовані, нічого не виконуючи (для ручного узгодження)
//
// Безпечний для повторного запуску. update.sh викликає цей скрипт сам.
// Код виходу: 0 — успіх, 1 — помилка міграції (подальші не застосовувались).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

use App\Services\Migrator;

$printer = static function (string $line): void { echo $line, "\n"; };
$m = new Migrator(null, null, $printer);

try {
    if (in_array('--status', $argv, true)) {
        $applied = $m->applied();
        foreach ($m->files() as $f) {
            $row = $applied[$f['version']] ?? null;
            printf("  %s  %-45s %s\n", $f['version'], $f['name'], $row ? 'застосована (' . $row['applied_via'] . ', ' . $row['applied_at'] . ')' : 'НЕ ЗАСТОСОВАНА');
        }
        exit(0);
    }
    $dry = in_array('--dry-run', $argv, true);
    $before = count($m->pending());
    if ($before === 0) {
        echo "  [ OK ] Усі міграції вже застосовано — схема БД актуальна\n";
        exit(0);
    }
    $n = $m->run($dry, in_array('--baseline', $argv, true));
    echo $dry ? "  [INFO] Режим перевірки: нічого не змінено\n" : "  [ OK ] Міграції оброблено (виконано: {$n})\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "  [FAIL] Помилка міграції: " . $e->getMessage() . "\n");
    exit(1);
}
