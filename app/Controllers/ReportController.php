<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class ReportController
{
    public function index(): void
    {
        Auth::requireLogin();

        View::render('reports/index', [
            'projects' => Project::allVisibleTo(Auth::id(), Auth::hasRole(['admin'])),
            'users' => User::allActive(),
            'categories' => Task::distinctCategoriesVisibleTo(Auth::id(), Auth::hasRole(['admin'])),
            'error' => $_GET['error'] ?? null,
            'canTicketReport' => Auth::hasRole(['admin']) || \App\Core\Access::isUnitAdmin(Auth::role()),
            'selectedProjectId' => !empty($_GET['project_id']) ? (int) $_GET['project_id'] : null,
            'currentYear' => (int) date('Y'),
            'currentWeek' => (int) date('W'),
            'currentMonth' => (int) date('n'),
        ]);
    }

    public function generatePdf(): void
    {
        Auth::requireLogin();
        $isAdmin = Auth::hasRole(['admin']);
        $viewerId = Auth::id();

        [$dateFrom, $dateTo, $periodLabel, $periodError] = $this->resolvePeriod(
            $_GET['period_type'] ?? '',
            $_GET['week'] ?? '',
            $_GET['month'] ?? ''
        );
        if ($periodError !== null) {
            header('Location: /reports?error=' . urlencode($periodError));
            exit;
        }

        // «Звіт по тікетах» — той самий звіт, що й у «Керуванні» (адміністратор системи — усі тікети, адміністратор підрозділу — тікети підрозділу).
        if (($_GET['report_type'] ?? 'time') === 'tickets') {
            $isUnit = \App\Core\Access::isUnitAdmin(Auth::role());
            if (!$isAdmin && !$isUnit) {
                header('Location: /reports?error=' . urlencode('Звіт по тікетах доступний адміністраторам системи й підрозділу.'));
                exit;
            }
            $_GET['date_from'] = $dateFrom;
            $_GET['date_to'] = $dateTo;
            if (!in_array($_GET['format'] ?? '', ['pdf', 'xlsx', 'csv'], true)) {
                $_GET['format'] = 'pdf';
            }
            (new ExportController())->sendTicketsReport($isAdmin ? null : (int) $viewerId);
            exit;
        }

        $projectId = !empty($_GET['project_id']) ? (int) $_GET['project_id'] : null;
        $logUserId = !empty($_GET['user_id']) ? (int) $_GET['user_id'] : null;
        $category = trim($_GET['category'] ?? '') ?: null;

        // Якщо вказано конкретний проєкт — перевіряємо, що він видимий цьому користувачу,
        // інакше звіт міг би підтвердити існування чужого проєкту через порожній/непорожній результат.
        $projectLabel = 'усі доступні проєкти';
        if ($projectId !== null) {
            $project = Project::find($projectId);
            if (!$project || !Project::isVisibleTo($project, $viewerId, $isAdmin)) {
                header('Location: /reports?error=' . urlencode('Проєкт не знайдено або немає доступу'));
                exit;
            }
            $projectLabel = $project['name'];
            if (!empty(Project::subProjectsOf($projectId))) {
                $projectLabel .= ' (разом з підпроєктами)';
            }
        }

        $userLabel = 'усі користувачі';
        if ($logUserId !== null) {
            $logUser = User::findById($logUserId);
            $userLabel = $logUser['full_name'] ?? ('#' . $logUserId);
        }

        $filters = ['project_id' => $projectId, 'log_user_id' => $logUserId, 'category' => $category];
        $taskRows = Task::timeLogsFilteredReport($dateFrom, $dateTo, $filters, $viewerId, $isAdmin);
        $withTickets = ($_GET['include_tickets'] ?? '1') !== '0';
        $rows = \App\Services\ReportScheduleService::merge(
            $taskRows,
            $withTickets ? \App\Models\TicketTimeLog::reportRows($dateFrom, $dateTo, $filters, (int) $viewerId, Auth::role()) : []
        );

        $filterLines = [
            "Період: {$periodLabel} ({$dateFrom} — {$dateTo})",
            "Проєкт: {$projectLabel}",
            "Користувач: {$userLabel}",
        ];
        if ($category !== null) {
            $filterLines[] = "Категорія: {$category}";
        }
        $filterLines[] = 'Час по тікетах: ' . ($withTickets ? 'враховано' : 'не враховано');
        $filterLines[] = 'Сформовано: ' . date('d.m.Y H:i');

        $format = in_array($_GET['format'] ?? 'pdf', ['pdf', 'xlsx', 'csv'], true) ? (string) ($_GET['format'] ?? 'pdf') : 'pdf';
        $file = \App\Services\ReportScheduleService::file($format, $rows, $filterLines, 'report_' . date('Ymd_His'));
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $file['mime']);
        header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
        header('Content-Length: ' . strlen($file['data']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $file['data'];
        exit;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string} [дата-від, дата-до, підпис періоду, помилка]
     */
    private function resolvePeriod(string $periodType, string $week, string $month): array
    {
        if ($periodType === 'week') {
            if (!preg_match('/^(\d{4})-W(\d{2})$/', $week, $m)) {
                return [null, null, null, 'Оберіть коректний тиждень'];
            }
            $year = (int) $m[1];
            $weekNum = (int) $m[2];
            if ($weekNum < 1 || $weekNum > 53) {
                return [null, null, null, 'Некоректний номер тижня'];
            }
            try {
                $start = new \DateTime();
                $start->setISODate($year, $weekNum, 1);
                $end = (clone $start)->modify('+6 days');
            } catch (\Throwable $e) {
                return [null, null, null, 'Некоректний тиждень'];
            }
            return [$start->format('Y-m-d'), $end->format('Y-m-d'), "тиждень {$week}", null];
        }

        if ($periodType === 'month') {
            if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
                return [null, null, null, 'Оберіть коректний місяць'];
            }
            $monthNum = (int) $m[2];
            if ($monthNum < 1 || $monthNum > 12) {
                return [null, null, null, 'Некоректний місяць'];
            }
            $dateFrom = "{$m[1]}-{$m[2]}-01";
            $dateTo = date('Y-m-t', strtotime($dateFrom));
            return [$dateFrom, $dateTo, "місяць {$month}", null];
        }

        return [null, null, null, 'Оберіть тип періоду — тиждень чи місяць'];
    }
}
