-- Міграція 008: додає необов'язкове поле tickets.project_id.
--
-- Дозволяє прив'язати звернення (тікет) до конкретного проєкту — наприклад,
-- коли проблема стосується саме роботи над проєктом, а не загального ІТ-запиту.
-- NULL (за замовчуванням) означає "без прив'язки" — так поводились усі
-- тікети до цієї міграції, і так продовжують поводитись, якщо прив'язку
-- не вказати явно.
--
-- На новій інсталяції (schema.sql "з нуля") колонка вже включена, міграція
-- не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/008_add_ticket_project_link.sql
--
-- Скрипт безпечний для повторного запуску: перевіряє, чи колонка вже існує.

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'project_id'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE tickets
        ADD COLUMN project_id INT NULL AFTER queue_id,
        ADD FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL',
    'SELECT "Колонка tickets.project_id вже існує — міграцію пропущено" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
