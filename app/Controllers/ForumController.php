<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Markdown;
use App\Core\RateLimiter;
use App\Core\View;
use App\Models\Audit;
use App\Models\Forum;
use App\Services\AttachmentService;
use App\Services\LibraryService;

/**
 * Форум: розділи → теми → повідомлення (Markdown). Читати можуть усі, хто увійшов (з урахуванням visibility розділу), писати —
 * усі, хто бачить розділ, окрім закритих тем/розділів. Модератори (адміністратор, IT-менеджер) керують розділами та
 * закріплюють/закривають/переносять/видаляють теми й повідомлення. Автор редагує й видаляє свої повідомлення.
 * Недоступний розділ, тема чи повідомлення для решти ВИГЛЯДАЮТЬ неіснуючими (404), а не забороненими.
 */
class ForumController
{
    private const TOPICS_PER_PAGE = 25;
    private const POSTS_PER_PAGE = 20;

    // ------------------------------------------------------------------ перегляд

    public function index(): void
    {
        $this->requireReady();
        $role = Auth::role();
        $uid = (int) Auth::id();
        $boards = Forum::boards($role, $uid);
        $this->render('forum/index', [
            'boards' => $boards,
            'recent' => Forum::recentTopics($role, 8, $uid),
            'unreadReady' => Forum::unreadReady(),
            'unreadTotal' => array_sum(array_map(static fn($b) => (int) ($b['unread_count'] ?? 0), $boards)),
        ]);
    }

    /** «Позначити все прочитаним». */
    public function readAll(): void
    {
        $this->requireReady();
        if (!Forum::unreadReady()) {
            $this->redirect('/forum', 'error', 'Позначки «непрочитане» ще не налаштовано: адміністратор має виконати update.sh (міграція 033).');
        }
        Forum::markAllRead((int) Auth::id());
        $this->redirect('/forum', 'success', 'Усі теми позначено прочитаними.');
    }

    public function board(array $params): void
    {
        $this->requireReady();
        $board = $this->boardOrFail((int) $params['id']);
        $total = Forum::topicCount((int) $board['id']);
        $totalPages = max(1, (int) ceil($total / self::TOPICS_PER_PAGE));
        $page = min($totalPages, max(1, (int) ($_GET['page'] ?? 1)));

        $this->render('forum/board', [
            'board' => $board,
            'topics' => Forum::topics((int) $board['id'], $page, self::TOPICS_PER_PAGE, (int) Auth::id()),
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
            'canStart' => $this->canStartTopic($board),
            'subscriptionsReady' => Forum::subscriptionsReady(),
            'isSubscribed' => Forum::isSubscribed((int) Auth::id(), (int) $board['id']),
            'notifyEnabled' => \App\Models\Setting::get('email_notify_forum_enabled', '0') === '1',
        ]);
    }

    /** Підписка на нові теми розділу (лист про кожну нову тему). */
    public function subscribe(array $params): void
    {
        $this->requireReady();
        $board = $this->boardOrFail((int) $params['id']);
        if (!Forum::subscriptionsReady()) {
            $this->redirect('/forum/boards/' . $board['id'], 'error', 'Підписки ще не налаштовано: адміністратор має виконати update.sh (міграція 031).');
        }
        Forum::subscribe((int) Auth::id(), (int) $board['id']);
        $this->redirect('/forum/boards/' . $board['id'], 'success', 'Ви підписані на нові теми розділу.');
    }

    public function unsubscribe(array $params): void
    {
        $this->requireReady();
        $board = $this->boardOrFail((int) $params['id']);
        if (Forum::subscriptionsReady()) {
            Forum::unsubscribe((int) Auth::id(), (int) $board['id']);
        }
        $this->redirect('/forum/boards/' . $board['id'], 'success', 'Підписку на розділ скасовано.');
    }

    public function topic(array $params): void
    {
        $this->requireReady();
        $topic = $this->topicOrFail((int) $params['id']);
        $this->renderTopic($topic, max(1, (int) ($_GET['page'] ?? 1)), null, '');
    }

    /** Постійне посилання на повідомлення: переводить на потрібну сторінку теми з якорем. */
    public function post(array $params): void
    {
        $this->requireReady();
        $post = $this->postOrFail((int) $params['id']);
        $page = Forum::pageOfPost((int) $post['topic_id'], (int) $post['id'], self::POSTS_PER_PAGE);
        header('Location: /forum/topics/' . (int) $post['topic_id'] . ($page > 1 ? '?page=' . $page : '') . '#post-' . (int) $post['id']);
        exit;
    }

    public function search(): void
    {
        $this->requireReady();
        $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = $query === '' ? ['rows' => [], 'total' => 0] : Forum::search(Auth::role(), $query, $page, self::TOPICS_PER_PAGE, (int) Auth::id());
        $totalPages = max(1, (int) ceil($result['total'] / self::TOPICS_PER_PAGE));
        if ($page > $totalPages) {
            $page = $totalPages;
            $result = Forum::search(Auth::role(), $query, $page, self::TOPICS_PER_PAGE, (int) Auth::id());
        }

        $this->render('forum/search', [
            'query' => $query, 'topics' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'totalPages' => $totalPages,
        ]);
    }

    // ------------------------------------------------------------------ теми й відповіді

    public function newTopic(array $params): void
    {
        $this->requireReady();
        $board = $this->boardOrFail((int) $params['id']);
        $this->requireCanStart($board);
        $this->render('forum/topic_form', ['board' => $board, 'form' => ['title' => '', 'body' => ''], 'error' => null, 'attachmentsReady' => Forum::attachmentsReady()]);
    }

    public function storeTopic(array $params): void
    {
        $this->requireReady();
        $board = $this->boardOrFail((int) $params['id']);
        $this->requireCanStart($board);

        $form = ['title' => trim((string) ($_POST['title'] ?? '')), 'body' => $this->cleanBody((string) ($_POST['body'] ?? ''))];
        $files = $this->incomingFiles(0, $uploadError);
        $error = $this->validateTitle($form['title']) ?? $this->validateBody($form['body']) ?? $uploadError ?? $this->floodError();
        if ($error !== null) {
            $this->render('forum/topic_form', ['board' => $board, 'form' => $form, 'error' => $error, 'attachmentsReady' => Forum::attachmentsReady()]);
            return;
        }

        $id = Forum::createTopic((int) $board['id'], $form['title'], $form['body'], Auth::id());
        Audit::log('forum_topic', $id, 'created', Auth::id(), ['title' => $form['title'], 'board' => $board['name']]);
        $fileErrors = $this->saveFiles(Forum::firstPostId($id), $files);
        \App\Services\NotificationService::forumTopicCreated($id, (int) Auth::id());
        $this->redirect('/forum/topics/' . $id, $fileErrors ? 'error' : 'success', $fileErrors ? 'Тему створено, але ' . implode(' ', $fileErrors) : 'Тему створено.');
    }

    public function reply(array $params): void
    {
        $this->requireReady();
        $topic = $this->topicOrFail((int) $params['id']);
        if (!$this->canReply($topic)) {
            $this->redirect('/forum/topics/' . $topic['id'], 'error', 'Тему закрито — нові відповіді неможливі.');
        }

        $body = $this->cleanBody((string) ($_POST['body'] ?? ''));
        $files = $this->incomingFiles(0, $uploadError);
        $error = $this->validateBody($body) ?? $uploadError ?? $this->floodError();
        if ($error !== null) {
            $this->renderTopic($topic, max(1, (int) ceil(($topic['reply_count'] + 1) / self::POSTS_PER_PAGE)), $error, $body);
            return;
        }

        $postId = Forum::addPost((int) $topic['id'], $body, Auth::id());
        Audit::log('forum_topic', (int) $topic['id'], 'reply_added', Auth::id(), ['post' => $postId]);
        $fileErrors = $this->saveFiles($postId, $files);
        \App\Services\NotificationService::forumReplyAdded((int) $topic['id'], $postId, (int) Auth::id());
        $page = Forum::pageOfPost((int) $topic['id'], $postId, self::POSTS_PER_PAGE);
        $query = ($page > 1 ? 'page=' . $page : '') . ($fileErrors ? ($page > 1 ? '&' : '') . http_build_query(['error' => 'Відповідь додано, але ' . implode(' ', $fileErrors)]) : '');
        header('Location: /forum/topics/' . (int) $topic['id'] . ($query !== '' ? '?' . $query : '') . '#post-' . $postId);
        exit;
    }

    // ------------------------------------------------------------------ редагування й видалення повідомлень

    public function editPost(array $params): void
    {
        $this->requireReady();
        $post = $this->postOrFail((int) $params['id']);
        $this->requireCanEditPost($post);
        $this->render('forum/post_form', $this->postFormData($post, $post['body'], null));
    }

    public function updatePost(array $params): void
    {
        $this->requireReady();
        $post = $this->postOrFail((int) $params['id']);
        $this->requireCanEditPost($post);

        $isFirst = Forum::firstPostId((int) $post['topic_id']) === (int) $post['id'];
        $body = $this->cleanBody((string) ($_POST['body'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? $post['topic_title']));
        $remove = array_map('intval', (array) ($_POST['remove_attachments'] ?? []));
        $keep = max(0, Forum::attachmentCount((int) $post['id']) - count($remove));
        $files = $this->incomingFiles($keep, $uploadError);
        $error = ($isFirst ? $this->validateTitle($title) : null) ?? $this->validateBody($body) ?? $uploadError;
        if ($error !== null) {
            $this->render('forum/post_form', $this->postFormData($post, $body, $error, $title));
            return;
        }

        $changes = [];
        if ($body !== $post['body']) {
            Forum::updatePost((int) $post['id'], $body, Auth::id());
            $changes['post'] = (int) $post['id'];
        }
        if ($isFirst && $title !== $post['topic_title']) {
            Forum::updateTitle((int) $post['topic_id'], $title);
            $changes['title'] = $title;
        }
        if ($remove) {
            $removed = Forum::removeAttachments((int) $post['id'], $remove);
            AttachmentService::deleteFiles(array_column($removed, 'stored_name'));
            if ($removed) {
                $changes['files_removed'] = array_column($removed, 'original_name');
            }
        }
        $fileErrors = $this->saveFiles((int) $post['id'], $files);
        if ($files && count($fileErrors) < count($files)) {
            $changes['files_added'] = count($files) - count($fileErrors);
        }
        if ($changes) {
            Audit::log('forum_topic', (int) $post['topic_id'], 'post_edited', Auth::id(), $changes);
        }
        if ($fileErrors) {
            $this->redirect('/forum/posts/' . $post['id'], 'error', 'Зміни збережено, але ' . implode(' ', $fileErrors));
        }
        $this->redirect('/forum/posts/' . $post['id'], 'success', 'Зміни збережено.');
    }

    public function deletePost(array $params): void
    {
        $this->requireReady();
        $post = $this->postOrFail((int) $params['id']);
        $topicId = (int) $post['topic_id'];
        $isFirst = Forum::firstPostId($topicId) === (int) $post['id'];
        $role = Auth::role();
        $isAuthor = $post['author_id'] !== null && (int) $post['author_id'] === Auth::id();

        if ($isFirst) {
            // Перше повідомлення = вся тема. Автор може прибрати її лише поки ніхто не відповів.
            $replies = Forum::postCount($topicId) - 1;
            if (!Forum::canModerate($role) && !($isAuthor && $replies === 0)) {
                $this->forbidden('Тему з відповідями видалити може лише модератор.');
            }
            $stored = Forum::storedNames('topic', $topicId);
            Forum::deleteTopic($topicId);
            AttachmentService::deleteFiles($stored);
            Audit::log('forum_topic', $topicId, 'deleted', Auth::id(), ['title' => $post['topic_title'], 'replies' => $replies]);
            $this->redirect('/forum/boards/' . (int) $post['board_id'], 'success', 'Тему «' . $post['topic_title'] . '» видалено.');
        }

        if (!Forum::canModerate($role) && !($isAuthor && !$post['topic_locked'])) {
            $this->forbidden('Видалити це повідомлення може лише його автор (поки тему не закрито) або модератор.');
        }
        $stored = Forum::storedNames('post', (int) $post['id']);
        Forum::deletePost((int) $post['id'], $topicId);
        AttachmentService::deleteFiles($stored);
        Audit::log('forum_topic', $topicId, 'post_deleted', Auth::id(), ['post' => (int) $post['id']]);
        $this->redirect('/forum/topics/' . $topicId, 'success', 'Повідомлення видалено.');
    }

    // ------------------------------------------------------------------ модерація тем

    public function pin(array $params): void
    {
        $topic = $this->moderatedTopic((int) $params['id']);
        $pinned = !$topic['is_pinned'];
        Forum::setPinned((int) $topic['id'], $pinned);
        Audit::log('forum_topic', (int) $topic['id'], $pinned ? 'pinned' : 'unpinned', Auth::id());
        $this->redirect('/forum/topics/' . $topic['id'], 'success', $pinned ? 'Тему закріплено.' : 'Тему відкріплено.');
    }

    public function lock(array $params): void
    {
        $topic = $this->moderatedTopic((int) $params['id']);
        $locked = !$topic['is_locked'];
        Forum::setLocked((int) $topic['id'], $locked);
        Audit::log('forum_topic', (int) $topic['id'], $locked ? 'locked' : 'unlocked', Auth::id());
        $this->redirect('/forum/topics/' . $topic['id'], 'success', $locked ? 'Тему закрито для відповідей.' : 'Тему знову відкрито.');
    }

    public function move(array $params): void
    {
        $topic = $this->moderatedTopic((int) $params['id']);
        $target = Forum::findBoard((int) ($_POST['board_id'] ?? 0));
        if (!$target || !Forum::boardVisibleTo($target, Auth::role())) {
            $this->redirect('/forum/topics/' . $topic['id'], 'error', 'Оберіть розділ, у який перенести тему.');
        }
        if ((int) $target['id'] !== (int) $topic['board_id']) {
            Forum::moveTopic((int) $topic['id'], (int) $target['id']);
            Audit::log('forum_topic', (int) $topic['id'], 'moved', Auth::id(), ['from_board' => $topic['board_name'], 'to_board' => $target['name']]);
        }
        $this->redirect('/forum/topics/' . $topic['id'], 'success', 'Тему перенесено до «' . $target['name'] . '».');
    }

    // ------------------------------------------------------------------ розділи (модератори)

    public function newBoard(): void
    {
        $this->requireReady();
        $this->requireModerator();
        $this->render('forum/board_form', $this->boardFormData(null, ['name' => '', 'description' => '', 'visibility' => 'all', 'sort_order' => 100, 'is_locked' => 0], null));
    }

    public function storeBoard(): void
    {
        $this->requireReady();
        $this->requireModerator();
        [$form, $error] = $this->parseBoard();
        if ($error === null && ($id = Forum::createBoard($form['name'], $form['description'] ?: null, $form['visibility'], $form['sort_order'], (bool) $form['is_locked'])) === null) {
            $error = 'Розділ «' . $form['name'] . '» уже існує.';
        }
        if ($error !== null) {
            $this->render('forum/board_form', $this->boardFormData(null, $form, $error));
            return;
        }
        Audit::log('forum_board', $id, 'created', Auth::id(), ['name' => $form['name']]);
        $this->redirect('/forum/boards/' . $id, 'success', 'Розділ створено.');
    }

    public function editBoard(array $params): void
    {
        $this->requireReady();
        $this->requireModerator();
        $board = $this->boardOrFail((int) $params['id']);
        $this->render('forum/board_form', $this->boardFormData($board, [
            'name' => $board['name'], 'description' => (string) $board['description'], 'visibility' => $board['visibility'],
            'sort_order' => (int) $board['sort_order'], 'is_locked' => (int) $board['is_locked'],
        ], null));
    }

    public function updateBoard(array $params): void
    {
        $this->requireReady();
        $this->requireModerator();
        $board = $this->boardOrFail((int) $params['id']);
        [$form, $error] = $this->parseBoard();
        if ($error === null && !Forum::updateBoard((int) $board['id'], $form['name'], $form['description'] ?: null, $form['visibility'], $form['sort_order'], (bool) $form['is_locked'])) {
            $error = 'Розділ «' . $form['name'] . '» уже існує.';
        }
        if ($error !== null) {
            $this->render('forum/board_form', $this->boardFormData($board, $form, $error));
            return;
        }
        Audit::log('forum_board', (int) $board['id'], 'updated', Auth::id(), ['name' => $form['name']]);
        $this->redirect('/forum/boards/' . $board['id'], 'success', 'Розділ збережено.');
    }

    public function deleteBoard(array $params): void
    {
        $this->requireReady();
        $this->requireModerator();
        $board = $this->boardOrFail((int) $params['id']);
        if (!Auth::hasRole(['admin'])) {
            $this->forbidden('Видалити розділ разом з усіма темами може лише адміністратор.');
        }
        $topics = Forum::topicCount((int) $board['id']);
        $stored = Forum::storedNames('board', (int) $board['id']);
        Forum::deleteBoard((int) $board['id']);
        AttachmentService::deleteFiles($stored);
        Audit::log('forum_board', (int) $board['id'], 'deleted', Auth::id(), ['name' => $board['name'], 'topics' => $topics]);
        $this->redirect('/forum', 'success', 'Розділ «' . $board['name'] . '» видалено разом з темами (' . $topics . ').');
    }

    // ------------------------------------------------------------------ допоміжне

    private function renderTopic(array $topic, int $page, ?string $error, string $replyBody): void
    {
        $total = (int) $topic['reply_count'] + 1;
        $totalPages = max(1, (int) ceil($total / self::POSTS_PER_PAGE));
        $page = min($totalPages, $page);
        $role = Auth::role();
        $renderer = new Markdown([]);

        $posts = Forum::posts((int) $topic['id'], $page, self::POSTS_PER_PAGE);
        if ($page === $totalPages && Auth::id() !== null) {
            Forum::markRead((int) $topic['id'], (int) Auth::id()); // тема дочитана до кінця
        }
        $firstId = Forum::firstPostId((int) $topic['id']);
        $files = Forum::attachmentsForPosts(array_column($posts, 'id'));
        foreach ($posts as &$p) {
            $p['attachments'] = $files[(int) $p['id']] ?? [];
            $p['html'] = $renderer->toHtml($p['body']);
            $p['is_first'] = (int) $p['id'] === $firstId;
            $p['can_edit'] = $this->canEditPost($p + ['topic_locked' => $topic['is_locked']]);
            $p['can_delete'] = Forum::canModerate($role)
                || ($p['author_id'] !== null && (int) $p['author_id'] === Auth::id()
                    && ($p['is_first'] ? (int) $topic['reply_count'] === 0 : !$topic['is_locked']));
        }
        unset($p);

        $this->render('forum/topic', [
            'topic' => $topic,
            'posts' => $posts,
            'page' => $page,
            'totalPages' => $totalPages,
            'canReply' => $this->canReply($topic),
            'canModerate' => Forum::canModerate($role),
            'moveTargets' => Forum::canModerate($role) ? Forum::moveTargets($role, (int) $topic['board_id']) : [],
            'error' => $error ?? ($_GET['error'] ?? null),
            'replyBody' => $replyBody,
            'attachmentsReady' => Forum::attachmentsReady(),
        ]);
    }

    private function canStartTopic(array $board): bool
    {
        return !$board['is_locked'] || Forum::canModerate(Auth::role());
    }

    private function canReply(array $topic): bool
    {
        return !$topic['is_locked'] || Forum::canModerate(Auth::role());
    }

    /** @param array<string, mixed> $post повідомлення; потрібні author_id і topic_locked */
    private function canEditPost(array $post): bool
    {
        if (Forum::canModerate(Auth::role())) {
            return true;
        }
        return $post['author_id'] !== null && (int) $post['author_id'] === Auth::id() && !$post['topic_locked'];
    }

    private function requireCanStart(array $board): void
    {
        if (!$this->canStartTopic($board)) {
            $this->forbidden('Розділ закрито для нових тем.');
        }
    }

    private function requireCanEditPost(array $post): void
    {
        if (!$this->canEditPost($post)) {
            $this->forbidden('Редагувати це повідомлення може лише його автор (поки тему не закрито) або модератор.');
        }
    }

    private function requireModerator(): void
    {
        if (!Forum::canModerate(Auth::role())) {
            $this->forbidden('Керувати форумом можуть адміністратор та IT-менеджер.');
        }
    }

    private function moderatedTopic(int $id): array
    {
        $this->requireReady();
        $topic = $this->topicOrFail($id);
        $this->requireModerator();
        return $topic;
    }

    /** Антифлуд для нових тем і відповідей (модератори не обмежуються). */
    private function floodError(): ?string
    {
        if (Forum::canModerate(Auth::role())) {
            return null;
        }
        $retry = RateLimiter::attempt('forum_post', 'user:' . (int) Auth::id());
        return $retry === null ? null : 'Забагато повідомлень за короткий час. Спробуйте ще раз приблизно через ' . max(1, (int) ceil($retry / 60)) . ' хв — текст збережено у формі.';
    }

    private function cleanBody(string $body): string
    {
        // Єдині переноси рядків; початковий відступ у Markdown значущий, тож обрізаємо лише кінцеві пробіли й порожні рядки.
        return rtrim(str_replace(["\r\n", "\r"], "\n", $body));
    }

    private function validateTitle(string $title): ?string
    {
        if ($title === '') {
            return 'Вкажіть заголовок теми.';
        }
        return mb_strlen($title) > Forum::MAX_TITLE ? 'Заголовок задовгий (не більше ' . Forum::MAX_TITLE . ' символів).' : null;
    }

    private function validateBody(string $body): ?string
    {
        if (trim($body) === '') {
            return 'Напишіть текст повідомлення.';
        }
        return mb_strlen($body) > Forum::MAX_BODY ? 'Повідомлення задовге (не більше ' . Forum::MAX_BODY . ' символів).' : null;
    }

    /** @return array{0: array{name: string, description: string, visibility: string, sort_order: int, is_locked: int}, 1: ?string} */
    private function parseBoard(): array
    {
        $form = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'visibility' => (string) ($_POST['visibility'] ?? 'all'),
            'sort_order' => max(0, min(9999, (int) ($_POST['sort_order'] ?? 100))),
            'is_locked' => empty($_POST['is_locked']) ? 0 : 1,
        ];
        $error = null;
        if ($form['name'] === '') {
            $error = 'Вкажіть назву розділу.';
        } elseif (mb_strlen($form['name']) > Forum::MAX_BOARD_NAME) {
            $error = 'Назва розділу задовга (не більше ' . Forum::MAX_BOARD_NAME . ' символів).';
        } elseif (mb_strlen($form['description']) > Forum::MAX_BOARD_DESCRIPTION) {
            $error = 'Опис задовгий (не більше ' . Forum::MAX_BOARD_DESCRIPTION . ' символів).';
        } elseif (!in_array($form['visibility'], Forum::allowedVisibilities(Auth::role()), true)) {
            $error = 'Недопустиме значення «Хто бачить».';
        }
        return [$form, $error];
    }

    private function boardFormData(?array $board, array $form, ?string $error): array
    {
        return [
            'board' => $board, 'form' => $form, 'error' => $error,
            'visibilities' => array_intersect_key(Forum::visibilities(), array_flip(Forum::allowedVisibilities(Auth::role()))),
        ];
    }

    // ------------------------------------------------------------------ попередній перегляд і файли

    /** HTML-фрагмент для кнопки «Попередній перегляд» (його вставляє public/assets/js/forum-editor.js). */
    public function preview(): void
    {
        $this->requireReady();
        $body = (string) ($_POST['body'] ?? '');
        header('Content-Type: text/html; charset=UTF-8');
        if (mb_strlen($body) > Forum::MAX_BODY) {
            http_response_code(413);
            echo '<div class="text-danger">Текст завеликий для перегляду.</div>';
            return;
        }
        echo trim($body) === '' ? '<div class="text-muted">Нічого показувати — текст порожній.</div>' : (new Markdown([]))->toHtml($body);
    }

    /** Віддає вкладення, якщо читачеві видимий розділ повідомлення (інакше 404, як і для самої теми). */
    public function file(array $params): void
    {
        $this->requireReady();
        $attachment = Forum::findAttachment((int) $params['id']);
        if (!$attachment || !in_array($attachment['board_visibility'], Forum::allowedVisibilities(Auth::role()), true)) {
            $this->notFound();
        }
        if (!in_array($attachment['mime_type'], LibraryService::INLINE_MIMES, true)) {
            $_GET['download'] = '1'; // docx/xlsx тощо — лише завантаження
        }
        AttachmentController::send($attachment);
    }

    /**
     * Файли з форми: перевіряє кількість і кожен файл ДО створення повідомлення (щоб не лишати наполовину збережене).
     * $uploadError — перша помилка (форма показує її й нічого не зберігає).
     *
     * @return array<int, array{name: string, tmp_name: string, error: int, size: int}>
     */
    private function incomingFiles(int $alreadyAttached, ?string &$uploadError): array
    {
        $uploadError = null;
        $files = [];
        foreach (AttachmentService::normalizeFiles($_FILES['files'] ?? []) as $f) {
            if ($f['error'] !== UPLOAD_ERR_NO_FILE) {
                $files[] = $f;
            }
        }
        if (!$files) {
            return [];
        }
        if (!Forum::attachmentsReady()) {
            $uploadError = 'Вкладення форуму ще не налаштовано: адміністратор має виконати update.sh (міграція 034).';
            return [];
        }
        if ($alreadyAttached + count($files) > Forum::MAX_FILES_PER_POST) {
            $uploadError = 'До повідомлення можна прикріпити не більше ' . Forum::MAX_FILES_PER_POST . ' файлів.';
            return [];
        }
        foreach ($files as $f) {
            $checked = LibraryService::validate($f);
            if (!$checked['ok']) {
                $uploadError = 'Файл «' . $f['name'] . '» не прийнято: ' . $checked['error'] . '. Повідомлення не збережено — додайте файли ще раз.';
                return [];
            }
        }
        return $files;
    }

    /** Зберігає вже перевірені файли; повертає тексти помилок (порожньо — усе гаразд). @return string[] */
    private function saveFiles(int $postId, array $files): array
    {
        $errors = [];
        foreach ($files as $f) {
            $stored = LibraryService::store($f);
            if (!$stored['ok']) {
                $errors[] = 'файл «' . $f['name'] . '» не збережено: ' . $stored['error'] . '.';
                continue;
            }
            try {
                Forum::addAttachment($postId, $stored['name'], $stored['stored_name'], $stored['mime'], $stored['size'], Auth::id());
            } catch (\Throwable $e) {
                AttachmentService::deleteFiles([$stored['stored_name']]);
                throw $e;
            }
            if (str_starts_with($stored['mime'], 'image/')) {
                AttachmentService::createThumbnail($stored['stored_name'], $stored['mime']);
            }
        }
        return $errors;
    }

    private function postFormData(array $post, string $body, ?string $error, ?string $title = null): array
    {
        return [
            'post' => $post, 'body' => $body, 'error' => $error,
            'title' => $title ?? $post['topic_title'],
            'isFirst' => Forum::firstPostId((int) $post['topic_id']) === (int) $post['id'],
            'attachments' => Forum::attachmentsForPosts([(int) $post['id']])[(int) $post['id']] ?? [],
            'attachmentsReady' => Forum::attachmentsReady(),
        ];
    }

    /**
     * Показує шаблон. Дані виклику мають ПРІОРИТЕТ над типовими (оператор «+» лишає значення зліва), тому власна
     * помилка форми не затирається ?error=… з адреси.
     */
    private function render(string $template, array $data): void
    {
        View::render($template, $data + [
            'canModerate' => Forum::canModerate(Auth::role()),
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
        ]);
    }

    private function boardOrFail(int $id): array
    {
        $board = Forum::findBoard($id);
        if (!$board || !Forum::boardVisibleTo($board, Auth::role())) {
            $this->notFound();
        }
        return $board;
    }

    private function topicOrFail(int $id): array
    {
        $topic = Forum::findTopic($id);
        if (!$topic || !Forum::topicVisibleTo($topic, Auth::role())) {
            $this->notFound();
        }
        return $topic;
    }

    private function postOrFail(int $id): array
    {
        $post = Forum::findPost($id);
        if (!$post || !in_array($post['board_visibility'], Forum::allowedVisibilities(Auth::role()), true)) {
            $this->notFound();
        }
        return $post;
    }

    private function requireReady(): void
    {
        Auth::requireLogin();
        if (!Forum::tablesExist()) {
            http_response_code(503);
            View::render('forum/unavailable', []);
            exit;
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
        echo 'Не знайдено.';
        exit;
    }

    private function forbidden(string $message): never
    {
        http_response_code(403);
        echo $message;
        exit;
    }
}
