-- Міграція 005: додає індекс на time_logs.log_date.
--
-- Ця колонка активно фільтрується (WHERE log_date BETWEEN ...) і
-- групується (GROUP BY по дню/тижню/місяцю) практично в кожному звіті
-- обліку часу (сторінки /projects/{id}/time, /admin/time, /reports).
-- На відміну від task_id/user_id (зовнішні ключі — InnoDB індексує їх
-- автоматично), log_date — звичайна колонка без індексу за замовчуванням.
--
-- На новій інсталяції (schema.sql "з нуля") індекс уже включений,
-- міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/005_add_time_logs_date_index.sql
--
-- Скрипт безпечний для повторного запуску: перевіряє, чи індекс уже існує.

SET @index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'time_logs'
      AND INDEX_NAME = 'idx_time_logs_log_date'
);

SET @sql = IF(
    @index_exists = 0,
    'ALTER TABLE time_logs ADD INDEX idx_time_logs_log_date (log_date)',
    'SELECT "Індекс idx_time_logs_log_date вже існує — міграцію пропущено" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
