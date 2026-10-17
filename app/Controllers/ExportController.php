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
