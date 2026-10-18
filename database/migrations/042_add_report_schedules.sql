-- Міграція 042: звіти за розкладом.
--
--  * report_schedules — збережений розклад звіту обліку часу: раз на тиждень (звіт за попередній тиждень) або раз на місяць
--    (за попередній місяць). Надсилається вкладенням (PDF/XLSX/CSV) власнику й додатковим одержувачам скриптом bin/send-reports.php.
--    Звіт будується з правами власника (бачить лише доступні йому проєкти). last_run_at при створенні = момент створення,
--    щоб новий розклад не спрацював «заднім числом» за поточний період.
--
-- Скрипт безпечний для повторного запуску.

CREATE TABLE IF NOT EXISTS report_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    frequency ENUM('weekly','monthly') NOT NULL DEFAULT 'weekly',
    weekday TINYINT NOT NULL DEFAULT 1,          -- 1 = понеділок … 7 = неділя (для weekly)
    month_day TINYINT NOT NULL DEFAULT 1,        -- 1…28 (для monthly)
    send_hour TINYINT NOT NULL DEFAULT 8,        -- 0…23, не раніше цієї години
    format ENUM('pdf','xlsx','csv') NOT NULL DEFAULT 'pdf',
    project_id INT NULL,
    log_user_id INT NULL,
    category VARCHAR(100) NULL,
    extra_recipients TEXT NULL,                  -- email-и через кому (понад власника)
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at DATETIME NULL,
    last_status VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_report_schedules_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (log_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
