-- Міграція 033: позначки «непрочитане» на форумі.
--
-- forum_topic_reads — коли користувач востаннє дочитав тему до кінця (відкрив її останню сторінку);
-- forum_read_marks  — «усе прочитано до цього моменту» (кнопка «Позначити все прочитаним»).
-- Тема непрочитана, якщо в ній є повідомлення новіші за пізнішу з цих двох відміток (для нового користувача —
-- за дату створення облікового запису), і останнє повідомлення написав не сам користувач.
--
-- Щоб після оновлення весь наявний форум не засвітився «новим», для ВСІХ наявних користувачів одразу ставиться
-- відмітка «усе прочитано» на момент міграції; непрочитаним стане лише те, що з'явиться після неї.
-- Записи видаляються разом з користувачем чи темою.
--
-- На новій інсталяції (schema.sql "з нуля") таблиці вже є, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/033_add_forum_unread.sql
--
-- Скрипт безпечний для повторного запуску (відмітки наявних користувачів не перезаписуються).

CREATE TABLE IF NOT EXISTS forum_topic_reads (
    user_id INT NOT NULL,
    topic_id INT NOT NULL,
    read_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, topic_id),
    CONSTRAINT fk_forum_reads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_forum_reads_topic FOREIGN KEY (topic_id) REFERENCES forum_topics(id) ON DELETE CASCADE,
    INDEX idx_forum_reads_topic (topic_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS forum_read_marks (
    user_id INT NOT NULL PRIMARY KEY,
    read_all_at DATETIME NOT NULL,
    CONSTRAINT fk_forum_marks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO forum_read_marks (user_id, read_all_at) SELECT id, NOW() FROM users;
