<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\UploadLimits;
use App\Core\View;
use App\Models\Audit;
use App\Models\LibraryDocument;
use App\Services\AttachmentService;
use App\Services\LibraryService;

/**
 * Бібліотека документів: файли, не прив'язані до тікета чи задачі (регламенти, інструкції, форми, шаблони).
 * Читати можуть усі, хто увійшов (з урахуванням visibility документа); завантажувати, оновлювати й керувати розділами —
 * адміністратор та IT-менеджер (LibraryDocument::canEdit). Документ, недоступний за visibility, для решти ВИГЛЯДАЄ неіснуючим (404),
 * а не забороненим. Файли віддаються виключно через download*() — прямого URL на файл у веб-корені немає.
 */
class LibraryController
{
    // ------------------------------------------------------------------ перегляд

    public function index(): void
    {
        $this->requireReady();
        $role = Auth::role();

        $category = isset($_GET['category']) && $_GET['category'] !== '' ? (string) $_GET['category'] : null;
        if ($category !== null && $category !== 'none' && !ctype_digit($category)) {
            $category = null;
        }
        $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(5, (int) Config::get('library.per_page', 30));

        $result = LibraryDocument::search($role, $category, $query, $page, $perPage);
        // Сторінка за межами списку (застаріле посилання після видалень) — показуємо останню існуючу, а не порожню.
        $lastPage = max(1, (int) ceil($result['total'] / $perPage));
        if ($page > $lastPage) {
            $page = $lastPage;
            $result = LibraryDocument::search($role, $category, $query, $page, $perPage);
        }

        View::render('library/index', $this->common() + [
            'documents' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'totalPages' => max(1, (int) ceil($result['total'] / $perPage)),
            'currentCategory' => $category,
            'query' => $query,
        ]);
    }

    public function show(array $params): void
    {
        $this->requireReady();
        $document = $this->visibleOrFail((int) $params['id']);

        View::render('library/show', $this->common() + [
            'document' => $document,
            'versions' => LibraryDocument::versions((int) $document['id']),
            'canDelete' => LibraryDocument::canDelete(Auth::role(), Auth::id(), $document),
            'currentCategory' => $document['category_id'] !== null ? (string) $document['category_id'] : 'none',
            'query' => '',
        ]);
    }

    // ------------------------------------------------------------------ створення й редагування

    public function create(): void
    {
        $this->requireReady();
        $this->requireEditor();
        View::render('library/form', $this->formData(null, [
            'title' => '', 'description' => '', 'category_id' => (string) ($_GET['category'] ?? ''), 'visibility' => 'all', 'comment' => '',
        ], null));
    }

    public function store(): void
    {
        $this->requireReady();
        $this->requireEditor();

        [$form, $error] = $this->parseMeta();
        $file = null;
        if ($error === null) {
            $file = $this->uploadedFile();
            if ($file === null) {
                $error = 'Оберіть файл для завантаження.';
            } else {
                $stored = LibraryService::store($file);
                if (!$stored['ok']) {
                    $error = '«' . AttachmentService::sanitizeName($file['name']) . '»: ' . $stored['error'] . '.';
                }
            }
        }
        if ($error !== null) {
            View::render('library/form', $this->formData(null, $form, $error));
            return;
        }

        try {
            $id = LibraryDocument::create($form['title'], $form['description'] ?: null, $form['category_id'] ?: null, $form['visibility'], $stored, $form['comment'] ?: null, Auth::id());
        } catch (\Throwable $e) {
            AttachmentService::deleteFiles([$stored['stored_name']]); // файл уже на диску, а запису в БД немає
            throw $e;
        }
        Audit::log('library_document', $id, 'created', Auth::id(), ['title' => $form['title'], 'file' => $stored['name']]);
        $this->redirect('/library/' . $id, 'success', 'Документ додано.');
    }

    public function edit(array $params): void
    {
        $this->requireReady();
        $this->requireEditor();
        $document = $this->visibleOrFail((int) $params['id']);

        View::render('library/form', $this->formData($document, [
            'title' => $document['title'], 'description' => (string) $document['description'],
            'category_id' => (string) ($document['category_id'] ?? ''), 'visibility' => $document['visibility'], 'comment' => '',
        ], null));
    }

    public function update(array $params): void
    {
        $this->requireReady();
        $this->requireEditor();
        $document = $this->visibleOrFail((int) $params['id']);

        [$form, $error] = $this->parseMeta();
        if ($error !== null) {
            View::render('library/form', $this->formData($document, $form, $error));
            return;
        }

        LibraryDocument::update((int) $document['id'], $form['title'], $form['description'] ?: null, $form['category_id'] ?: null, $form['visibility'], Auth::id());
        $changes = [];
        foreach (['title' => 'title', 'visibility' => 'visibility'] as $field => $key) {
            if ((string) $document[$field] !== (string) $form[$key]) {
                $changes[$field] = $form[$key];
            }
        }
        if ((int) ($document['category_id'] ?? 0) !== (int) $form['category_id']) {
            $changes['category_id'] = $form['category_id'] ?: null;
        }
        if ((string) $document['description'] !== (string) $form['description']) {
            $changes['description_changed'] = 1;
        }
        if ($changes) {
            Audit::log('library_document', (int) $document['id'], 'updated', Auth::id(), $changes);
        }
        $this->redirect('/library/' . $document['id'], 'success', 'Зміни збережено.');
    }

    public function addVersion(array $params): void
    {
        $this->requireReady();
        $this->requireEditor();
        $document = $this->visibleOrFail((int) $params['id']);
        $back = '/library/' . $document['id'];

        if (LibraryDocument::versionCount((int) $document['id']) >= LibraryDocument::maxVersions()) {
            $this->redirect($back, 'error', 'Досягнуто ліміт версій документа (' . LibraryDocument::maxVersions() . '). Видаліть непотрібні старі версії.');
        }
        $file = $this->uploadedFile();
        if ($file === null) {
            $this->redirect($back, 'error', 'Оберіть файл нової версії.');
        }
        $stored = LibraryService::store($file);
        if (!$stored['ok']) {
            $this->redirect($back, 'error', '«' . AttachmentService::sanitizeName($file['name']) . '»: ' . $stored['error'] . '.');
        }

        $comment = mb_substr(trim((string) ($_POST['comment'] ?? '')), 0, 255);
        try {
            $number = LibraryDocument::addVersion((int) $document['id'], $stored, $comment !== '' ? $comment : null, Auth::id());
        } catch (\Throwable $e) {
            AttachmentService::deleteFiles([$stored['stored_name']]);
            throw $e;
        }
        Audit::log('library_document', (int) $document['id'], 'version_added', Auth::id(), ['version' => $number, 'file' => $stored['name']]);
        $this->redirect($back, 'success', 'Додано версію ' . $number . '.');
    }

    // ------------------------------------------------------------------ видалення

    public function delete(array $params): void
    {
        $this->requireReady();
        $document = $this->visibleOrFail((int) $params['id']);
        if (!LibraryDocument::canDelete(Auth::role(), Auth::id(), $document)) {
            $this->forbidden('Видалити документ може адміністратор або той редактор, який його створив.');
        }

        $files = LibraryDocument::storedNames((int) $document['id']);
        LibraryDocument::delete((int) $document['id']);
        AttachmentService::deleteFiles($files);
        Audit::log('library_document', (int) $document['id'], 'deleted', Auth::id(), ['title' => $document['title'], 'versions' => count($files)]);
        $this->redirect('/library', 'success', 'Документ «' . $document['title'] . '» видалено разом з усіма версіями.');
    }

    public function deleteVersion(array $params): void
    {
        $this->requireReady();
        $version = LibraryDocument::findVersion((int) $params['id']);
        $document = $version ? $this->visibleOrFail((int) $version['document_id']) : null;
        if (!$version || !$document) {
            $this->notFound();
        }
        if (!LibraryDocument::canDelete(Auth::role(), Auth::id(), $document)) {
            $this->forbidden('Видалити версію може адміністратор або той редактор, який створив документ.');
        }
        $back = '/library/' . $document['id'];
        if (LibraryDocument::versionCount((int) $document['id']) < 2) {
            $this->redirect($back, 'error', 'Єдину версію видалити не можна — видаліть документ повністю.');
        }

        LibraryDocument::deleteVersion((int) $version['id']);
        AttachmentService::deleteFiles([$version['stored_name']]);
        Audit::log('library_document', (int) $document['id'], 'version_deleted', Auth::id(), ['version' => (int) $version['version_no'], 'file' => $version['original_name']]);
        $this->redirect($back, 'success', 'Версію ' . (int) $version['version_no'] . ' видалено.');
    }

    // ------------------------------------------------------------------ завантаження файлу

    /** Поточна (остання) версія документа. */
    public function download(array $params): void
    {
        $this->requireReady();
        $document = $this->visibleOrFail((int) $params['id']);
        $version = LibraryDocument::latestVersion((int) $document['id']);
        if (!$version) {
            $this->notFound();
        }
        $this->sendFile($version);
    }

    /** Конкретна версія за її id. */
    public function downloadVersion(array $params): void
    {
        $this->requireReady();
        $version = LibraryDocument::findVersion((int) $params['id']);
        if (!$version) {
            $this->notFound();
        }
        $this->visibleOrFail((int) $version['document_id']);
        $this->sendFile($version);
    }

    private function sendFile(array $version): void
    {
        $path = AttachmentService::path($version['stored_name']);
        if ($path === null || !is_file($path)) {
            http_response_code(404);
            echo 'Файл не знайдено на сервері (можливо, його видалено з диска). Зверніться до адміністратора.';
            exit;
        }

        // Лише PDF і зображення браузер показує сам; усе інше — тільки завантаження. ?download=1 примушує завантаження.
        $inline = in_array($version['mime_type'], LibraryService::INLINE_MIMES, true) && !isset($_GET['download']);
        $name = $version['original_name'];
        $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';

        session_write_close(); // не тримати сесійний замок, поки триває віддача файлу
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $version['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    }

    // ------------------------------------------------------------------ розділи

    public function createCategory(): void
    {
        $this->requireReady();
        $this->requireEditor();
        [$name, $sort, $error] = $this->parseCategory();
        if ($error === null && LibraryDocument::createCategory($name, $sort) === null) {
            $error = 'Розділ «' . $name . '» уже існує.';
        }
        $this->redirect('/library', $error ? 'error' : 'success', $error ?? 'Розділ «' . $name . '» створено.');
    }

    public function updateCategory(array $params): void
    {
        $this->requireReady();
        $this->requireEditor();
        $category = LibraryDocument::findCategory((int) $params['id']);
        if (!$category) {
            $this->notFound();
        }
        [$name, $sort, $error] = $this->parseCategory();
        if ($error === null && !LibraryDocument::updateCategory((int) $category['id'], $name, $sort)) {
            $error = 'Розділ «' . $name . '» уже існує.';
        }
        $this->redirect('/library?category=' . $category['id'], $error ? 'error' : 'success', $error ?? 'Розділ збережено.');
    }

    public function deleteCategory(array $params): void
    {
        $this->requireReady();
        $this->requireEditor();
        $category = LibraryDocument::findCategory((int) $params['id']);
        if (!$category) {
            $this->notFound();
        }
        LibraryDocument::deleteCategory((int) $category['id']);
        $this->redirect('/library', 'success', 'Розділ «' . $category['name'] . '» видалено. Його документи переміщено до «Без розділу».');
    }

    // ------------------------------------------------------------------ допоміжне

    /** Дані, спільні для сторінок бібліотеки (бічне меню, права, флеш-повідомлення). */
    private function common(): array
    {
        $role = Auth::role();
        return [
            'sidebar' => LibraryDocument::categoriesWithCounts($role),
            'canEdit' => LibraryDocument::canEdit($role),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ];
    }

    private function formData(?array $document, array $form, ?string $error): array
    {
        // Власні ключі першими: оператор «+» лишає значення зліва, а error з common() (це ?error=… з адреси) не має їх затирати.
        return [
            'document' => $document,
            'form' => $form,
            'error' => $error ?? ($_GET['error'] ?? null),
            'categories' => LibraryDocument::categoriesWithCounts(Auth::role())['categories'],
            'visibilities' => array_intersect_key(LibraryDocument::visibilities(), array_flip(LibraryDocument::allowedVisibilities(Auth::role()))),
            'currentCategory' => null,
            'query' => '',
        ] + $this->common();
    }

    /** @return array{0: array{title: string, description: string, category_id: string, visibility: string, comment: string}, 1: ?string} */
    private function parseMeta(): array
    {
        $form = [
            'title' => trim((string) ($_POST['title'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'category_id' => (string) ($_POST['category_id'] ?? ''),
            'visibility' => (string) ($_POST['visibility'] ?? 'all'),
            'comment' => mb_substr(trim((string) ($_POST['comment'] ?? '')), 0, 255),
        ];

        $error = null;
        if ($form['title'] === '') {
            $error = 'Вкажіть назву документа.';
        } elseif (mb_strlen($form['title']) > LibraryDocument::MAX_TITLE) {
            $error = 'Назва задовга (не більше ' . LibraryDocument::MAX_TITLE . ' символів).';
        } elseif (mb_strlen($form['description']) > LibraryDocument::MAX_DESCRIPTION) {
            $error = 'Опис задовгий (не більше ' . LibraryDocument::MAX_DESCRIPTION . ' символів).';
        } elseif (!in_array($form['visibility'], LibraryDocument::allowedVisibilities(Auth::role()), true)) {
            $error = 'Недопустиме значення «Хто бачить».';
        } elseif ($form['category_id'] !== '' && (!ctype_digit($form['category_id']) || !LibraryDocument::categoryExists((int) $form['category_id']))) {
            $error = 'Обраного розділу не існує.';
        }
        $form['category_id'] = $form['category_id'] === '' ? '' : (string) (int) $form['category_id'];
        return [$form, $error];
    }

    /** @return array{0: string, 1: int, 2: ?string} */
    private function parseCategory(): array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $sort = max(0, min(9999, (int) ($_POST['sort_order'] ?? 100)));
        if ($name === '') {
            return [$name, $sort, 'Вкажіть назву розділу.'];
        }
        if (mb_strlen($name) > 100) {
            return [$name, $sort, 'Назва розділу задовга (не більше 100 символів).'];
        }
        return [$name, $sort, null];
    }

    /** @return array{name: string, tmp_name: string, error: int, size: int}|null null, якщо файл не вибрано */
    private function uploadedFile(): ?array
    {
        $files = AttachmentService::normalizeFiles($_FILES['file'] ?? []);
        $file = $files[0] ?? null;
        return $file !== null && $file['error'] !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    private function visibleOrFail(int $id): array
    {
        $document = LibraryDocument::find($id);
        if (!$document || !LibraryDocument::visibleTo($document, Auth::role())) {
            $this->notFound();
        }
        return $document;
    }

    private function requireReady(): void
    {
        Auth::requireLogin();
        if (!LibraryDocument::tablesExist()) {
            http_response_code(503);
            View::render('library/unavailable', []);
            exit;
        }
    }

    private function requireEditor(): void
    {
        if (!LibraryDocument::canEdit(Auth::role())) {
            $this->forbidden('Керувати бібліотекою документів можуть адміністратор та IT-менеджер.');
        }
    }

    private function redirect(string $url, string $key, string $message): never
    {
        header('Location: ' . $url . (str_contains($url, '?') ? '&' : '?') . http_build_query([$key => $message]));
        exit;
    }

    private function notFound(): never
    {
        http_response_code(404);
        echo 'Документ не знайдено.';
        exit;
    }

    private function forbidden(string $message): never
    {
        http_response_code(403);
        echo $message;
        exit;
    }
}
