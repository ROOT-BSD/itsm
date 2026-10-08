-- Міграція 022: REST API (токени доступу, ліміти запитів) і вебхуки (ендпоінти, черга доставок).
--
-- Нічого не змінює в наявних даних і поведінці: API за замовчуванням ВИМКНЕНО (вмикається адміністратором:
-- Адмін-панель → Налаштування → API), вебхуків без створених ендпоінтів немає.
--
-- На новій інсталяції (schema.sql "з нуля") таблиці вже включені, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/022_add_api_and_webhooks.sql
--
-- Скрипт безпечний для повторного запуску (CREATE TABLE IF NOT EXISTS).

-- REST API: персональні токени доступу. Токен показується ОДИН раз при створенні; у БД лежить лише його SHA-256 (token_hash),
-- тож витік БД не дає працюючих токенів. Токен діє від імені свого власника: ті самі ролі й правила видимості, що й у вебі.
-- scope: read — лише читання; write — ще й створення/зміна. revoked_at — відкликаний; expires_at — необов'язковий термін дії.
CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    token_prefix VARCHAR(16) NOT NULL,
    scope VARCHAR(10) NOT NULL DEFAULT 'read',
    expires_at DATETIME NULL,
    last_used_at DATETIME NULL,
    last_used_ip VARCHAR(45) NULL,
    revoked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_api_tokens_user (user_id)
) ENGINE=InnoDB;

-- Лічильник запитів API по хвилинах (вікно 60 с) для обмеження частоти на токен.
CREATE TABLE IF NOT EXISTS api_rate_limits (
    token_id INT NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (token_id, window_start),
    CONSTRAINT fk_api_rate_limits_token FOREIGN KEY (token_id) REFERENCES api_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Вебхуки: адреси, на які система надсилає події (POST, JSON, підпис HMAC-SHA256 за secret).
-- events: '*' (усі) або перелік подій через кому. consecutive_failures — поспіль невдалих доставок; після ліміту
-- ендпоінт автоматично вимикається (disabled_reason пояснює чому).
CREATE TABLE IF NOT EXISTS webhook_endpoints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    url VARCHAR(500) NOT NULL,
    secret VARCHAR(64) NOT NULL,
    events TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    verify_tls TINYINT(1) NOT NULL DEFAULT 1,
    consecutive_failures INT NOT NULL DEFAULT 0,
    disabled_reason VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_webhook_endpoints_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Черга й журнал доставок вебхуків. Подія спершу ЗАПИСУЄТЬСЯ сюди (в одній транзакції зі зміною, що її спричинила, —
-- відкат зміни скасовує й подію), а надсилається окремо: одразу після відповіді користувачу та скриптом bin/deliver-webhooks.php
-- (повтори з наростаючою паузою). status: pending / success / failed (вичерпано спроби).
CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    endpoint_id INT NOT NULL,
    event VARCHAR(60) NOT NULL,
    event_id CHAR(36) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    last_status_code SMALLINT NULL,
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME NULL,
    CONSTRAINT fk_webhook_deliveries_endpoint FOREIGN KEY (endpoint_id) REFERENCES webhook_endpoints(id) ON DELETE CASCADE,
    INDEX idx_webhook_deliveries_due (status, next_attempt_at),
    INDEX idx_webhook_deliveries_endpoint (endpoint_id, id)
) ENGINE=InnoDB;
