-- Міграція 032: видалення поля «Видимість» проєкту (projects.visibility).
--
-- Поле (публічний / приватний / обмежений) ніколи не впливало на те, хто бачить проєкт: доступ визначають автор,
-- відповідальний та учасники проєкту (а для «Адміністратора Підрозділу» — ще й люди його підрозділу). Тому стовпець
-- й елементи керування ним у формі створення проєкту та в «Адмін → Проєкти» прибрано; з API-відповіді зникло поле
-- "visibility" проєкту.
--
-- Дані стовпця більше ніде не використовуються, тож видалення безпечне. Резервна копія БД перед оновленням — як завжди.
-- Старі записи журналу аудиту visibility_changed_to_* лишаються як є.
--
-- На новій інсталяції (schema.sql "з нуля") стовпця вже немає, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/032_drop_project_visibility.sql
--
-- Скрипт безпечний для повторного запуску.

SET @has_vis := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'visibility');
SET @ddl := IF(@has_vis > 0,
    'ALTER TABLE projects DROP COLUMN visibility',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
