-- Міграція 023: лічильники обмеження частоти запитів до публічного порталу /support.
--
-- Нічого не змінює в наявних даних. Без цієї таблиці система працює як раніше (обмежувач «відкривається»
-- і запити не блокує), але захисту від флуду порталу не буде.
--
-- На новій інсталяції (schema.sql "з нуля") таблиця вже включена, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/023_add_rate_limits.sql
--
-- Скрипт безпечний для повторного запуску (CREATE TABLE IF NOT EXISTS).

-- bucket: «portal.<дія>.<довжина вікна в секундах>»; subject: IP-адреса клієнта (IPv6 — підмережа /64);
-- window_start: початок вікна (unix-час); hits: кількість запитів у вікні. Старі вікна очищаються автоматично.
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket VARCHAR(40) NOT NULL,
    subject VARCHAR(45) NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, subject, window_start),
    INDEX idx_rate_limits_window (window_start)
) ENGINE=InnoDB;
