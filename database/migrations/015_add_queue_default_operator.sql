-- Міграція 015: автопризначення оператора для черги тікетів.
--
-- Додає ticket_queues.default_operator_id — якщо задано, новий тікет у цій
-- черзі одразу отримує цього оператора (без ручного призначення).
-- Налаштовується в Адмін-панель → Черги тікетів.
--
-- На новій інсталяції (schema.sql "з нуля") колонка вже включена, міграція
-- не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/015_add_queue_default_operator.sql
--
-- Скрипт безпечний для повторного запуску: перевіряє, чи колонка вже існує.

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_queues' AND COLUMN_NAME = 'default_operator_id'
);

SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE ticket_queues
        ADD COLUMN default_operator_id INT NULL,
        ADD FOREIGN KEY (default_operator_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT "Колонка ticket_queues.default_operator_id вже існує — міграцію пропущено" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
