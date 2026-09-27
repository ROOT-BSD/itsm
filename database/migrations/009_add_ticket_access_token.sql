-- Міграція 009: додає tickets.access_token для порталу самообслуговування
-- (подання й відстеження звернення без входу в систему).
--
-- НЕ заповнює токенами вже наявні тікети — їм токен не потрібен, бо вони
-- створені через звичайний (авторизований) інтерфейс і переглядаються
-- через /tickets/{id}, а не через публічне посилання відстеження.
--
-- На новій інсталяції (schema.sql "з нуля") колонка вже включена, міграція
-- не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/009_add_ticket_access_token.sql
--
-- Скрипт безпечний для повторного запуску: перевіряє, чи колонка вже існує.

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'access_token'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE tickets ADD COLUMN access_token VARCHAR(64) NULL UNIQUE AFTER project_id',
    'SELECT "Колонка tickets.access_token вже існує — міграцію пропущено" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
