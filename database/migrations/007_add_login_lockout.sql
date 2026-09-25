-- Міграція 007: блокування облікового запису після N невдалих спроб входу.
--
-- Додає:
--   1. Колонки users.failed_login_attempts, users.locked_until
--   2. Таблицю app_settings (ключ-значення) з дефолтними параметрами блокування
--
-- На новій інсталяції (schema.sql "з нуля") усе це вже включене, міграція
-- не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/007_add_login_lockout.sql
--
-- Скрипт безпечний для повторного запуску: перевіряє, чи об'єкти вже існують.

-- 1. Колонки в users
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'failed_login_attempts'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE users
        ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0,
        ADD COLUMN locked_until DATETIME NULL',
    'SELECT "Колонки блокування вже існують — цю частину міграції пропущено" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Таблиця app_settings
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- 3. Дефолтні значення (лише якщо їх ще немає — не перезаписуємо, якщо адмін уже змінив)
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
('max_login_attempts', '5'),
('lockout_minutes', '15');
