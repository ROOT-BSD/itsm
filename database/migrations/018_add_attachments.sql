-- Міграція 018: вкладення до тікетів і задач (модуль «файлові документи»).
--
-- Створює таблицю attachments нової структури. Самі файли зберігаються на диску в
-- storage/uploads (директорія має бути доступна для запису веб-серверу — update.sh це налаштовує).
--
-- УВАГА: у попередніх версіях схеми вже була таблиця attachments — порожня заготовка зі
-- стовпцями file_name/file_path/file_size, яку жоден код не використовував. Звичайний
-- CREATE TABLE IF NOT EXISTS її б мовчки пропустив, лишивши нову функцію без таблиці,
-- тому спершу ця заготовка замінюється:
--   • порожня (так і має бути) — видаляється;
--   • раптом містить рядки — НЕ видаляється, а перейменовується в attachments_legacy.
--
-- На новій інсталяції (schema.sql "з нуля") усе це вже включене, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/018_add_attachments.sql
--
-- Скрипт безпечний для повторного запуску: нову таблицю (є стовпець stored_name) не чіпає.

SET @has_table = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attachments'
);
SET @is_new = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attachments' AND COLUMN_NAME = 'stored_name'
);

SET @legacy_rows = 0;
SET @sql = IF(
    @has_table = 1 AND @is_new = 0,
    'SELECT COUNT(*) INTO @legacy_rows FROM attachments',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = CASE
    WHEN @has_table = 1 AND @is_new = 0 AND @legacy_rows = 0 THEN 'DROP TABLE attachments'
    WHEN @has_table = 1 AND @is_new = 0 AND @legacy_rows > 0 THEN 'RENAME TABLE attachments TO attachments_legacy'
    ELSE 'DO 0'
END;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NULL,
    task_id INT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name CHAR(32) NOT NULL UNIQUE,
    mime_type VARCHAR(50) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attachments_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_attachments_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_attachments_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_attachments_ticket (ticket_id),
    INDEX idx_attachments_task (task_id)
) ENGINE=InnoDB;
