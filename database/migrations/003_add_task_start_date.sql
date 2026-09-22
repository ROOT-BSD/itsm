-- Міграція 003: додає колонку start_date до задач (потрібна для діаграми Ганта).
-- На новій інсталяції (schema.sql "з нуля") це поле вже включене, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/003_add_task_start_date.sql
--
-- Скрипт безпечний для повторного запуску: перевіряє, чи колонка вже існує.

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tasks'
      AND COLUMN_NAME = 'start_date'
);

SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tasks ADD COLUMN start_date DATE NULL AFTER assignee_id',
    'SELECT "Колонка start_date вже існує — міграцію пропущено" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
