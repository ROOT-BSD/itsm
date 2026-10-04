-- Міграція 016: інтеграція з Active Directory.
--
-- Додає:
--   1. users.ad_username — атрибут AD (типово sAMAccountName), яким система
--      шукає запис у каталозі при вході; email лишається тим, що людина
--      вводить у формі логіну, і не обов'язково збігається з username AD.
--   2. Таблицю ad_group_role_mapping — відповідність груп AD локальним ролям
--      для синхронізації (bin/sync-ad-users.php).
--   3. Налаштування ad_sync_enabled (Адмін-панель → Active Directory).
--
-- На новій інсталяції (schema.sql "з нуля" + seed.sql) усе це вже включене,
-- міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/016_add_active_directory.sql
--
-- Скрипт безпечний для повторного запуску (перевіряє існування колонки/таблиці/налаштування).

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ad_username'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE users ADD COLUMN ad_username VARCHAR(100) NULL',
    'SELECT "users.ad_username вже існує — пропущено" AS status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ad_group_role_mapping (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad_group VARCHAR(255) NOT NULL UNIQUE,
    role_id INT NOT NULL,
    rank INT NOT NULL DEFAULT 100,
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
('ad_sync_enabled', '0');
