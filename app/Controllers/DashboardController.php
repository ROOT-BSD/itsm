<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;

class DashboardController
{
    public function index(): void
    {
        Auth::requireLogin();

        $db = Database::connection();

        $myOpenTasks = $db->prepare(
            "SELECT t.id, t.title, t.priority, ts.name AS status_name, p.name AS project_name,
                    DATE_FORMAT(t.created_at, '%d.%m.%Y') AS created_at_formatted
             FROM tasks t
             JOIN task_statuses ts ON ts.id = t.status_id
             JOIN projects p ON p.id = t.project_id
             WHERE t.assignee_id = :uid AND ts.is_closed = 0
             ORDER BY p.name ASC, t.due_date IS NULL, t.due_date ASC
             LIMIT 20"
        );
        $myOpenTasks->execute(['uid' => Auth::id()]);

        $stats = $db->query(
            "SELECT
                (SELECT COUNT(*) FROM projects WHERE status = 'active') AS active_projects,
                (SELECT COUNT(*) FROM tasks t JOIN task_statuses ts ON ts.id = t.status_id WHERE ts.is_closed = 0) AS open_tasks,
                (SELECT COUNT(*) FROM tickets WHERE status NOT IN ('resolved','closed')) AS open_tickets"
        )->fetch();

        View::render('dashboard', [
            'myOpenTasks' => $myOpenTasks->fetchAll(),
            'stats' => $stats,
        ]);
    }
}
