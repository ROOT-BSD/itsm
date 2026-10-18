<?php

namespace App\Controllers;

use App\Core\Access;
use App\Core\Auth;
use App\Models\Audit;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TableExport;

/**
 * Експорт списків у CSV/XLSX (`?format=csv|xlsx`, за замовчуванням xlsx).
 *
 * Права не розширюються: кожен список будується тими самими методами моделей, що й сторінка,
 * з якої натискають «Експорт», тож у файл потрапляє рівно те, що користувач бачить на екрані
 * (тікети й задачі — за його доступом; журнал аудиту — адміністратору системи все,
 * адміністратору підрозділу лише записи свого підрозділу).
 */
class ExportController
{
    public function tickets(): void
    {
        Auth::requireLogin();
        $closed = ($_GET['status'] ?? '') === 'closed';
        $isAdmin = Auth::hasRole(['admin']);
        $unassigned = Access::canSeeUnassignedTickets(Auth::role());
        $list = $closed
            ? Ticket::allClosedVisibleTo(Auth::id(), $isAdmin, $unassigned)
            : Ticket::allOpenVisibleTo(Auth::id(), $isAdmin, $unassigned);
        if (!$closed && !empty($_GET['queue'])) {
            $queueId = (int) $_GET['queue'];
            $list = array_values(array_filter($list, fn(array $t): bool => (int) $t['queue_id'] === $queueId));
        }
        $policies = Ticket::slaPoliciesByQueue();

        $rows = [];
        foreach ($list as $t) {
            $sla = Ticket::slaStatus($t, $policies);
            $rows[] = [
                (int) $t['id'], $t['subject'], $t['queue_name'], $t['project_name'] ?? '',
                $t['requester_name'], $t['operator_name'] ?? '', \App\Models\Ticket::statusLabel((string) $t['status']),
                !$sla['has_policy'] ? '' : (($sla['response_overdue'] || $sla['resolution_overdue']) ? 'Прострочено' : 'У нормі'),
                $t['created_at'], $t['first_response_at'] ?? '', $t['resolved_at'] ?? '',
                $t['csat_score'] !== null ? (int) $t['csat_score'] : '',
            ];
        }
        TableExport::send($closed ? 'tickets-closed' : 'tickets', $_GET['format'] ?? '', [
            '№', 'Тема', 'Черга', 'Проєкт', 'Заявник', 'Оператор', 'Статус', 'SLA',
            'Створено', 'Перша відповідь', 'Вирішено', 'Оцінка CSAT',
        ], $rows, $closed ? 'Закриті тікети' : 'Тікети');
    }

    public function tasks(): void
    {
        Auth::requireLogin();
        $closed = ($_GET['status'] ?? '') === 'closed';
        $isAdmin = Auth::hasRole(['admin']);
        $list = $closed
            ? Task::allClosedVisibleTo(Auth::id(), $isAdmin)
            : Task::allOpenVisibleTo(Auth::id(), $isAdmin);

        $rows = [];
        foreach ($list as $t) {
            $rows[] = [
                (int) $t['id'], $t['title'], $t['project_name'], $t['type_name'], $t['status_name'],
                $t['priority'], $t['assignee_name'] ?? '', $t['start_date'] ?? '', $t['due_date'] ?? '',
                $t['estimated_hours'] !== null ? (float) $t['estimated_hours'] : '',
                $t['actual_hours'] !== null ? (float) $t['actual_hours'] : '',
                $t['created_at'],
            ];
        }
        TableExport::send($closed ? 'tasks-closed' : 'tasks', $_GET['format'] ?? '', [
            '№', 'Назва', 'Проєкт', 'Тип', 'Статус', 'Пріоритет', 'Виконавець',
            'Початок', 'Термін', 'Оцінка, год', 'Витрачено, год', 'Створено',
        ], $rows, $closed ? 'Закриті задачі' : 'Задачі');
    }

    /** Журнал аудиту: адміністратор системи. */
    public function audit(): void
    {
        Auth::requireLogin();
        if (!Auth::hasRole(['admin'])) {
            http_response_code(403);
            echo 'Експорт журналу аудиту дозволено лише ролі «Адміністратор системи».';
            exit;
        }
        $this->sendAudit([]);
    }

    /** Журнал аудиту підрозділу: адміністратор підрозділу — лише записи свого підрозділу. */
    /** Експорт «Звіту по тікетах» (XLSX/CSV) — список тікетів за період з часами реакції й вирішення. */
    public function ticketsReport(): void
    {
        Auth::requireLogin();
        if (!Auth::hasRole(['admin'])) {
            http_response_code(403);
            echo 'Доступно лише ролі «Адміністратор системи».';
            exit;
        }
        $this->sendTicketsReport(null);
    }

    public function unitTicketsReport(): void
    {
        Auth::requireLogin();
        if (!Access::isUnitAdmin(Auth::role())) {
            http_response_code(403);
            echo 'Ця сторінка доступна лише ролі «Адміністратор Підрозділу».';
            exit;
        }
        $this->sendTicketsReport((int) Auth::id());
    }

    /** Віддає «Звіт по тікетах» у форматі з ?format= (pdf|xlsx|csv); фільтри — з AdminController::ticketsReportFilters(). Доступ перевіряє викликач. */
    public function sendTicketsReport(?int $scope): void
    {
        $f = AdminController::ticketsReportFilters();
        $fmt = static fn(?float $m) => $m === null ? '' : round($m / 60, 2);
        $rows = [];
        $src = Ticket::workReportRows($f, $scope, TableExport::MAX_ROWS);
        if (($_GET['format'] ?? '') === 'pdf') {
            $this->sendTicketsReportPdf($f, $src, $scope !== null);
        }
        foreach ($src as $t) {
            $rows[] = [
                (int) $t['id'], $t['subject'], $t['queue_name'], $t['requester_name'], $t['operator_name'] ?? '',
                Ticket::statusLabel((string) $t['status']), $t['created_at'], $t['first_response_at'] ?? '', $t['resolved_at'] ?? '',
                $fmt($t['response_min'] !== null ? (float) $t['response_min'] : null), $fmt($t['resolution_min'] !== null ? (float) $t['resolution_min'] : null),
                round((float) $t['spent_hours'], 2),
            ];
        }
        TableExport::send('tickets-report-' . $f['date_from'] . '_' . $f['date_to'], $_GET['format'] ?? '', [
            '№', 'Тема', 'Черга', 'Заявник', 'Оператор', 'Статус', 'Створено', 'Перша відповідь', 'Вирішено',
            'До першої відповіді, год', 'До вирішення, год', 'Витрачено, год',
        ], $rows, 'Звіт по тікетах');
    }

    /** PDF «Звіту по тікетах»: підсумок, розбивки за чергами й операторами та список тікетів. */
    private function sendTicketsReportPdf(array $f, array $src, bool $unit): never
    {
        $d = \App\Services\TicketWorkReport::build($src);
        $dur = static fn(array $s) => $s['n'] > 0 ? \App\Services\TicketWorkReport::duration($s['avg']) . ' / ' . \App\Services\TicketWorkReport::duration($s['median']) : '—';
        $lines = ['Період створення: ' . $f['date_from'] . ' — ' . $f['date_to']];
        if ($unit) {
            $lines[] = 'Лише тікети підрозділу';
        }
        $lines[] = 'Час календарний (без урахування вихідних і робочих годин). Сформовано: ' . date('d.m.Y H:i');
        $pdf = new \App\Core\PdfReport('Звіт по тікетах', $lines);
        $s = $d['summary'];
        $pdf->line('Створено: ' . $s['total'] . '; вирішено/закрито: ' . $s['resolved'] . '; відкрито: ' . $s['open']);
        $pdf->line('Витрачено годин (облік часу в тікетах): ' . number_format((float) $s['hours'], 2));
        $pdf->line('До першої відповіді (середній / медіана): ' . $dur($s['response']));
        $pdf->line('До вирішення (середній / медіана): ' . $dur($s['resolution']));
        $cols = fn(string $first) => [
            ['header' => $first, 'width' => 38], ['header' => 'Створено', 'width' => 17], ['header' => 'Вирішено', 'width' => 17],
            ['header' => 'Відкрито', 'width' => 17], ['header' => 'Годин', 'width' => 14], ['header' => 'Перша відповідь', 'width' => 38], ['header' => 'Вирішення', 'width' => 38],
        ];
        $map = static fn(array $g) => array_map(static fn($r) => [$r['name'], $r['total'], $r['resolved'], $r['open'], number_format((float) $r['hours'], 2), $dur($r['response']), $dur($r['resolution'])], $g);
        $pdf->table('По чергах (середній / медіана)', $cols('Черга'), $map($d['byQueue']));
        $pdf->table('По операторах (середній / медіана)', $cols('Оператор'), $map($d['byOperator']));
        $rows = [];
        foreach ($src as $t) {
            $rows[] = ['#' . $t['id'], $t['subject'], $t['operator_name'] ?? '—', Ticket::statusLabel((string) $t['status']), $t['created_at'],
                \App\Services\TicketWorkReport::duration($t['response_min'] !== null ? (float) $t['response_min'] : null),
                \App\Services\TicketWorkReport::duration($t['resolution_min'] !== null ? (float) $t['resolution_min'] : null),
                number_format((float) $t['spent_hours'], 2)];
        }
        $pdf->table('Тікети за період (' . count($src) . ')', [
            ['header' => '№', 'width' => 11], ['header' => 'Тема', 'width' => 36], ['header' => 'Оператор', 'width' => 26], ['header' => 'Статус', 'width' => 20],
            ['header' => 'Створено', 'width' => 30], ['header' => 'До відповіді', 'width' => 20], ['header' => 'До вирішення', 'width' => 22], ['header' => 'Годин', 'width' => 15],
        ], $rows);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $pdf->download('tickets-report-' . $f['date_from'] . '_' . $f['date_to'] . '.pdf');
        exit;
    }

    public function unitAudit(): void
    {
        Auth::requireLogin();
        if (!Access::isUnitAdmin(Auth::role())) {
            http_response_code(403);
            echo 'Ця сторінка доступна лише ролі «Адміністратор Підрозділу».';
            exit;
        }
        $this->sendAudit(['unit_admin_id' => (int) Auth::id()]);
    }

    private function sendAudit(array $scope): void
    {
        $filters = $scope + [
            'entity_type' => $_GET['entity_type'] ?? '',
            'user_id' => !empty($_GET['user_id']) ? (int) $_GET['user_id'] : null,
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
        ];
        $entries = Audit::filtered($filters, TableExport::MAX_ROWS, 0);
        $names = Audit::entityNamesFor($entries);
        $refs = Audit::referencedNamesFor($entries);
        $relation = ['blocks' => 'блокує', 'blocked_by' => 'залежить від', 'duplicates' => 'дублює', 'related' => "пов'язана з"];

        $rows = [];
        foreach ($entries as $e) {
            $type = $e['entity_type'];
            $id = (int) $e['entity_id'];
            $details = [];
            $decoded = !empty($e['changes']) ? json_decode($e['changes'], true) : null;
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if ($value === null) {
                        $shown = '—';
                    } elseif ($key === 'relation_type') {
                        $shown = $relation[$value] ?? (string) $value;
                    } elseif (isset($refs[$key][(int) $value])) {
                        $shown = $refs[$key][(int) $value];
                    } else {
                        $shown = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                    $details[] = (Audit::CHANGE_KEY_LABELS[$key] ?? $key) . ': ' . $shown;
                }
            }
            $rows[] = [
                $e['created_at'], Audit::ENTITY_TYPE_LABELS[$type] ?? $type, $id,
                $names[$type][$id] ?? '', $e['action'], $e['user_name'] ?? '— система —', implode('; ', $details),
            ];
        }
        TableExport::send('audit', $_GET['format'] ?? '', [
            'Дата й час', 'Тип', '№ сутності', 'Назва', 'Дія', 'Хто', 'Деталі',
        ], $rows, 'Журнал аудиту');
    }
}
