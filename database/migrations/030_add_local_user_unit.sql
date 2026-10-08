-- Міграція 030: організаційний підрозділ для ЛОКАЛЬНИХ користувачів (для ролі «Адміністратор Підрозділу»).
--
-- users.unit_ou — шлях підрозділу в тому самому форматі, що й AD OU ("OU=IT,OU=Kyiv": від листа до кореня).
-- Для AD-користувачів підрозділ, як і раніше, береться з users.ad_ou (синхронізація); unit_ou їх не стосується.
-- Локальному користувачеві підрозділ задає адміністратор системи (Користувачі → Редагувати) — його можна
-- вказати і з тим самим шляхом, що й у AD (тоді локальні й AD-користувачі опиняються в одному підрозділі).
--
-- На новій інсталяції (schema.sql "з нуля") зміни вже включені, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/030_add_local_user_unit.sql
--
-- Скрипт безпечний для повторного запуску.

SET @has_unit := (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'unit_ou');
SET @ddl := IF(@has_unit = 0,
    'ALTER TABLE users ADD COLUMN unit_ou VARCHAR(500) NULL',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
