-- Міграція 017: колонка ad_ou у users (організаційний підрозділ AD, OU).
--
-- Зберігає шлях OU з DN користувача в AD (лише компоненти OU=, без CN-контейнерів
-- і DC=; напр. "OU=IT,OU=Kyiv") — за ним сторінка «Користувачі» групує AD-акаунти.
-- Заповнюється під час синхронізації (bin/sync-ad-users.php); для вже синхронізованих
-- раніше користувачів стане порожнім до наступного запуску.
--
-- Видаляє проміжні колонки з попередніх варіантів цієї функції, якщо вони є:
--   ad_container  — групування за повним шляхом із CN-контейнерами (замінено на ad_ou)
--   ad_department — групування за атрибутом AD «department» (відкинуто)
-- Це похідні дані, їх повністю відтворює AD при наступній синхронізації.
--
-- На новій інсталяції (schema.sql "з нуля") усе це вже включене.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/017_add_user_ad_ou.sql
--
-- Скрипт безпечний для повторного запуску: перевіряє наявність кожної колонки.

SET @ou_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ad_ou'
);
SET @sql = IF(
    @ou_exists = 0,
    'ALTER TABLE users ADD COLUMN ad_ou VARCHAR(500) NULL',
    'SELECT "users.ad_ou вже існує — пропущено" AS status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @container_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ad_container'
);
SET @sql = IF(
    @container_exists = 1,
    'ALTER TABLE users DROP COLUMN ad_container',
    'SELECT "users.ad_container відсутня — нічого видаляти" AS status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @dept_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ad_department'
);
SET @sql = IF(
    @dept_exists = 1,
    'ALTER TABLE users DROP COLUMN ad_department',
    'SELECT "users.ad_department відсутня — нічого видаляти" AS status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
