<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Markdown;
use App\Core\View;
use App\Models\Audit;
use App\Models\WikiAttachment;
use App\Models\WikiPage;
use App\Services\AttachmentService;
use App\Services\LibraryService;
use App\Core\UploadLimits;

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
        $renderer = (new Markdown(WikiPage::allSlugs()))->setAttachments(WikiAttachment::mapForPage((int) $page['id']));
        $html = $renderer->toHtml($page['content']);
        $common = $this->common($page['slug']);
        $visible = $common['visiblePages'];

        View::render('wiki/show', $common + [
            'page' => $page,
            'html' => $html,
            'breadcrumbs' => WikiPage::ancestors($page, $visible),
            'children' => WikiPage::childrenOf((int) $page['id'], $visible),
            'attachments' => WikiAttachment::forPage((int) $page['id']),
            'toc' => array_values(array_filter($renderer->headings(), static fn(array $h): bool => $h['level'] >= 2 && $h['level'] <= 3)),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    // ------------------------------------------------------------------ створення

    public function create(): void
    {
        $this->requireEditor();
        // ?parent=slug — кнопка «Додати підсторінку» на сторінці-батьку
        $parentId = null;
        $parentSlug = trim((string) ($_GET['parent'] ?? ''));
        if ($parentSlug !== '' && preg_match('/^[a-z0-9-]{1,80}$/', $parentSlug)) {
            $parent = WikiPage::findBySlug($parentSlug);
            if ($parent && WikiPage::visibleTo($parent, Auth::role())) {
                $parentId = (int) $parent['id'];
            }
        }
        View::render('wiki/form', $this->common() + $this->formExtras(null) + [
            'page' => null,
            'form' => ['title' => trim((string) ($_GET['title'] ?? '')), 'slug' => trim((string) ($_GET['slug'] ?? '')), 'content' => '', 'visibility' => 'all', 'sort_order' => 100, 'parent_id' => $parentId],
            'error' => $_GET['error'] ?? null,
        ]);
    }

    public function store(): void
    {
        $this->requireEditor();
        $form = $this->readForm();

        $error = $this->validate($form, null);
        $slug = $form['slug'] !== '' ? $form['slug'] : WikiPage::slugFromTitle($form['title']);
        if ($error === null) {
            if ($form['slug'] !== '' && !WikiPage::isValidSlug($slug)) {
                $error = 'Адреса може містити лише малі латинські літери, цифри та дефіси (до 80 символів) і не може бути службовим словом.';
            } elseif ($form['slug'] !== '' && WikiPage::slugExists($slug)) {
                $error = "Адреса «{$slug}» уже зайнята іншою сторінкою — оберіть іншу.";
            }
        }
        if ($error !== null) {
            View::render('wiki/form', $this->common() + $this->formExtras(null) + ['page' => null, 'form' => $form, 'error' => $error]);
            return;
        }

        // Адреса не вказана — береться з назви; якщо така вже є, додається «-2», «-3»…
        $slug = $form['slug'] !== '' ? $slug : WikiPage::uniqueSlug($slug);
        $id = WikiPage::create($slug, $form['title'], $form['content'], $form['visibility'], $form['sort_order'], Auth::id());
        if ($form['parent_id'] !== false && $form['parent_id'] !== null) {
            WikiPage::setParent($id, $form['parent_id']);
        }
        Audit::log('wiki_page', $id, 'created', Auth::id(), ['title' => $form['title'], 'slug' => $slug] + ($form['parent_id'] ? ['parent' => $form['parent_id']] : []));
        $this->redirect('/wiki/' . $slug, 'success', 'Сторінку створено.');
    }

    // ------------------------------------------------------------------ редагування

    public function edit(array $params): void
    {
        $this->requireEditor();
        $page = $this->findVisible($params['slug']);
        View::render('wiki/form', $this->common($page['slug']) + $this->formExtras($page) + [
            'page' => $page,
            'form' => ['title' => $page['title'], 'slug' => $page['slug'], 'content' => $page['content'], 'visibility' => $page['visibility'], 'sort_order' => (int) $page['sort_order'], 'parent_id' => $page['parent_id'] !== null ? (int) $page['parent_id'] : null],
            'success' => $_GET['success'] ?? null,
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

        $error = $this->validate($form, (int) $page['id']);
        if ($error === null && !WikiPage::update((int) $page['id'], $postedVersion, $form['title'], $form['content'], $form['visibility'], $form['sort_order'], Auth::id())) {
            $current = WikiPage::findBySlug($page['slug']);
            $who = $current && $current['updated_by_name'] ? ' (' . $current['updated_by_name'] . ')' : '';
            $error = "Поки ви редагували, цю сторінку встигли змінити{$who} — ваші зміни НЕ збережено. "
                . 'Скопіюйте свій текст із поля нижче, відкрийте актуальну версію (кнопка «Скасувати» повертає на неї) і внесіть правки ще раз.';
        }
        if ($error !== null) {
            View::render('wiki/form', $this->common($page['slug']) + $this->formExtras($page) + ['page' => $page, 'form' => $form, 'postedVersion' => $postedVersion, 'error' => $error]);
            return;
        }
        // false — поле батька не надсилалося (поточний батько недоступний цьому редакторові): лишаємо як було
        if ($form['parent_id'] !== false && $form['parent_id'] !== ($page['parent_id'] !== null ? (int) $page['parent_id'] : null)) {
            WikiPage::setParent((int) $page['id'], $form['parent_id']);
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
        $storedNames = WikiAttachment::storedNamesForPage((int) $page['id']);
        WikiPage::delete((int) $page['id']);
        AttachmentService::deleteFiles($storedNames); // записи вкладень видалені каскадом — прибираємо й самі файли
        Audit::log('wiki_page', (int) $page['id'], 'deleted', Auth::id(), ['title' => $page['title'], 'slug' => $page['slug']] + ($storedNames ? ['attachments' => count($storedNames)] : []));
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
        $renderer = (new Markdown(WikiPage::allSlugs()))->setAttachments(WikiAttachment::mapForPage((int) $page['id']));
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
        // Для наявної сторінки в перегляді працюють і її вкладення (slug надсилає редактор).
        $attachments = [];
        $slug = (string) ($_POST['slug'] ?? '');
        if (preg_match('/^[a-z0-9-]{1,80}$/', $slug)) {
            $page = WikiPage::findBySlug($slug);
            if ($page && WikiPage::visibleTo($page, Auth::role())) {
                $attachments = WikiAttachment::mapForPage((int) $page['id']);
            }
        }
        echo (new Markdown(WikiPage::allSlugs()))->setAttachments($attachments)->toHtml($content);
    }

    // ------------------------------------------------------------------ вкладення

    /**
     * Завантаження файлу до сторінки (редактор шле його через fetch і вставляє розмітку в текст; без JS працює
     * звичайна форма й повертає на редагування). Дозволені ті самі типи, що в бібліотеці документів.
     */
    public function uploadAttachment(array $params): void
    {
        $this->requireEditor();
        $page = $this->findVisible($params['slug']);
        $json = $this->wantsJson();
        $back = '/wiki/' . $page['slug'] . '/edit';

        $fail = function (string $message, int $status = 422) use ($json, $back): never {
            if ($json) {
                $this->json(['ok' => false, 'error' => $message], $status);
            }
            $this->redirect($back, 'error', $message);
        };

        if (!WikiPage::attachmentsReady()) {
            $fail('Вкладення вікі не підготовлено: адміністратор має виконати update.sh (міграція 026).', 503);
        }
        if (WikiAttachment::countForPage((int) $page['id']) >= WikiAttachment::MAX_PER_PAGE) {
            $fail('До сторінки вже додано максимум файлів (' . WikiAttachment::MAX_PER_PAGE . ') — видаліть непотрібні.');
        }
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            $fail('Оберіть файл.');
        }
        $stored = LibraryService::store($file);
        if (!$stored['ok']) {
            $fail('Файл не додано: ' . $stored['error'] . '.');
        }
        try {
            $id = WikiAttachment::create((int) $page['id'], $stored['name'], $stored['stored_name'], $stored['mime'], $stored['size'], Auth::id());
        } catch (\Throwable $e) {
            AttachmentService::deleteFiles([$stored['stored_name']]); // запис не створено — файл-сирота не лишається
            throw $e;
        }
        Audit::log('wiki_page', (int) $page['id'], 'attachment_added', Auth::id(), ['name' => $stored['name']]);

        $isImage = WikiAttachment::isImage($stored['mime']);
        $label = trim(str_replace(['[', ']', "\n", '\\'], ' ', pathinfo($stored['name'], PATHINFO_FILENAME)));
        $markdown = ($isImage ? '!' : '') . '[' . ($label !== '' ? $label : 'файл') . '](file:' . $id . ')';
        if ($json) {
            $this->json(['ok' => true, 'id' => $id, 'name' => $stored['name'], 'image' => $isImage, 'size' => UploadLimits::human($stored['size']), 'markdown' => $markdown]);
        }
        $this->redirect($back, 'success', 'Файл додано. Розмітка для вставки: ' . $markdown);
    }

    public function deleteAttachment(array $params): void
    {
        $this->requireEditor();
        $page = $this->findVisible($params['slug']);
        $json = $this->wantsJson();
        $attachment = WikiAttachment::find((int) $params['id']);
        if (!$attachment || (int) $attachment['page_id'] !== (int) $page['id']) {
            $this->notFound();
        }
        WikiAttachment::delete((int) $attachment['id']);
        AttachmentService::deleteFiles([$attachment['stored_name']]);
        Audit::log('wiki_page', (int) $page['id'], 'attachment_removed', Auth::id(), ['name' => $attachment['original_name']]);
        if ($json) {
            $this->json(['ok' => true]);
        }
        $this->redirect('/wiki/' . $page['slug'] . '/edit', 'success', 'Файл «' . $attachment['original_name'] . '» видалено. Посилання на нього в тексті тепер не працюватимуть.');
    }

    /** Віддає файл вкладення, якщо читачеві видима його сторінка (інакше 404, як і для самої сторінки). */
    public function file(array $params): void
    {
        Auth::requireLogin();
        $attachment = WikiAttachment::find((int) $params['id']);
        $page = $attachment ? WikiPage::findById((int) $attachment['page_id']) : null;
        if (!$attachment || !$page || !WikiPage::visibleTo($page, Auth::role())) {
            $this->notFound();
        }
        $path = AttachmentService::path($attachment['stored_name']);
        if ($path === null || !is_file($path)) {
            http_response_code(404);
            echo 'Файл не знайдено на сервері (можливо, його видалено з диска). Зверніться до адміністратора.';
            exit;
        }
        $inline = in_array($attachment['mime_type'], LibraryService::INLINE_MIMES, true) && !isset($_GET['download']);
        $name = $attachment['original_name'];
        $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';

        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    }

    private function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ------------------------------------------------------------------ допоміжне

    /** Дані, потрібні кожній сторінці вікі: бічне меню (дерево) й права поточного користувача. */
    private function common(?string $currentSlug = null): array
    {
        $role = Auth::role();
        $visible = WikiPage::listVisible($role);
        return [
            'visiblePages' => $visible,
            'sidebarPages' => WikiPage::tree($visible),
            'currentSlug' => $currentSlug,
            'canEdit' => WikiPage::canEdit($role),
            'role' => $role,
        ];
    }

    /**
     * Додаткові дані форми: варіанти батьківської сторінки (без самої сторінки та її нащадків) і вкладення.
     * 'parentOptions' = null — міграція 026 не застосована, вибору батька немає.
     */
    private function formExtras(?array $page): array
    {
        $options = null;
        if (WikiPage::hierarchyReady()) {
            $exclude = $page ? array_merge([(int) $page['id']], WikiPage::descendantIds((int) $page['id'])) : [];
            $options = array_values(array_filter(
                WikiPage::tree(WikiPage::listVisible(Auth::role())),
                static fn(array $p): bool => !in_array((int) $p['id'], $exclude, true)
            ));
        }
        return [
            'parentOptions' => $options,
            'attachments' => $page ? WikiAttachment::forPage((int) $page['id']) : [],
            'attachmentsReady' => WikiPage::attachmentsReady(),
            'attachmentMax' => LibraryService::maxBytes(),
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

    /** @return array{title: string, slug: string, content: string, visibility: string, sort_order: int, parent_id: int|null|false} */
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
            // null — верхній рівень; false — поле не надсилалося (форма без вибору батька): батько не змінюється
            'parent_id' => !array_key_exists('parent_id', $_POST) ? false : (preg_match('/^[1-9][0-9]{0,9}$/', (string) $_POST['parent_id']) ? (int) $_POST['parent_id'] : null),
        ];
    }

    private function validate(array $form, ?int $pageId): ?string
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
        if ($form['parent_id']) {
            if (!WikiPage::hierarchyReady()) {
                return null;
            }
            $visibleIds = array_map(static fn(array $p): int => (int) $p['id'], WikiPage::listVisible(Auth::role()));
            if (!in_array($form['parent_id'], $visibleIds, true)) {
                return 'Обрана батьківська сторінка не існує.';
            }
            if (($problem = WikiPage::parentProblem($pageId, $form['parent_id'])) !== null) {
                return $problem;
            }
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
