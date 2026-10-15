<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Services\SearchService;

/** Глобальний пошук (`/search?q=`) по проєктах, задачах, тікетах, вікі, документах і форуму — у межах прав поточного користувача. */
class SearchController
{
    public function index(): void
    {
        Auth::requireLogin();
        $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, SearchService::MAX_LENGTH);
        $tooShort = $query !== '' && mb_strlen($query) < SearchService::MIN_LENGTH;
        $sections = ($query === '' || $tooShort) ? [] : SearchService::run($query, (int) Auth::id(), Auth::role());
        View::render('search/index', [
            'query' => $query,
            'tooShort' => $tooShort,
            'sections' => $sections,
        ]);
    }
}
