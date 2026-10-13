-- Міграція 035: відписка від email-сповіщень по окремій темі форуму.
--
-- За замовчуванням про відповіді в темі сповіщаються її автор і всі, хто в ній писав. Запис у цій таблиці означає
-- «цей користувач не хоче листів про відповіді в цій темі» (кнопка «Не сповіщати про цю тему» на сторінці теми
-- і посилання в листі). Записи видаляються разом з користувачем чи темою.
--
-- На новій інсталяції (schema.sql "з нуля") таблиця вже є, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/035_add_forum_topic_mutes.sql
--
-- Скрипт безпечний для повторного запуску.

CREATE TABLE IF NOT EXISTS forum_topic_mutes (
    user_id INT NOT NULL,
    topic_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, topic_id),
    CONSTRAINT fk_forum_mutes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_forum_mutes_topic FOREIGN KEY (topic_id) REFERENCES forum_topics(id) ON DELETE CASCADE,
    INDEX idx_forum_mutes_topic (topic_id)
) ENGINE=InnoDB;
