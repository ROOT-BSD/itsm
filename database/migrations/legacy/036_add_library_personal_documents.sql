-- Міграція 036: особисті документи та документи підрозділу в бібліотеці.
--
-- Кожен користувач (крім заявників) може додати документ для себе (visibility = 'private') або відкрити його своєму
-- підрозділу (visibility = 'unit'). library_documents.unit_ou — підрозділ автора на момент відкриття доступу, у форматі
-- users.unit_ou / AD OU ("OU=IT,OU=Kyiv"): документ бачать автор і користувачі цього підрозділу (разом із вкладеними).
-- Значення visibility 'all' / 'staff' / 'admin' (документи адміністратора й IT-менеджера) працюють як і раніше.
--
-- На новій інсталяції (schema.sql "з нуля") зміни вже включені, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/legacy/036_add_library_personal_documents.sql
--
-- Скрипт безпечний для повторного запуску.

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'library_documents' AND COLUMN_NAME = 'unit_ou');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE library_documents ADD COLUMN unit_ou VARCHAR(500) NULL AFTER visibility, ADD INDEX idx_library_documents_creator (created_by)',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
