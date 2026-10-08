-- Міграція 025: форуми (розділ «Форум») — обговорення між користувачами: розділи → теми → повідомлення.
--
-- Нічого не змінює в наявних даних і поведінці: форум порожній, доки адміністратор не створить перший розділ.
--
-- На новій інсталяції (schema.sql "з нуля") таблиці вже включені, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/025_add_forum.sql
--
-- Скрипт безпечний для повторного запуску (CREATE TABLE IF NOT EXISTS).

-- Розділи форуму. visibility: all / staff / admin — ті самі значення й правила, що у вікі та бібліотеки документів.
-- is_locked: розділ закритий для нових тем (тема від модератора можлива; наявні теми читаються).
CREATE TABLE IF NOT EXISTS forum_boards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(500) NULL,
    visibility VARCHAR(10) NOT NULL DEFAULT 'all',
    sort_order INT NOT NULL DEFAULT 100,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Теми. reply_count / last_post_at / last_post_by — похідні величини (перераховуються з повідомлень після кожної зміни),
-- збережені, щоб список тем не рахував повідомлення на кожен рядок. is_pinned — закріплена вгорі; is_locked — нові відповіді закриті.
CREATE TABLE IF NOT EXISTS forum_topics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    author_id INT NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    reply_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_post_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_post_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_forum_topics_board FOREIGN KEY (board_id) REFERENCES forum_boards(id) ON DELETE CASCADE,
    CONSTRAINT fk_forum_topics_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_forum_topics_last_by FOREIGN KEY (last_post_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_forum_topics_board (board_id, is_pinned, last_post_at)
) ENGINE=InnoDB;

-- Повідомлення. Перше повідомлення теми (найменший id) — її початковий текст. Текст — Markdown (безпечна підмножина).
CREATE TABLE IF NOT EXISTS forum_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    topic_id INT NOT NULL,
    author_id INT NULL,
    body MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at DATETIME NULL,
    edited_by INT NULL,
    CONSTRAINT fk_forum_posts_topic FOREIGN KEY (topic_id) REFERENCES forum_topics(id) ON DELETE CASCADE,
    CONSTRAINT fk_forum_posts_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_forum_posts_edited FOREIGN KEY (edited_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_forum_posts_topic (topic_id, id)
) ENGINE=InnoDB;
