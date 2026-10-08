-- Міграція 027: ручне перевизначення ролі та деактивації AD-користувача, яке синхронізація не скасовує.
--
--   users.ad_role_locked  = 1 — синхронізація не змінює роль (її задано вручну);
--   users.ad_blocked      = 1 — синхронізація не вмикає користувача знову, навіть якщо він є в AD.
--
-- Наявні користувачі отримують 0 (поведінка не змінюється). Локальних акаунтів прапорці не стосуються.
--
-- На новій інсталяції (schema.sql "з нуля") зміни вже включені, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/027_add_ad_manual_overrides.sql
--
-- Скрипт безпечний для повторного запуску.

SET @has_role_locked := (SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ad_role_locked');
SET @ddl := IF(@has_role_locked = 0,
    'ALTER TABLE users ADD COLUMN ad_role_locked TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_blocked := (SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ad_blocked');
SET @ddl := IF(@has_blocked = 0,
    'ALTER TABLE users ADD COLUMN ad_blocked TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
