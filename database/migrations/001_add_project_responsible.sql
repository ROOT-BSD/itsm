-- Міграція 001: додає можливість призначати відповідального (виконавця) проєкту.
-- Потрібна ЛИШЕ для систем, розгорнутих ДО цієї зміни — на новій інсталяції
-- (schema.sql застосовується "з нуля") це поле вже включене, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p ІМ_Я_БД < database/migrations/001_add_project_responsible.sql
--
-- Скрипт безпечний для повторного запуску: перевіряє, чи колонка вже існує.

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'projects'
      AND COLUMN_NAME = 'responsible_user_id'
);

SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE projects
        ADD COLUMN responsible_user_id INT NULL AFTER created_by,
        ADD CONSTRAINT fk_projects_responsible_user
            FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT "Колонка responsible_user_id вже існує — міграцію пропущено" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
