<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\PdfReport;
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
            'selectedProjectId' => !empty($_GET['project_id']) ? (int) $_GET['project_id'] : null,
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
        }

        $userLabel = 'усі користувачі';
        if ($logUserId !== null) {
            $logUser = User::findById($logUserId);
            $userLabel = $logUser['full_name'] ?? ('#' . $logUserId);
        }

        $rows = Task::timeLogsFilteredReport(
            $dateFrom,
            $dateTo,
            ['project_id' => $projectId, 'log_user_id' => $logUserId, 'category' => $category],
            $viewerId,
            $isAdmin
        );

        $filterLines = [
            "Період: {$periodLabel} ({$dateFrom} — {$dateTo})",
            "Проєкт: {$projectLabel}",
            "Користувач: {$userLabel}",
        ];
        if ($category !== null) {
            $filterLines[] = "Категорія: {$category}";
        }
        $filterLines[] = 'Сформовано: ' . date('d.m.Y H:i');

        $pdf = new PdfReport('Звіт з обліку часу', $filterLines);

        if (empty($rows)) {
            $pdf->line('Немає записів обліку часу за обраними фільтрами.');
        } else {
            // --- Діаграма розподілу годин по користувачах (у шапці звіту) ---
            $hoursByUser = [];
            foreach ($rows as $r) {
                $name = $r['user_name'];
                $hoursByUser[$name] = ($hoursByUser[$name] ?? 0.0) + (float) $r['hours'];
            }
            arsort($hoursByUser);
            $chartData = [];
            foreach ($hoursByUser as $name => $hours) {
                $chartData[] = ['name' => $name, 'hours' => $hours];
            }
            $pdf->userChart($chartData);

            // --- Таблиця, згрупована по проєктах, з підсумком на кожен ---
            $columns = [
                ['header' => 'Дата', 'width' => 22],
                ['header' => 'Задача', 'width' => 60],
                ['header' => 'Користувач', 'width' => 40],
                ['header' => 'Категорія', 'width' => 33],
                ['header' => 'Годин', 'width' => 25],
            ];

            $groupsByProject = [];
            foreach ($rows as $r) {
                $pid = (int) $r['project_id'];
                if (!isset($groupsByProject[$pid])) {
                    $groupsByProject[$pid] = ['project_name' => $r['project_name'], 'rows' => [], 'subtotal' => 0.0];
                }
                $groupsByProject[$pid]['rows'][] = [
                    $r['log_date'],
                    mb_strimwidth($r['task_title'], 0, 30, '…'),
                    mb_strimwidth($r['user_name'], 0, 20, '…'),
                    mb_strimwidth($r['activity_category'] ?? '—', 0, 16, '…'),
                    number_format((float) $r['hours'], 2),
                ];
                $groupsByProject[$pid]['subtotal'] += (float) $r['hours'];
            }
            // Проєкти з найбільшими витратами часу — першими.
            uasort($groupsByProject, fn($a, $b) => $b['subtotal'] <=> $a['subtotal']);

            $pdf->groupedTable($columns, array_values($groupsByProject));

            $totalHours = array_sum(array_column($rows, 'hours'));
            $pdf->heading('Разом: ' . number_format($totalHours, 2) . ' год.');
        }

        $filename = 'report_' . date('Ymd_His') . '.pdf';
        $pdf->download($filename);
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
