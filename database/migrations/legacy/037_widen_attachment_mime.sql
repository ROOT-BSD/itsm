-- Міграція 037: ширший тип файлу у вкладеннях (attachments.mime_type 50 → 100 символів).
--
-- Документи Office мають довгі MIME-типи (наприклад, application/vnd.openxmlformats-officedocument.wordprocessingml.document —
-- 71 символ), тож до задач тепер можна прикріплювати DOCX, XLSX, PPTX, ODT, ODS, ODP. Без цієї міграції система працює як
-- раніше (лише JPG, PNG, PDF), а завантаження Office-файлів у задачі вимкнене.
--
-- На новій інсталяції (schema.sql "з нуля") зміни вже включені, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/legacy/037_widen_attachment_mime.sql
--
-- Скрипт безпечний для повторного запуску.

SET @len := (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attachments' AND COLUMN_NAME = 'mime_type');
SET @ddl := IF(@len IS NOT NULL AND @len < 100,
    'ALTER TABLE attachments MODIFY mime_type VARCHAR(100) NOT NULL',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
