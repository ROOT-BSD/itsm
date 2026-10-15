<?php
// Перенесення тексту сторінок вікі та їхніх версій з БД у файли storage/wiki/<id>/ (current.md, rev-N.md).
//
//   php bin/migrate-wiki-to-files.php             # перенести й, після перевірки читанням, очистити текст у БД
//   php bin/migrate-wiki-to-files.php --keep-db   # перенести, але лишити копію тексту в БД
//
// Безпечний для повторного запуску: уже перенесені файли не перезаписуються, текст у БД очищається лише
// для тих записів, чий файл на диску побайтово збігається з ним. update.sh викликає цей скрипт сам.
// Код виходу: 0 — успіх, 1 — помилка запису на диск (нічого з БД при цьому не втрачено).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Лише з командного рядка.\n");
}

require __DIR__ . '/../app/autoload.php';

use App\Core\Database;
use App\Services\WikiStore;

$keepDb = in_array('--keep-db', $argv, true);
$pdo = Database::connection();
$pages = 0;
$revs = 0;
$cleared = 0;

try {
    foreach ($pdo->query('SELECT id, version, content FROM wiki_pages')->fetchAll() as $p) {
        $id = (int) $p['id'];
        // Поточний текст: файл уже є — не чіпаємо; інакше пишемо з БД.
        if (!WikiStore::hasCurrent($id) && (string) $p['content'] !== '') {
            WikiStore::write($id, 0, (string) $p['content']);
            $pages++;
        }
        if (!$keepDb && (string) $p['content'] !== '' && WikiStore::readCurrent($id) === (string) $p['content']) {
            $pdo->prepare("UPDATE wiki_pages SET content = '' WHERE id = :id")->execute(['id' => $id]);
            $cleared++;
        }
    }
    foreach ($pdo->query('SELECT id, page_id, version, content FROM wiki_revisions')->fetchAll() as $r) {
        $pageId = (int) $r['page_id'];
        $ver = (int) $r['version'];
        if (WikiStore::readRevision($pageId, $ver) === null && (string) $r['content'] !== '') {
            WikiStore::write($pageId, $ver, (string) $r['content'], false);
            $revs++;
        }
        if (!$keepDb && (string) $r['content'] !== '' && WikiStore::readRevision($pageId, $ver) === (string) $r['content']) {
            $pdo->prepare("UPDATE wiki_revisions SET content = '' WHERE id = :id")->execute(['id' => (int) $r['id']]);
            $cleared++;
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Помилка: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Перенесено на диск: сторінок {$pages}, версій {$revs}; очищено записів у БД: {$cleared}.\n";
exit(0);
