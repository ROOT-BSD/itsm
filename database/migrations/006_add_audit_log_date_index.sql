-- Міграція 006: додає індекс на audit_log.created_at.
--
-- Нова сторінка перегляду журналу аудиту (/admin/audit) сортує записи за
-- датою й фільтрує за діапазоном дат на кожному запиті. На новій
-- інсталяції (schema.sql "з нуля") індекс уже включений, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/006_add_audit_log_date_index.sql
--
-- Скрипт безпечний для повторного запуску: перевіряє, чи індекс уже існує.

SET @index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'audit_log'
      AND INDEX_NAME = 'idx_audit_log_created_at'
);

SET @sql = IF(
    @index_exists = 0,
    'ALTER TABLE audit_log ADD INDEX idx_audit_log_created_at (created_at)',
    'SELECT "Індекс idx_audit_log_created_at вже існує — міграцію пропущено" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
