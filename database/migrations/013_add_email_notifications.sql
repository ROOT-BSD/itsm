-- Міграція 013: email-сповіщення (активність тікетів, створення/призначення
-- проєктів і задач, нагадування про термін виконання).
--
-- Додає:
--   1. Таблицю task_due_reminders — щоб те саме нагадування (2 дні / 1 день /
--      настав термін) не надсилалось повторно;
--   2. Налаштування email_notifications_enabled в app_settings (вимкнено за
--      замовчуванням — доки адміністратор явно не увімкне на сторінці
--      «Пошта → тікети» після перевірки SMTP).
--
-- На новій інсталяції (schema.sql "з нуля" + seed.sql) усе це вже включене,
-- міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/013_add_email_notifications.sql
--
-- Скрипт безпечний для повторного запуску (IF NOT EXISTS / INSERT IGNORE).

CREATE TABLE IF NOT EXISTS task_due_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    reminder_type ENUM('2d','1d','due') NOT NULL,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_task_reminder (task_id, reminder_type),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
('email_notifications_enabled', '0');
