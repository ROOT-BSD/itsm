<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Models\Audit;
use App\Models\LoginLog;
use App\Models\UserSession;

/** Активні сесії та журнал входів: власні (/profile/sessions) і загальні для адміністратора (/admin/sessions, /admin/login-log). */
class SessionController
{
    private const PER_PAGE = 50;

    // ---------- Користувач: власні сесії ----------

    public function mine(): void
    {
        Auth::requireLogin();
        $uid = (int) Auth::id();
        View::render('profile/sessions', [
            'ready' => UserSession::ready(),
            'sessions' => UserSession::activeFor($uid),
            'currentHash' => UserSession::hash(session_id()),
            'history' => LoginLog::recentFor($uid, 30),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function revokeMine(array $params): void
    {
        Auth::requireLogin();
        $row = UserSession::find((int) $params['id']);
        // Чужу сесію (або неіснуючу) показуємо так само, як неіснуючу: без підказки, що вона є.
        if (!$row || (int) $row['user_id'] !== (int) Auth::id()) {
            http_response_code(404);
            echo 'Сесію не знайдено';
            return;
        }
        if (hash_equals($row['session_hash'], UserSession::hash(session_id()))) {
            $this->back('/profile/sessions', 'error', 'Поточну сесію завершують кнопкою «Вийти».');
            return;
        }
        UserSession::revoke((int) $row['id'], 'user', (int) Auth::id());
        Audit::log('user', (int) Auth::id(), 'session_revoked_self', (int) Auth::id(), ['session_id' => (int) $row['id']]);
        $this->back('/profile/sessions', 'success', 'Сесію завершено');
    }

    public function revokeOthers(): void
    {
        Auth::requireLogin();
        $n = UserSession::revokeAllFor((int) Auth::id(), 'user', (int) Auth::id(), session_id());
        Audit::log('user', (int) Auth::id(), 'sessions_revoked_self', (int) Auth::id(), ['count' => $n]);
        $this->back('/profile/sessions', 'success', $n > 0 ? "Завершено інших сесій: {$n}" : 'Інших активних сесій немає');
    }

    // ---------- Адміністратор ----------

    public function adminIndex(): void
    {
        $this->requireAdmin();
        View::render('admin/sessions', [
            'ready' => UserSession::ready(),
            'sessions' => UserSession::allActive(),
            'currentHash' => UserSession::hash(session_id()),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function adminRevoke(array $params): void
    {
        $this->requireAdmin();
        $row = UserSession::find((int) $params['id']);
        if (!$row) {
            http_response_code(404);
            echo 'Сесію не знайдено';
            return;
        }
        if (hash_equals($row['session_hash'], UserSession::hash(session_id()))) {
            $this->back('/admin/sessions', 'error', 'Це ваша поточна сесія — завершіть її кнопкою «Вийти».');
            return;
        }
        UserSession::revoke((int) $row['id'], 'admin', (int) Auth::id());
        Audit::log('user', (int) $row['user_id'], 'session_revoked_by_admin', (int) Auth::id(), ['session_id' => (int) $row['id']]);
        $this->back('/admin/sessions', 'success', 'Сесію завершено');
    }

    /** Завершує всі сесії користувача (адмінові власні — крім поточної). */
    public function adminRevokeUser(array $params): void
    {
        $this->requireAdmin();
        $uid = (int) $params['id'];
        $n = UserSession::revokeAllFor($uid, 'admin', (int) Auth::id(), $uid === (int) Auth::id() ? session_id() : null);
        Audit::log('user', $uid, 'sessions_revoked_by_admin', (int) Auth::id(), ['count' => $n]);
        $this->back('/admin/sessions', 'success', $n > 0 ? "Завершено сесій: {$n}" : 'Активних сесій немає');
    }

    public function adminLog(): void
    {
        $this->requireAdmin();
        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'result' => in_array($_GET['result'] ?? '', ['ok', 'fail'], true) ? $_GET['result'] : '',
            'date_from' => (string) ($_GET['date_from'] ?? ''),
            'date_to' => (string) ($_GET['date_to'] ?? ''),
        ];
        $total = LoginLog::count($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);

        View::render('admin/login_log', [
            'ready' => UserSession::ready(),
            'entries' => LoginLog::filtered($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'filters' => $filters,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    private function requireAdmin(): void
    {
        Auth::requireLogin();
        if (!Auth::hasRole(['admin'])) {
            http_response_code(403);
            echo 'Доступно лише ролі "Адміністратор системи".';
            exit;
        }
    }

    private function back(string $path, string $key, string $message): void
    {
        header('Location: ' . $path . '?' . $key . '=' . urlencode($message));
        exit;
    }
}
