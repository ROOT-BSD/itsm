-- Міграція 019: attachments.source — звідки прийшло вкладення.
--
-- Значення: 'web' (сторінка чи форма системи), 'portal' (анонімний портал самообслуговування),
-- 'email' (зображення з вхідного листа, email-to-ticket). Потрібне, щоб на сторінці тікета
-- показувати, хто саме додав файл, коли в нього немає облікового запису (uploaded_by = NULL).
-- Для вже наявних вкладень значенням стає 'web'.
--
-- На новій інсталяції (schema.sql "з нуля") колонка вже включена, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/019_add_attachment_source.sql
--
-- Скрипт безпечний для повторного запуску: перевіряє, чи колонка вже існує.

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attachments' AND COLUMN_NAME = 'source'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE attachments ADD COLUMN source VARCHAR(10) NOT NULL DEFAULT ''web'' AFTER uploaded_by',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
