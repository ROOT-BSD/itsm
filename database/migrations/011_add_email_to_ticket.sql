-- Міграція 011: email-to-ticket — журнал обробки пошти та налаштування.
--
-- Додає:
--   1. Таблицю email_ingest_log (захист від дублів, причини пропуску,
--      зв'язок Message-ID → тікет для гілок листування);
--   2. Налаштування email_ticket_enabled / email_ticket_queue_id в app_settings.
--
-- На новій інсталяції (schema.sql "з нуля") усе це вже включене, міграція
-- не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/011_add_email_to_ticket.sql
--
-- Скрипт безпечний для повторного запуску (IF NOT EXISTS / INSERT IGNORE).

CREATE TABLE IF NOT EXISTS email_ingest_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(255) NOT NULL,
    from_email VARCHAR(150) NOT NULL DEFAULT '',
    subject VARCHAR(255) NOT NULL DEFAULT '',
    result ENUM('ticket_created','comment_added','skipped') NOT NULL,
    ticket_id INT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_email_ingest_message (message_id),
    KEY idx_email_ingest_created (created_at),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Не перезаписуємо, якщо адміністратор уже змінив.
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
('email_ticket_enabled', '0'),
('email_ticket_queue_id', '0');
