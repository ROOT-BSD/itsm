-- Міграція 028: стабільний ідентифікатор AD-користувача (objectGUID) поряд з email.
--
-- users.ad_guid — канонічний GUID (36 символів, нижній регістр, напр. 3f2504e0-4f89-11d3-9a0c-0305e82c3301).
-- Синхронізація шукає користувача спершу за GUID, потім за email; знайшовши за email — запам'ятовує GUID.
-- Тому зміна email в AD більше не створює дубль облікового запису, а оновлює існуючий.
--
-- Наявні AD-користувачі отримують GUID автоматично при наступній синхронізації (за збігом email).
-- Локальні акаунти мають NULL.
--
-- На новій інсталяції (schema.sql "з нуля") зміни вже включені, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/028_add_ad_guid.sql
--
-- Скрипт безпечний для повторного запуску.

SET @has_guid := (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ad_guid');
SET @ddl := IF(@has_guid = 0,
    'ALTER TABLE users ADD COLUMN ad_guid CHAR(36) NULL, ADD UNIQUE INDEX uq_users_ad_guid (ad_guid)',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
