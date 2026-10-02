-- Міграція 014: гранульовані перемикачі email-сповіщень.
--
-- Замінює одну спільну галочку "email_notifications_enabled" на чотири
-- незалежні: тікети, проєкти, задачі, нагадування про термін. Стару
-- галочку з app_settings НЕ видаляємо (просто більше не читається кодом) —
-- видаляти налаштування міграцією зайве й ризиковано.
--
-- Якщо стара галочка вже була увімкнена (1) — усі чотири нові одразу
-- стають увімкненими, щоб оновлення не вимкнуло сповіщення, які
-- адміністратор уже свідомо ввімкнув. Якщо не була, або install новий —
-- усі чотири вимкнені за замовчуванням, як і було.
--
-- На новій інсталяції (schema.sql "з нуля" + seed.sql) усе це вже включене,
-- міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/014_add_granular_notification_settings.sql
--
-- Скрипт безпечний для повторного запуску (INSERT IGNORE — не перезаписує,
-- якщо адміністратор уже змінив якусь із чотирьох окремо).

SET @old_enabled = (SELECT setting_value FROM app_settings WHERE setting_key = 'email_notifications_enabled' LIMIT 1);
SET @old_enabled = IFNULL(@old_enabled, '0');

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
('email_notify_tickets_enabled', @old_enabled),
('email_notify_projects_enabled', @old_enabled),
('email_notify_tasks_enabled', @old_enabled),
('email_notify_reminders_enabled', @old_enabled);
