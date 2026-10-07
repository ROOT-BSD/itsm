<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Models\Project;
use App\Core\Database;
use App\Core\View;

class DashboardController
{
    public function index(): void
    {
        Auth::requireLogin();

        $db = Database::connection();
        $isAdmin = Auth::hasRole(['admin']);
        $uid = Auth::id();

        $myOpenTasks = $db->prepare(
            "SELECT t.id, t.title, t.priority, t.due_date, ts.name AS status_name, p.name AS project_name,
                    DATE_FORMAT(t.created_at, '%d.%m.%Y') AS created_at_formatted
             FROM tasks t
             JOIN task_statuses ts ON ts.id = t.status_id
             JOIN projects p ON p.id = t.project_id
             WHERE t.assignee_id = :uid AND ts.is_closed = 0
             ORDER BY t.due_date IS NULL, t.due_date ASC, p.name ASC
             LIMIT 20"
        );
        $myOpenTasks->execute(['uid' => $uid]);

        // Статистика рахується лише по проєктах/тікетах, видимих поточному
        // користувачу (адмін/автор/відповідальний) — щоб число на картці
        // збігалося з тим, що людина справді побачить після переходу за посиланням.
        // Основні проєкти й підпроєкти рахуються окремо (parent_id IS NULL / NOT NULL).
        if ($isAdmin) {
            $stats = $db->query(
                "SELECT
                    (SELECT COUNT(*) FROM projects WHERE status = 'active' AND parent_id IS NULL) AS active_projects,
                    (SELECT COUNT(*) FROM projects WHERE status = 'active' AND parent_id IS NOT NULL) AS active_subprojects,
                    (SELECT COUNT(*) FROM tasks t JOIN task_statuses ts ON ts.id = t.status_id WHERE ts.is_closed = 0) AS open_tasks,
                    (SELECT COUNT(*) FROM tickets WHERE status NOT IN ('resolved','closed')) AS open_tickets"
            )->fetch();
        } else {
            // Правило «які проєкти бачить користувач» — єдине, у Project::accessCondition() (автор, відповідальний, учасник).
            [$topSql, $topParams] = Project::accessCondition('projects', 'dtop', $uid);
            [$subSql, $subParams] = Project::accessCondition('projects', 'dsub', $uid);
            [$taskSql, $taskParams] = Project::accessCondition('p', 'dtask', $uid);
            $statsStmt = $db->prepare(
                "SELECT
                    (SELECT COUNT(*) FROM projects
                        WHERE status = 'active' AND parent_id IS NULL AND {$topSql}) AS active_projects,
                    (SELECT COUNT(*) FROM projects
                        WHERE status = 'active' AND parent_id IS NOT NULL AND {$subSql}) AS active_subprojects,
                    (SELECT COUNT(*) FROM tasks t
                        JOIN task_statuses ts ON ts.id = t.status_id
                        JOIN projects p ON p.id = t.project_id
                        WHERE ts.is_closed = 0 AND {$taskSql}) AS open_tasks,
                    (SELECT COUNT(*) FROM tickets
                        WHERE status NOT IN ('resolved','closed') AND (requester_user_id = :uid5 OR assigned_operator_id = :uid6)) AS open_tickets"
            );
            $statsStmt->execute(array_merge($topParams, $subParams, $taskParams, ['uid5' => $uid, 'uid6' => $uid]));
            $stats = $statsStmt->fetch();
        }

        View::render('dashboard', [
            'myOpenTasks' => $myOpenTasks->fetchAll(),
            'stats' => $stats,
        ]);
    }
}
