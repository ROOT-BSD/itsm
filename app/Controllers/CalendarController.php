<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Task;

class CalendarController
{
    public function index(): void
    {
        Auth::requireLogin();

        $year = (int) ($_GET['year'] ?? date('Y'));
        $month = (int) ($_GET['month'] ?? date('n'));

        // Захист від некоректних параметрів у URL (наприклад, ?month=99)
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 1970 || $year > 2100) {
            $year = (int) date('Y');
        }

        $firstOfMonth = sprintf('%04d-%02d-01', $year, $month);
        $daysInMonth = (int) date('t', strtotime($firstOfMonth));
        $lastOfMonth = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        $tasks = Task::spanningVisibleTo($firstOfMonth, $lastOfMonth, Auth::id(), Auth::hasRole(['admin']));

        // Задача розміщується ЛИШЕ у день початку та в день завершення —
        // проміжні дні діапазону не заповнюються (за явним запитом:
        // "проміжні діапазони не показувати, тільки початок та кінець").
        $tasksByDay = [];
        foreach ($tasks as $task) {
            $effectiveStart = $task['start_date'] ?: $task['due_date'];
            $effectiveEnd = $task['due_date'] ?: $task['start_date'];
            $isSingleDay = ($effectiveStart === $effectiveEnd);

            $startVisibleThisMonth = ($effectiveStart >= $firstOfMonth && $effectiveStart <= $lastOfMonth);
            $endVisibleThisMonth = ($effectiveEnd >= $firstOfMonth && $effectiveEnd <= $lastOfMonth);

            if ($startVisibleThisMonth) {
                $day = (int) date('j', strtotime($effectiveStart));
                $tasksByDay[$day][] = $task + ['is_range_start' => true, 'is_range_end' => $isSingleDay];
            }
            // Для одноденної задачі другий запис не потрібен — це той самий день.
            if (!$isSingleDay && $endVisibleThisMonth) {
                $day = (int) date('j', strtotime($effectiveEnd));
                $tasksByDay[$day][] = $task + ['is_range_start' => false, 'is_range_end' => true];
            }
        }

        // Понеділок = 1 ... неділя = 7 (українська сітка тижня)
        $firstWeekday = (int) date('N', strtotime($firstOfMonth));

        // Дані для посилань "попередній/наступний місяць"
        $prevMonth = $month === 1 ? 12 : $month - 1;
        $prevYear = $month === 1 ? $year - 1 : $year;
        $nextMonth = $month === 12 ? 1 : $month + 1;
        $nextYear = $month === 12 ? $year + 1 : $year;

        $monthNames = [
            1 => 'Січень', 2 => 'Лютий', 3 => 'Березень', 4 => 'Квітень',
            5 => 'Травень', 6 => 'Червень', 7 => 'Липень', 8 => 'Серпень',
            9 => 'Вересень', 10 => 'Жовтень', 11 => 'Листопад', 12 => 'Грудень',
        ];

        View::render('calendar/index', [
            'year' => $year,
            'month' => $month,
            'monthName' => $monthNames[$month],
            'daysInMonth' => $daysInMonth,
            'firstWeekday' => $firstWeekday,
            'tasksByDay' => $tasksByDay,
            'prevMonth' => $prevMonth,
            'prevYear' => $prevYear,
            'nextMonth' => $nextMonth,
            'nextYear' => $nextYear,
            'todayDay' => ((int) date('Y') === $year && (int) date('n') === $month) ? (int) date('j') : null,
        ]);
    }
}
