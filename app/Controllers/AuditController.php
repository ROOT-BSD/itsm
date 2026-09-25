<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Audit;
use App\Models\User;

class AuditController
{
    private const PER_PAGE = 50;

    public function __construct()
    {
        Auth::requireLogin();
        if (!Auth::hasRole(['admin'])) {
            http_response_code(403);
            echo 'Доступ до журналу аудиту дозволено лише ролі "Адміністратор системи".';
            exit;
        }
    }

    public function index(): void
    {
        $filters = [
            'entity_type' => $_GET['entity_type'] ?? '',
            'user_id' => !empty($_GET['user_id']) ? (int) $_GET['user_id'] : null,
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
        ];

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $total = Audit::countFiltered($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * self::PER_PAGE;

        $entries = Audit::filtered($filters, self::PER_PAGE, $offset);

        View::render('admin/audit', [
            'entries' => $entries,
            'entityNames' => Audit::entityNamesFor($entries),
            'referencedNames' => Audit::referencedNamesFor($entries),
            'entityTypes' => Audit::distinctEntityTypes(),
            'users' => User::allActive(),
            'filters' => $filters,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }
}
