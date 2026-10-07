<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Markdown;
use App\Core\View;
use App\Models\Audit;
use App\Models\WikiPage;

/**
 * Вікі: сторінки в Markdown з історією версій. Читати можуть усі, хто увійшов (з урахуванням visibility
 * сторінки); створювати й редагувати — admin та it_manager; видаляти — admin. Сторінка, до якої в
 * користувача немає доступу, відповідає 404, а не 403: так не видно навіть самого факту, що вона існує.
 */
class WikiController
{
    public function index(): void
    {
        Auth::requireLogin();
        $role = Auth::role();

        $query = trim((string) ($_GET['q'] ?? ''));
        $results = null;
        $error = $_GET['error'] ?? null;
        if ($query !== '') {
            if (mb_strlen($query) < 2) {
                $error = 'Пошуковий запит має містити щонайменше 2 символи.';
            } else {
                $results = [];
                foreach (WikiPage::search($query, $role) as $row) {
                    $row['snippet_html'] = $this->snippet($row['content'], $query);
                    unset($row['content']);
                    $results[] = $row;
                }
            }
        }

        View::render('wiki/index', $this->common() + [
            'query' => $query,
            'results' => $results,
            'error' => $error,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function show(array $params): void
    {
        $page = $this->findVisible($params['slug']);
        $renderer = new Markdown(WikiPage::allSlugs());
        $html = $renderer->toHtml($page['content']);

        View::render('wiki/show', $this->common($page['slug']) + [
            'page' => $page,
            'html' => $html,
            'toc' => array_values(array_filter($renderer->headings(), static fn(array $h): bool => $h['level'] >= 2 && $h['level'] <= 3)),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    // ------------------------------------------------------------------ створення

    public function create(): void
    {
        $this->requireEditor();
        View::render('wiki/form', $this->common() + [
            'page' => null,
            'form' => ['title' => trim((string) ($_GET['title'] ?? '')), 'slug' => trim((string) ($_GET['slug'] ?? '')), 'content' => '', 'visibility' => 'all', 'sort_order' => 100],
            'error' => $_GET['error'] ?? null,
        ]);
    }

    public function store(): void
    {
        $this->requireEditor();
        $form = $this->readForm();

        $error = $this->validate($form);
        $slug = $form['slug'] !== '' ? $form['slug'] : WikiPage::slugFromTitle($form['title']);
        if ($error === null) {
            if ($form['slug'] !== '' && !WikiPage::isValidSlug($slug)) {
                $error = 'Адреса може містити лише малі латинські літери, цифри та дефіси (до 80 символів) і не може бути службовим словом.';
            } elseif ($form['slug'] !== '' && WikiPage::slugExists($slug)) {
                $error = "Адреса «{$slug}» уже зайнята іншою сторінкою — оберіть іншу.";
            }
        }
        if ($error !== null) {
            View::render('wiki/form', $this->common() + ['page' => null, 'form' => $form, 'error' => $error]);
            return;
        }

        // Адреса не вказана — береться з назви; якщо така вже є, додається «-2», «-3»…
        $slug = $form['slug'] !== '' ? $slug : WikiPage::uniqueSlug($slug);
        $id = WikiPage::create($slug, $form['title'], $form['content'], $form['visibility'], $form['sort_order'], Auth::id());
        Audit::log('wiki_page', $id, 'created', Auth::id(), ['title' => $form['title'], 'slug' => $slug]);
        $this->redirect('/wiki/' . $slug, 'success', 'Сторінку створено.');
    }

    // ------------------------------------------------------------------ редагування

    public function edit(array $params): void
    {
        $this->requireEditor();
        $page = $this->findVisible($params['slug']);
        View::render('wiki/form', $this->common($page['slug']) + [
            'page' => $page,
            'form' => ['title' => $page['title'], 'slug' => $page['slug'], 'content' => $page['content'], 'visibility' => $page['visibility'], 'sort_order' => (int) $page['sort_order']],
            'error' => $_GET['error'] ?? null,
        ]);
    }

    public function update(array $params): void
    {
        $this->requireEditor();
        $page = $this->findVisible($params['slug']);
        $form = $this->readForm();
        $form['slug'] = $page['slug']; // адреса після створення не змінюється: на неї можуть вести посилання
        $postedVersion = (int) ($_POST['version'] ?? 0);

        $error = $this->validate($form);
        if ($error === null && !WikiPage::update((int) $page['id'], $postedVersion, $form['title'], $form['content'], $form['visibility'], $form['sort_order'], Auth::id())) {
            $current = WikiPage::findBySlug($page['slug']);
            $who = $current && $current['updated_by_name'] ? ' (' . $current['updated_by_name'] . ')' : '';
            $error = "Поки ви редагували, цю сторінку встигли змінити{$who} — ваші зміни НЕ збережено. "
                . 'Скопіюйте свій текст із поля нижче, відкрийте актуальну версію (кнопка «Скасувати» повертає на неї) і внесіть правки ще раз.';
        }
        if ($error !== null) {
            View::render('wiki/form', $this->common($page['slug']) + ['page' => $page, 'form' => $form, 'postedVersion' => $postedVersion, 'error' => $error]);
            return;
        }

        Audit::log('wiki_page', (int) $page['id'], 'updated', Auth::id(), ['title' => $form['title'], 'version' => $postedVersion + 1]);
        $this->redirect('/wiki/' . $page['slug'], 'success', 'Зміни збережено.');
    }

    public function delete(array $params): void
    {
        Auth::requireLogin();
        $page = $this->findVisible($params['slug']);
        if (!WikiPage::canDelete(Auth::role(), $page)) {
            http_response_code(403);
            echo ($page['source'] ?? null) === 'docs'
                ? 'Сторінки з документації видаляти не можна — їх можна лише редагувати або обмежити видимість.'
                : 'Видалити сторінку може лише адміністратор системи.';
            exit;
        }
        WikiPage::delete((int) $page['id']);
        Audit::log('wiki_page', (int) $page['id'], 'deleted', Auth::id(), ['title' => $page['title'], 'slug' => $page['slug']]);
        $this->redirect('/wiki', 'success', 'Сторінку «' . $page['title'] . '» видалено разом з її історією.');
    }

    // ------------------------------------------------------------------ історія

    public function history(array $params): void
    {
        $page = $this->findVisible($params['slug']);
        View::render('wiki/history', $this->common($page['slug']) + [
            'page' => $page,
            'revisions' => WikiPage::revisions((int) $page['id']),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    public function revision(array $params): void
    {
        $page = $this->findVisible($params['slug']);
        $revision = WikiPage::revision((int) $page['id'], (int) $params['id']);
        if (!$revision) {
            $this->notFound();
        }
        $renderer = new Markdown(WikiPage::allSlugs());
        View::render('wiki/revision', $this->common($page['slug']) + [
            'page' => $page,
            'revision' => $revision,
            'html' => $renderer->toHtml($revision['content']),
        ]);
    }

    public function restore(array $params): void
    {
        $this->requireEditor();
        $page = $this->findVisible($params['slug']);
        $revision = WikiPage::revision((int) $page['id'], (int) $params['id']);
        if (!$revision) {
            $this->notFound();
        }
        // Відновлення — це НОВА версія з вмістом старої: історія лишається лінійною й нічого не втрачається.
        $ok = WikiPage::update((int) $page['id'], (int) $page['version'], $revision['title'], $revision['content'], $page['visibility'], (int) $page['sort_order'], Auth::id());
        if (!$ok) {
            $this->redirect('/wiki/' . $page['slug'] . '/history', 'error', 'Сторінку тим часом змінили — оновіть історію й спробуйте ще раз.');
        }
        Audit::log('wiki_page', (int) $page['id'], 'restored', Auth::id(), ['restored_version' => (int) $revision['version'], 'version' => (int) $page['version'] + 1]);
        $this->redirect('/wiki/' . $page['slug'], 'success', 'Відновлено вміст версії ' . (int) $revision['version'] . ' (збережено як нову версію).');
    }

    // ------------------------------------------------------------------ попередній перегляд

    /** Відповідає готовим HTML-фрагментом (його вставляє у форму public/assets/js/wiki-editor.js). */
    public function preview(): void
    {
        $this->requireEditor();
        $content = (string) ($_POST['content'] ?? '');
        header('Content-Type: text/html; charset=UTF-8');
        if (mb_strlen($content) > WikiPage::MAX_CONTENT_CHARS) {
            http_response_code(413);
            echo '<div class="text-danger">Текст завеликий для перегляду.</div>';
            return;
        }
        echo (new Markdown(WikiPage::allSlugs()))->toHtml($content);
    }

    // ------------------------------------------------------------------ допоміжне

    /** Дані, потрібні кожній сторінці вікі: бічне меню й права поточного користувача. */
    private function common(?string $currentSlug = null): array
    {
        $role = Auth::role();
        return [
            'sidebarPages' => WikiPage::listVisible($role),
            'currentSlug' => $currentSlug,
            'canEdit' => WikiPage::canEdit($role),
            'role' => $role,
        ];
    }

    private function findVisible(string $slug): array
    {
        Auth::requireLogin();
        $page = preg_match('/^[a-z0-9-]{1,80}$/', $slug) ? WikiPage::findBySlug($slug) : null;
        if (!$page || !WikiPage::visibleTo($page, Auth::role())) {
            $this->notFound();
        }
        return $page;
    }

    private function requireEditor(): void
    {
        Auth::requireLogin();
        if (!WikiPage::canEdit(Auth::role())) {
            http_response_code(403);
            echo 'Створювати й редагувати сторінки вікі можуть адміністратор і керівник ІТ-підрозділу.';
            exit;
        }
    }

    private function notFound(): never
    {
        http_response_code(404);
        echo 'Сторінку не знайдено.';
        exit;
    }

    private function redirect(string $path, string $key, string $message): never
    {
        header('Location: ' . $path . '?' . $key . '=' . urlencode($message));
        exit;
    }

    /** @return array{title: string, slug: string, content: string, visibility: string, sort_order: int} */
    private function readForm(): array
    {
        $sort = $_POST['sort_order'] ?? '100';
        return [
            'title' => trim((string) ($_POST['title'] ?? '')),
            'slug' => strtolower(trim((string) ($_POST['slug'] ?? ''))),
            // Текст не обрізається пробілами: початковий відступ у Markdown може бути значущим.
            'content' => str_replace(["\r\n", "\r"], "\n", (string) ($_POST['content'] ?? '')),
            'visibility' => (string) ($_POST['visibility'] ?? 'all'),
            'sort_order' => is_numeric($sort) ? max(0, min(9999, (int) $sort)) : 100,
        ];
    }

    private function validate(array $form): ?string
    {
        if ($form['title'] === '') {
            return 'Вкажіть назву сторінки.';
        }
        if (mb_strlen($form['title']) > 200) {
            return 'Назва задовга (максимум 200 символів).';
        }
        if (!array_key_exists($form['visibility'], WikiPage::VISIBILITIES)) {
            return 'Оберіть коректну видимість сторінки.';
        }
        if (mb_strlen($form['content']) > WikiPage::MAX_CONTENT_CHARS) {
            return 'Текст сторінки завеликий (максимум ' . number_format(WikiPage::MAX_CONTENT_CHARS, 0, '', ' ') . ' символів).';
        }
        // Заголовок, який перетворився б на порожню адресу, — не підходить (потрібна хоч одна латинська літера/цифра, або адреса вручну)
        return null;
    }

    /**
     * Фрагмент тексту навколо першого збігу з виділенням <mark>. Повертає ГОТОВИЙ безпечний HTML: кожен
     * шматок екранується окремо, а <mark> додається лише тут.
     */
    private function snippet(string $content, string $query): string
    {
        $plain = Markdown::plainText($content);
        $pos = mb_stripos($plain, $query);
        if ($pos === false) {
            return htmlspecialchars(mb_substr($plain, 0, 160) . (mb_strlen($plain) > 160 ? '…' : ''), ENT_QUOTES, 'UTF-8');
        }
        $start = max(0, $pos - 70);
        $before = mb_substr($plain, $start, $pos - $start);
        $match = mb_substr($plain, $pos, mb_strlen($query));
        $after = mb_substr($plain, $pos + mb_strlen($query), 90);
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return ($start > 0 ? '…' : '') . $esc($before) . '<mark>' . $esc($match) . '</mark>' . $esc($after)
            . (mb_strlen($plain) > $pos + mb_strlen($query) + 90 ? '…' : '');
    }
}
