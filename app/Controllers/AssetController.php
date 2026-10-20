<?php

namespace App\Controllers;

use App\Core\Access;
use App\Core\Auth;
use App\Core\View;
use App\Models\Asset;
use App\Models\Ticket;
use App\Services\AssetImport;
use App\Services\TableExport;

/**
 * Реєстр обладнання й ПЗ: список з пошуком, картка активу (зв'язки, тікети, історія), створення/зміна, імпорт і експорт CSV/XLSX.
 * Доступ: адміністратор, менеджер ІТ, оператор; адміністратор підрозділу — активи свого підрозділу (див. Asset::scopeSql).
 */
class AssetController
{
    private const PAGE = 50;

    public function __construct()
    {
        Auth::requireLogin();
        if (!Access::canUseAssets(Auth::role())) {
            http_response_code(403);
            echo 'Реєстр обладнання доступний лише персоналу ІТ та адміністраторам.';
            exit;
        }
    }

    private function uid(): int
    {
        return (int) Auth::id();
    }

    private function filters(): array
    {
        return [
            'q' => trim((string) ($_GET['q'] ?? '')), 'type' => (string) ($_GET['type'] ?? ''), 'status' => (string) ($_GET['status'] ?? ''),
            'owner_id' => (int) ($_GET['owner_id'] ?? 0), 'warranty' => (string) ($_GET['warranty'] ?? ''),
        ];
    }

    private function notReady(): void
    {
        if (!Asset::ready()) {
            View::render('assets/index', ['ready' => false, 'assets' => [], 'f' => $this->filters(), 'total' => 0, 'page' => 1, 'pages' => 1,
                'owners' => [], 'error' => null, 'success' => null, 'canImport' => false]);
            exit;
        }
    }

    public function index(): void
    {
        $this->notReady();
        $f = $this->filters();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $total = Asset::count($f, Auth::role(), $this->uid());
        $pages = max(1, (int) ceil($total / self::PAGE));
        $page = min($page, $pages);
        View::render('assets/index', [
            'ready' => true, 'assets' => Asset::search($f, Auth::role(), $this->uid(), self::PAGE, ($page - 1) * self::PAGE),
            'f' => $f, 'total' => $total, 'page' => $page, 'pages' => $pages,
            'owners' => Asset::owners(Auth::role(), $this->uid()),
            'error' => $_GET['error'] ?? null, 'success' => $_GET['success'] ?? null, 'canImport' => true,
        ]);
    }

    private function visibleOrFail(int $id): array
    {
        $this->notReady();
        $a = Asset::find($id);
        if (!$a || !Asset::isVisible($a, Auth::role(), $this->uid())) {
            http_response_code(404);
            echo 'Актив не знайдено.';
            exit;
        }
        return $a;
    }

    public function show(array $params): void
    {
        $a = $this->visibleOrFail((int) $params['id']);
        $role = Auth::role();
        $uid = $this->uid();
        // Зв'язані активи й тікети показуємо лише ті, що поточному користувачеві доступні.
        $links = array_values(array_filter(Asset::links((int) $a['id']), function ($l) use ($role, $uid) {
            $o = Asset::find((int) $l['other_id']);
            return $o && Asset::isVisible($o, $role, $uid);
        }));
        $isAdmin = Auth::hasRole(['admin']);
        $canUnassigned = Access::canSeeUnassignedTickets($role);
        $tickets = array_values(array_filter(Asset::ticketsOf((int) $a['id']), function ($t) use ($uid, $isAdmin, $canUnassigned) {
            $full = Ticket::find((int) $t['id']);
            return $full && Ticket::isVisibleTo($full, $uid, $isAdmin, $canUnassigned);
        }));
        View::render('assets/show', [
            'a' => $a, 'links' => $links, 'tickets' => $tickets, 'history' => Asset::history((int) $a['id']),
            'canDelete' => Access::canDeleteAssets($role),
            'ownerNames' => $this->ownerNames(),
            'error' => $_GET['error'] ?? null, 'success' => $_GET['success'] ?? null,
        ]);
    }

    /** id => ім'я для показу «було/стало» власника в історії */
    private function ownerNames(): array
    {
        $out = [];
        foreach (\App\Core\Database::connection()->query('SELECT id, full_name FROM users')->fetchAll() as $u) {
            $out[(int) $u['id']] = $u['full_name'];
        }
        return $out;
    }

    public function form(array $params = []): void
    {
        $this->notReady();
        $a = null;
        if (!empty($params['id'])) {
            $a = $this->visibleOrFail((int) $params['id']);
        }
        View::render('assets/form', [
            'a' => $a, 'owners' => Asset::owners(Auth::role(), $this->uid()),
            'error' => $_GET['error'] ?? null,
            'old' => $_SESSION['asset_old'] ?? null,
        ]);
        unset($_SESSION['asset_old']);
    }

    public function save(array $params = []): void
    {
        $this->notReady();
        $id = !empty($params['id']) ? (int) $params['id'] : null;
        $existing = $id !== null ? $this->visibleOrFail($id) : null;
        $role = Auth::role();
        $d = [
            'asset_type' => (string) ($_POST['asset_type'] ?? 'other'), 'name' => trim((string) ($_POST['name'] ?? '')),
            'inventory_number' => trim((string) ($_POST['inventory_number'] ?? '')), 'serial_number' => trim((string) ($_POST['serial_number'] ?? '')),
            'manufacturer' => trim((string) ($_POST['manufacturer'] ?? '')), 'model' => trim((string) ($_POST['model'] ?? '')),
            'status' => (string) ($_POST['status'] ?? 'in_use'), 'owner_user_id' => (int) ($_POST['owner_user_id'] ?? 0) ?: null,
            'location' => trim((string) ($_POST['location'] ?? '')), 'network_id' => trim((string) ($_POST['network_id'] ?? '')),
            'purchase_date' => trim((string) ($_POST['purchase_date'] ?? '')), 'warranty_until' => trim((string) ($_POST['warranty_until'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ];
        $fail = function (string $msg) use ($id, $d): never {
            $_SESSION['asset_old'] = $d;
            header('Location: ' . ($id ? "/it-assets/{$id}/edit" : '/it-assets/create') . '?error=' . urlencode($msg));
            exit;
        };
        if ($d['name'] === '' || mb_strlen($d['name']) > 200) {
            $fail('Вкажіть назву (до 200 символів).');
        }
        if (!isset(Asset::TYPES[$d['asset_type']]) || !isset(Asset::STATUSES[$d['status']])) {
            $fail('Некоректний тип чи статус.');
        }
        foreach (['inventory_number' => 80, 'serial_number' => 120, 'manufacturer' => 120, 'model' => 120, 'location' => 200, 'network_id' => 120] as $k => $len) {
            if (mb_strlen($d[$k]) > $len) {
                $fail('Поле «' . Asset::FIELD_LABELS[$k] . "» задовге (до {$len} символів).");
            }
        }
        foreach (['purchase_date', 'warranty_until'] as $k) {
            $iso = AssetImport::date($d[$k]);
            if ($iso === false || ($d[$k] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d[$k]))) {
                $fail('Некоректна дата у полі «' . Asset::FIELD_LABELS[$k] . '».');
            }
            $d[$k] = $iso;
        }
        if ($d['purchase_date'] && $d['warranty_until'] && $d['warranty_until'] < $d['purchase_date']) {
            $fail('Гарантія не може закінчуватись раніше дати придбання.');
        }
        if ($d['owner_user_id'] !== null && !in_array($d['owner_user_id'], array_map(fn($u) => (int) $u['id'], Asset::owners($role, $this->uid())), true)) {
            $fail('Цього власника обрати не можна.');
        }
        if ($d['inventory_number'] !== '') {
            $dup = Asset::findByInventory($d['inventory_number']);
            if ($dup && (int) $dup['id'] !== (int) $id) {
                $fail('Інвентарний № «' . $d['inventory_number'] . '» уже використовується (актив №' . (int) $dup['id'] . ').');
            }
        }
        if ($id === null) {
            $newId = Asset::create($d, $this->uid());
            header('Location: /it-assets/' . $newId . '?success=' . urlencode('Актив створено.'));
        } else {
            $diff = Asset::update($id, $d, $this->uid());
            header('Location: /it-assets/' . $id . '?success=' . urlencode($diff ? 'Зміни збережено.' : 'Змін немає.'));
        }
        exit;
    }

    public function delete(array $params): void
    {
        $a = $this->visibleOrFail((int) $params['id']);
        if (!Access::canDeleteAssets(Auth::role())) {
            header('Location: /it-assets/' . (int) $a['id'] . '?error=' . urlencode('Видаляти картки може лише адміністратор системи чи менеджер ІТ. Переведіть актив у статус «Списано».'));
            exit;
        }
        Asset::delete((int) $a['id'], $this->uid(), $a['name']);
        header('Location: /it-assets?success=' . urlencode('Актив «' . $a['name'] . '» видалено.'));
        exit;
    }

    /** Актив за введеним значенням: число — № картки, інакше — інвентарний №; лише доступний користувачеві. */
    private function resolveAsset(string $ref): ?array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }
        $a = null;
        if (ctype_digit($ref)) {
            $a = Asset::find((int) $ref);
        }
        $a ??= Asset::findByInventory($ref);
        return ($a && Asset::isVisible($a, Auth::role(), $this->uid())) ? $a : null;
    }

    public function addLink(array $params): void
    {
        $a = $this->visibleOrFail((int) $params['id']);
        $other = $this->resolveAsset((string) ($_POST['other'] ?? ''));
        $url = '/it-assets/' . (int) $a['id'];
        if (!$other) {
            $this->go($url, 'error', 'Актив для зв\'язку не знайдено (вкажіть № картки чи інвентарний №).');
        }
        if (!Asset::addLink((int) $a['id'], (int) $other['id'], (string) ($_POST['relation'] ?? ''), $this->uid())) {
            $this->go($url, 'error', 'Такий зв\'язок уже є або він неможливий (актив не можна зв\'язати із самим собою).');
        }
        $this->go($url, 'success', 'Зв\'язок додано.');
    }

    public function removeLink(array $params): void
    {
        $a = $this->visibleOrFail((int) $params['id']);
        Asset::removeLink((int) $a['id'], (int) $params['link'], $this->uid());
        $this->go('/it-assets/' . (int) $a['id'], 'success', 'Зв\'язок прибрано.');
    }

    private function visibleTicket(int $id): ?array
    {
        $t = Ticket::find($id);
        return ($t && Ticket::isVisibleTo($t, $this->uid(), Auth::hasRole(['admin']), Access::canSeeUnassignedTickets(Auth::role()))) ? $t : null;
    }

    /** З картки активу: прив'язати тікет за його номером. */
    public function addTicket(array $params): void
    {
        $a = $this->visibleOrFail((int) $params['id']);
        $t = $this->visibleTicket((int) ($_POST['ticket_id'] ?? 0));
        $url = '/it-assets/' . (int) $a['id'];
        if (!$t) {
            $this->go($url, 'error', 'Тікет не знайдено або він вам недоступний.');
        }
        Asset::linkTicket((int) $a['id'], (int) $t['id'], $this->uid());
        $this->go($url, 'success', 'Тікет №' . (int) $t['id'] . ' прив\'язано.');
    }

    public function removeTicket(array $params): void
    {
        $a = $this->visibleOrFail((int) $params['id']);
        Asset::unlinkTicket((int) $a['id'], (int) $params['ticket'], $this->uid());
        $this->go('/it-assets/' . (int) $a['id'], 'success', 'Прив\'язку тікета прибрано.');
    }

    /** З тікета: прив'язати актив за № картки чи інвентарним №. */
    public function ticketLink(array $params): void
    {
        $t = $this->visibleTicket((int) $params['id']);
        if (!$t) {
            http_response_code(404);
            echo 'Тікет не знайдено.';
            return;
        }
        $this->notReady();
        $url = '/tickets/' . (int) $t['id'];
        $a = $this->resolveAsset((string) ($_POST['asset'] ?? ''));
        if (!$a) {
            $this->go($url, 'error', 'Актив не знайдено (вкажіть № картки чи інвентарний №).');
        }
        if (!Asset::linkTicket((int) $a['id'], (int) $t['id'], $this->uid())) {
            $this->go($url, 'error', 'Цей актив уже прив\'язано до тікета.');
        }
        $this->go($url, 'success', 'Актив «' . $a['name'] . '» прив\'язано.');
    }

    public function ticketUnlink(array $params): void
    {
        $t = $this->visibleTicket((int) $params['id']);
        if (!$t) {
            http_response_code(404);
            echo 'Тікет не знайдено.';
            return;
        }
        $this->notReady();
        Asset::unlinkTicket((int) $params['asset'], (int) $t['id'], $this->uid());
        $this->go('/tickets/' . (int) $t['id'], 'success', 'Прив\'язку активу прибрано.');
    }

    public function export(): void
    {
        $this->notReady();
        $assets = Asset::search($this->filters(), Auth::role(), $this->uid(), TableExport::MAX_ROWS, 0);
        \App\Models\Audit::log('asset', 0, 'exported', $this->uid(), ['rows' => count($assets)]);
        TableExport::send('assets-' . date('Y-m-d'), $_GET['format'] ?? '', AssetImport::HEADERS, AssetImport::exportRows($assets), 'Активи');
    }

    /** Шаблон для імпорту: заголовки й один приклад. */
    public function template(): void
    {
        $example = [['Ноутбук', 'Ноутбук бухгалтера', 'INV-0001', 'SN123456', 'Dell', 'Latitude 5440', 'Використовується', 'user@example.com', 'каб. 12', 'PC-BUH-01', '2025-03-01', '2028-03-01', '']];
        TableExport::send('assets-template', $_GET['format'] ?? 'xlsx', AssetImport::HEADERS, $example, 'Активи');
    }

    public function templateSoftware(): void
    {
        TableExport::send('software-template', $_GET['format'] ?? 'xlsx', AssetImport::HEADERS_SW, AssetImport::softwareExample(), 'ПЗ');
    }

    public function importForm(): void
    {
        $this->notReady();
        View::render('assets/import', ['result' => $_SESSION['asset_import'] ?? null, 'error' => $_GET['error'] ?? null]);
        unset($_SESSION['asset_import']);
    }

    public function import(): void
    {
        $this->notReady();
        @set_time_limit(120);
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->go('/it-assets/import', 'error', 'Оберіть файл .xlsx або .csv (або файл завеликий для завантаження).');
        }
        if (($f['size'] ?? 0) > 10 * 1024 * 1024) {
            $this->go('/it-assets/import', 'error', 'Файл завеликий (до 10 МБ).');
        }
        try {
            $table = AssetImport::readFile($f['tmp_name'], basename((string) $f['name']));
            $sw = ($_POST['kind'] ?? '') === 'software';
            $r = AssetImport::import($table, Auth::role(), $this->uid(), $sw);
        } catch (\RuntimeException $e) {
            $this->go('/it-assets/import', 'error', $e->getMessage());
        }
        \App\Models\Audit::log('asset', 0, $sw ? 'imported_software' : 'imported', $this->uid(), ['created' => $r['created'], 'updated' => $r['updated'], 'errors' => count($r['errors'])]);
        $_SESSION['asset_import'] = $r + ['file' => basename((string) $f['name'])];
        header('Location: /it-assets/import');
        exit;
    }

    private function go(string $url, string $key, string $msg): never
    {
        header('Location: ' . $url . '?' . $key . '=' . urlencode($msg));
        exit;
    }
}
