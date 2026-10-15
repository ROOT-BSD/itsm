<?php
// Автономний конвертер Office → PDF (DOCX, XLSX) без LibreOffice, БД і веб-сервера — лише PHP 8.1+ (mbstring, dom).
//
//   php bin/office-to-pdf.php документ.docx              # створить документ.pdf поряд
//   php bin/office-to-pdf.php таблиця.xlsx вихід.pdf     # явна назва результату
//   php bin/office-to-pdf.php --dir вхідна_папка вихідна_папка   # усі .docx/.xlsx з папки
//
// Файл можна копіювати окремо разом із каталогами app/Services/Office, app/Vendor/tfpdf і app/autoload.php.
// Код виходу: 0 — усе конвертовано, 1 — були помилки, 2 — неправильні аргументи.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

use App\Services\Office\OfficeException;
use App\Services\Office\OfficeToPdf;

$args = array_slice($argv, 1);
if (!$args || in_array($args[0], ['-h', '--help'], true)) {
    fwrite(STDOUT, "Використання:\n  php bin/office-to-pdf.php файл.docx|файл.xlsx [вихід.pdf]\n  php bin/office-to-pdf.php --dir вхідна_папка вихідна_папка\n");
    exit($args ? 0 : 2);
}

$jobs = [];
if ($args[0] === '--dir') {
    if (count($args) !== 3 || !is_dir($args[1])) {
        fwrite(STDERR, "Вкажіть існуючу вхідну папку й вихідну папку.\n");
        exit(2);
    }
    if (!is_dir($args[2]) && !@mkdir($args[2], 0775, true)) {
        fwrite(STDERR, "Не вдалося створити вихідну папку.\n");
        exit(2);
    }
    $used = [];
    foreach (scandir($args[1]) ?: [] as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (is_file($args[1] . '/' . $f) && OfficeToPdf::supports($ext)) {
            $base = pathinfo($f, PATHINFO_FILENAME);
            // doc.docx і doc.xlsx не повинні затерти один одного: другий отримає назву doc-xlsx.pdf.
            $name = isset($used[strtolower($base)]) ? $base . '-' . $ext : $base;
            $used[strtolower($base)] = true;
            $jobs[] = [rtrim($args[1], '/') . '/' . $f, rtrim($args[2], '/') . '/' . $name . '.pdf'];
        }
    }
} else {
    $in = $args[0];
    $out = $args[1] ?? preg_replace('/\.[^.\/]+$/', '', $in) . '.pdf';
    $jobs[] = [$in, $out];
}

$failed = 0;
foreach ($jobs as [$in, $out]) {
    try {
        OfficeToPdf::convert($in, $out, pathinfo($in, PATHINFO_EXTENSION));
        fwrite(STDOUT, "OK   {$in} → {$out}\n");
    } catch (OfficeException $e) {
        $failed++;
        fwrite(STDERR, "FAIL {$in}: " . $e->getMessage() . "\n");
    }
}
exit($failed > 0 ? 1 : 0);
