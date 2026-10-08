-- Міграція 026: вікі — ієрархія сторінок (батьківська сторінка) і вкладення (зображення та файли до сторінки).
--
-- Наявні сторінки лишаються як є (parent_id = NULL — верхній рівень).
--
-- На новій інсталяції (schema.sql "з нуля") зміни вже включені, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/026_add_wiki_hierarchy_and_attachments.sql
--
-- Скрипт безпечний для повторного запуску (перевіряє наявність колонки; CREATE TABLE IF NOT EXISTS).

-- parent_id: батьківська сторінка. При видаленні батька код переносить дітей на рівень вище; ON DELETE SET NULL — страховка.
SET @has_parent := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wiki_pages' AND COLUMN_NAME = 'parent_id');
SET @ddl := IF(@has_parent = 0,
    'ALTER TABLE wiki_pages
        ADD COLUMN parent_id INT NULL AFTER slug,
        ADD INDEX idx_wiki_pages_parent (parent_id),
        ADD CONSTRAINT fk_wiki_pages_parent FOREIGN KEY (parent_id) REFERENCES wiki_pages(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Вкладення сторінки. Файли лежать у storage/uploads (поза веб-коренем) під випадковими іменами, як і решта файлів;
-- віддаються через /wiki/files/{id} з перевіркою видимості САМОЇ сторінки. Видалення сторінки видаляє записи
-- (каскадом), а файли з диска прибирає код.
CREATE TABLE IF NOT EXISTS wiki_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_id INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name CHAR(32) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT NOT NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wiki_attachments_page FOREIGN KEY (page_id) REFERENCES wiki_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_wiki_attachments_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_wiki_attachments_page (page_id)
) ENGINE=InnoDB;
