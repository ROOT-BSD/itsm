-- Міграція 034: вкладення в повідомленнях форуму.
--
-- Файл прикріплюється до конкретного повідомлення (до 5 штук). Права на файл = права на розділ повідомлення
-- (як і для самої теми); сам файл лежить у storage/uploads під випадковим іменем. Записи видаляються разом із
-- повідомленням (каскадом); файли з диска прибирає застосунок.
--
-- На новій інсталяції (schema.sql "з нуля") таблиця вже є, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/034_add_forum_attachments.sql
--
-- Скрипт безпечний для повторного запуску.

CREATE TABLE IF NOT EXISTS forum_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT NOT NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_forum_attachments_post FOREIGN KEY (post_id) REFERENCES forum_posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_forum_attachments_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_forum_attachments_post (post_id)
) ENGINE=InnoDB;
