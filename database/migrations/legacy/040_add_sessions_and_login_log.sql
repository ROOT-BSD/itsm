-- Міграція 040: активні сесії користувачів і журнал входів.
--
--  * user_sessions — по рядку на кожну веб-сесію (ідентифікатор сесії зберігається лише як SHA-256): IP, браузер,
--    початок, остання активність, ознака завершення. Дає змогу бачити «де я ввійшов» і завершити чужу сесію.
--  * login_log — журнал спроб входу (успішних і невдалих): коли, хто, звідки, чим (пароль / AD / SSO), причина відмови.
--
-- На новій інсталяції (schema.sql "з нуля") усе вже є.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/legacy/040_add_sessions_and_login_log.sql
--
-- Скрипт безпечний для повторного запуску.

CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_hash CHAR(64) NOT NULL,
    ip VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    ended_reason VARCHAR(20) NULL,
    ended_by INT NULL,
    UNIQUE KEY uq_user_sessions_hash (session_hash),
    KEY idx_user_sessions_user (user_id, ended_at, last_seen_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (ended_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    login VARCHAR(190) NOT NULL,
    method VARCHAR(10) NOT NULL,
    success TINYINT(1) NOT NULL,
    reason VARCHAR(30) NULL,
    ip VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_log_created (created_at),
    KEY idx_login_log_user (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
