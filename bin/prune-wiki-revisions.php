<?php
// Обрізає історію всіх сторінок вікі до WikiPage::MAX_REVISIONS найновіших версій (записи в БД + файли rev-N.md).
//
//   php bin/prune-wiki-revisions.php
//
// Безпечний для повторного запуску. update.sh викликає цей скрипт сам; нові збереження сторінок обрізають історію автоматично.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

use App\Core\Database;
use App\Models\WikiPage;

$removed = 0;
$pages = 0;
foreach (Database::connection()->query('SELECT id FROM wiki_pages')->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $n = WikiPage::pruneRevisions((int) $id);
    if ($n > 0) {
        $pages++;
        $removed += $n;
    }
}
echo $removed > 0
    ? "Історія вікі: видалено старих версій — {$removed} (сторінок — {$pages}); лишено не більше " . WikiPage::MAX_REVISIONS . " на сторінку.\n"
    : "Історія вікі: у кожної сторінки не більше " . WikiPage::MAX_REVISIONS . " версій — нічого видаляти.\n";
