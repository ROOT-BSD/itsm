<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;

/**
 * Сторінка «Архів»: закриті тікети, задачі й проєкти зібрані в одному місці —
 * бо їх свідомо прибрано з головних списків (/tickets, /projects, /tasks,
 * таблиці задач на сторінці проєкту), щоб ті не захаращувались завершеною
 * роботою. Видимість тут та сама, що й у відповідних головних списках
 * (адмін бачить усе, решта — лише своє).
 */
class ArchiveController
{
    public function index(): void
    {
        Auth::requireLogin();
        $isAdmin = Auth::hasRole(['admin']);
        $canSeeUnassigned = Auth::hasRole(['it_manager', 'support_operator']);

        View::render('archive/index', [
            'tickets' => Ticket::allClosedVisibleTo(Auth::id(), $isAdmin, $canSeeUnassigned),
            'tasks' => Task::allClosedVisibleTo(Auth::id(), $isAdmin),
            'projects' => Project::closedVisibleTo(Auth::id(), $isAdmin),
        ]);
    }
}
