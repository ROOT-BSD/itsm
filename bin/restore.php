<?php
// Розшифровка, перевірка й відновлення з резервної копії (створеної bin/backup.php або з адмін-панелі).
//
//   php bin/restore.php <файл.tar.gz[.enc]> --extract=<тека>            розшифрувати, перевірити, розпакувати в теку
//   php bin/restore.php <файл.tar.gz[.enc]> --apply --yes [--extract=<тека>] [--no-safety-backup]
//                                                                              розпакувати й ВІДНОВИТИ БД та файли у цей застосунок
//                                                                              (без --extract — у тимчасову теку, яку буде видалено)
//
// Пароль шифрування береться зі змінної BACKUP_PASSWORD (.env або середовище); для копії з іншого сервера задайте її
// в середовищі: BACKUP_PASSWORD='…' php bin/restore.php …  Без --apply нічого в системі не змінюється.
// --apply ПЕРЕЗАПИСУЄ поточну базу даних (з .env) і файли storage/uploads та storage/wiki: спершу зробіть свіжу копію
// (скрипт робить її сам перед відновленням, якщо не вказано --no-safety-backup) і переконайтесь, що це саме той сервер. Потрібні утиліти tar і mysql (клієнт). Файл .env копія не містить.
// Код виходу: 0 — успіх, 1 — помилка.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

use App\Services\BackupService;

$out = static function (string $m): void { echo $m . "\n"; };
$die = static function (string $m): never { fwrite(STDERR, "Помилка: $m\n"); exit(1); };

$file = null;
$extract = null;
$apply = false;
$yes = false;
$safety = true;
foreach (array_slice($argv ?? [], 1) as $a) {
    if (str_starts_with($a, '--extract=')) {
        $extract = substr($a, 10);
    } elseif ($a === '--apply') {
        $apply = true;
    } elseif ($a === '--no-safety-backup') {
        $safety = false;
    } elseif ($a === '--yes') {
        $yes = true;
    } elseif ($file === null && !str_starts_with($a, '--')) {
        $file = $a;
    } else {
        $die("Невідомий аргумент: $a");
    }
}
if ($file === null || ($extract === null && !$apply)) {
    $die('Використання: php bin/restore.php <файл> --extract=<тека> | --apply --yes [--extract=<тека>] [--no-safety-backup]');
}
if (!is_file($file)) {
    $die("Файл не знайдено: $file");
}
if ($apply && !$yes) {
    $die('--apply перезаписує базу й файли: додайте --yes, якщо ви впевнені.');
}

try {
    $r = BackupService::restore($file, ['extract' => $extract, 'apply' => $apply, 'safety' => $safety], $out);
} catch (\RuntimeException $e) {
    $die($e->getMessage());
}
if (!$apply) {
    $out('Нічого не змінено в системі. Для відновлення додайте --apply --yes або дотримуйтесь RESTORE.txt.');
    exit(0);
}
$out('Готово. Виконайте sudo bash update.sh (права, схема) і перевірте сторінку «Стан системи».');
exit(0);
