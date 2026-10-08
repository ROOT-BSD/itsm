-- =========================================================
-- Система управління ІТ-задачами та зверненнями
-- Схема БД MySQL 8.x — ядро (Епіки 1-3) + Service Desk (Епік 12)
-- =========================================================

-- УВАГА: цей файл НЕ створює базу даних і не робить USE —
-- назву БД задає install.sh (або ви самі при ручному застосуванні):
--   mysql -u root -p ІМ_Я_БД < database/schema.sql

-- ---------- Ролі та користувачі (Епік 1) ----------

CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,      -- admin, it_manager, sysadmin, support_operator, observer, requester
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NULL,               -- NULL, якщо тільки AD-автентифікація
    auth_source ENUM('local','ad') NOT NULL DEFAULT 'local',
    ad_username VARCHAR(100) NULL,                 -- sAMAccountName (чи інший налаштований атрибут) — для AD-пошуку при вході, email лишається незмінним ключем входу
    ad_ou VARCHAR(500) NULL,                       -- шлях OU з DN користувача в AD (лише OU=, без CN-контейнерів і DC=) — для групування на сторінці «Користувачі»
    role_id INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    failed_login_attempts INT NOT NULL DEFAULT 0,  -- скидається до 0 при вдалому вході
    locked_until DATETIME NULL,                    -- NULL = не заблоковано; блокування завжди прив'язане до КОНКРЕТНОГО користувача, не IP
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- Загальносистемні налаштування (ключ-значення) — зараз лише параметри
-- блокування після невдалих спроб входу, але таблиця зроблена узагальненою
-- для майбутніх налаштувань без нової міграції на кожен додатковий параметр.
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- ---------- Проєкти (Епік 2) ----------

CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    visibility ENUM('public','private','restricted') NOT NULL DEFAULT 'private',
    status ENUM('active','archived','closed') NOT NULL DEFAULT 'active',
    created_by INT NOT NULL,
    responsible_user_id INT NULL,          -- виконавець/відповідальний за проєкт (обирається з існуючих користувачів)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Учасники проєкту: користувачі, яким автор/відповідальний/адміністратор ЯВНО надали доступ до проєкту (крім автора й
-- відповідального, які мають доступ завжди). Учасник бачить проєкт і всі його задачі й працює з ними так само, як
-- автор чи відповідальний; керувати складом учасників він не може. Доступ дає лише цей запис — поле projects.visibility
-- на доступ не впливає. Права НЕ успадковуються підпроєктами: кожен проєкт має власний перелік учасників.
-- Видалення проєкту чи користувача прибирає й відповідні записи.
CREATE TABLE IF NOT EXISTS project_members (
    project_id INT NOT NULL,
    user_id INT NOT NULL,
    added_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, user_id),
    CONSTRAINT fk_project_members_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_members_added_by FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_project_members_user (user_id)
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS project_members (
    project_id INT NOT NULL,
    user_id INT NOT NULL,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, user_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Задачі та інциденти (Епік 3) ----------

CREATE TABLE IF NOT EXISTS task_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,   -- incident, service_request, problem, planned_task
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_statuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,   -- new, in_progress, resolved, closed ...
    name VARCHAR(100) NOT NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- Етапи/контрольні точки проєкту (дорожня карта, Епік 4).
-- Створюється ДО tasks, бо tasks.milestone_id посилається на цю таблицю —
-- MySQL перевіряє існування таблиці FOREIGN KEY одразу при виконанні
-- CREATE TABLE, тому порядок у файлі важливий.
CREATE TABLE IF NOT EXISTS milestones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    target_date DATE NULL,
    status ENUM('planned','in_progress','completed','delayed') NOT NULL DEFAULT 'planned',
    sort_order INT NOT NULL DEFAULT 0,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    parent_task_id INT NULL,
    type_id INT NOT NULL,
    status_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    priority ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal',
    author_id INT NOT NULL,
    assignee_id INT NULL,
    start_date DATE NULL,                  -- дата початку (для діаграми Ганта)
    due_date DATE NULL,
    milestone_id INT NULL,                 -- прив'язка до етапу/контрольної точки (дорожня карта)
    estimated_hours DECIMAL(6,2) NULL,
    actual_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    FOREIGN KEY (type_id) REFERENCES task_types(id),
    FOREIGN KEY (status_id) REFERENCES task_statuses(id),
    FOREIGN KEY (author_id) REFERENCES users(id),
    FOREIGN KEY (assignee_id) REFERENCES users(id),
    FOREIGN KEY (milestone_id) REFERENCES milestones(id) ON DELETE SET NULL,
    FULLTEXT KEY ft_title_description (title, description)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_relations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    related_task_id INT NOT NULL,
    relation_type ENUM('blocks','blocked_by','duplicates','related') NOT NULL,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (related_task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    author_id INT NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS time_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    user_id INT NOT NULL,
    activity_category VARCHAR(100),
    hours DECIMAL(5,2) NOT NULL,
    log_date DATE NOT NULL,
    comment VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    KEY idx_time_logs_log_date (log_date)  -- звіти обліку часу фільтрують і групують по цій колонці на кожному запиті
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    user_id INT NULL,
    changes JSON NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    KEY idx_audit_log_created_at (created_at)  -- сторінка перегляду журналу сортує й фільтрує за датою
) ENGINE=InnoDB;

-- ---------- Service Desk / тікети для всіх співробітників (Епік 12) ----------

CREATE TABLE IF NOT EXISTS ticket_queues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255),
    default_operator_id INT NULL,  -- автопризначення: новий тікет у цій черзі одразу отримує цього оператора (Адмін-панель → Черги тікетів)
    FOREIGN KEY (default_operator_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Відповідність групи AD (значення атрибуту memberOf, звичайно повний DN, напр.
-- "cn=ITSM-Operators,ou=Groups,dc=example,dc=com") локальній ролі. Під час синхронізації
-- (bin/sync-ad-users.php) кожен AD-користувач отримує роль ПЕРШОЇ групи зі списку своїх
-- memberOf, для якої є відповідність тут, за порядком rank (менше число — вищий пріоритет);
-- якщо жодна група не збіглась — роль за замовчуванням (AD_DEFAULT_ROLE / 'requester').
CREATE TABLE IF NOT EXISTS ad_group_role_mapping (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad_group VARCHAR(255) NOT NULL UNIQUE,
    role_id INT NOT NULL,
    rank INT NOT NULL DEFAULT 100,
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sla_policies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    queue_id INT NOT NULL UNIQUE,           -- один норматив на чергу; UNIQUE потрібен для коректного "upsert" при редагуванні через інтерфейс
    first_response_minutes INT NOT NULL,
    resolution_minutes INT NOT NULL,
    FOREIGN KEY (queue_id) REFERENCES ticket_queues(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    queue_id INT NOT NULL,
    project_id INT NULL,                  -- необов'язковий зв'язок з проєктом; NULL = звичайне звернення без прив'язки
    access_token VARCHAR(64) NULL UNIQUE, -- для порталу самообслуговування: посилання на відстеження без входу в систему
    requester_name VARCHAR(150) NOT NULL,
    requester_email VARCHAR(150) NOT NULL,
    requester_user_id INT NULL,           -- може бути NULL: заявник без облікового запису основного функціоналу
    subject VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('new','in_progress','waiting_customer','resolved','closed') NOT NULL DEFAULT 'new',
    assigned_operator_id INT NULL,
    first_response_at DATETIME NULL,
    resolved_at DATETIME NULL,
    csat_score TINYINT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (queue_id) REFERENCES ticket_queues(id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_operator_id) REFERENCES users(id),
    FOREIGN KEY (requester_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    author_type ENUM('operator','requester') NOT NULL,
    author_id INT NULL,
    body TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Вкладення до тікетів і задач (модуль «файлові документи», App\Services\AttachmentService).
-- Сам файл лежить у storage/uploads/<перші 2 символи>/<stored_name> — поза веб-коренем;
-- stored_name — випадкові 32 hex-символи, жодної частини імені від користувача (original_name
-- зберігається лише для показу). Рівно одне з ticket_id/task_id заповнене — це гарантує код
-- (CHECK тут не використано: MySQL 8 забороняє CHECK на колонках з ON DELETE CASCADE, а проєкт
-- підтримує і MySQL 8, і MariaDB). Видалення тікета/задачі каскадно прибирає рядки; файли на
-- диску при цьому прибирає код (Task::delete, Project::delete).
CREATE TABLE IF NOT EXISTS attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NULL,
    task_id INT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name CHAR(32) NOT NULL UNIQUE,
    mime_type VARCHAR(50) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    uploaded_by INT NULL,
    source VARCHAR(10) NOT NULL DEFAULT 'web',  -- звідки файл: web / portal (анонімний портал) / email (вхідний лист)
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attachments_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_attachments_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_attachments_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_attachments_ticket (ticket_id),
    INDEX idx_attachments_task (task_id)
) ENGINE=InnoDB;

-- Вікі: сторінки в Markdown (App\Core\Markdown) з історією версій.
--   visibility: all — усі, хто увійшов; staff — усі, крім ролі «Заявник»; admin — лише адміністратор.
--   version збільшується при кожному збереженні; форма редагування передає версію, з якої почала, —
--   так збереження «поверх» чужих змін (двоє редагують одночасно) виявляється, а не мовчки затирає текст.
--   source = 'docs' — сторінка імпортована з docs/*.md (bin/import-wiki-docs.php); imported_hash — SHA-1
--   тексту при імпорті: поки поточний текст його ще збігається, сторінку ніхто не правив, і наступний
--   імпорт може безпечно оновити її новою версією документації; після ручної правки — вже ні.
CREATE TABLE IF NOT EXISTS wiki_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(80) NOT NULL UNIQUE,
    parent_id INT NULL,
    title VARCHAR(200) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    visibility VARCHAR(10) NOT NULL DEFAULT 'all',
    sort_order INT NOT NULL DEFAULT 100,
    version INT NOT NULL DEFAULT 1,
    source VARCHAR(20) NULL,
    imported_hash CHAR(40) NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wiki_pages_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_wiki_pages_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_wiki_pages_parent FOREIGN KEY (parent_id) REFERENCES wiki_pages(id) ON DELETE SET NULL,
    INDEX idx_wiki_pages_parent (parent_id),
    INDEX idx_wiki_pages_sort (sort_order, title)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wiki_revisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_id INT NOT NULL,
    version INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    edited_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wiki_revisions_page FOREIGN KEY (page_id) REFERENCES wiki_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_wiki_revisions_user FOREIGN KEY (edited_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_wiki_revisions_page (page_id, version)
) ENGINE=InnoDB;

-- Журнал обробки вхідної пошти (email-to-ticket). Одночасно:
--  1) захист від повторної обробки того самого листа (UNIQUE message_id);
--  2) відповідь на питання "чому мій лист не став тікетом" (пропущені теж записуються);
--  3) зв'язок Message-ID → тікет, за яким відповіді в тій самій гілці листування
--     потрапляють коментарем у вже наявний тікет.
CREATE TABLE IF NOT EXISTS email_ingest_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(255) NOT NULL,        -- <id@host> з заголовка Message-ID або 'hash:<sha1>' для листів без нього
    from_email VARCHAR(150) NOT NULL DEFAULT '',
    subject VARCHAR(255) NOT NULL DEFAULT '',
    result ENUM('ticket_created','comment_added','skipped') NOT NULL,
    ticket_id INT NULL,
    note VARCHAR(255) NULL,                  -- причина пропуску / примітка
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_email_ingest_message (message_id),
    KEY idx_email_ingest_created (created_at),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Журнал надісланих нагадувань про наближення/настання терміну виконання
-- задачі. UNIQUE (task_id, reminder_type) — щоб те саме нагадування не
-- надсилалось повторно при кожному запуску cron (він може виконуватись
-- частіше, ніж раз на день). Записи видаляються каскадно при видаленні
-- задачі, а також очищуються вручну (Task::updateDates()), якщо термін
-- переносять, — щоб нагадування коректно спрацювали заново для нової дати.
CREATE TABLE IF NOT EXISTS task_due_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    reminder_type ENUM('2d','1d','due') NOT NULL,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_task_reminder (task_id, reminder_type),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Лічильники обмеження частоти запитів до публічного порталу /support.
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

-- Бібліотека документів (розділ «Документи»).
-- Розділи (плоский перелік). Видалення розділу не видаляє документи: вони переходять до «Без розділу».
CREATE TABLE IF NOT EXISTS library_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    sort_order INT NOT NULL DEFAULT 100
) ENGINE=InnoDB;

-- Документ — «обкладинка» (назва, опис, розділ, хто бачить); самі файли лежать у library_versions.
-- visibility: all / staff / admin — ті самі значення й правила, що й у вікі.
CREATE TABLE IF NOT EXISTS library_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    visibility VARCHAR(10) NOT NULL DEFAULT 'all',
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_library_documents_category FOREIGN KEY (category_id) REFERENCES library_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_library_documents_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_library_documents_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_library_documents_category (category_id),
    INDEX idx_library_documents_title (title)
) ENGINE=InnoDB;

-- Версії файлу документа. Поточна — з найбільшим version_no; старі зберігаються й доступні за прямим посиланням.
-- stored_name — випадкове ім'я у storage/uploads (те саме сховище, що й у вкладень тікетів/задач).
CREATE TABLE IF NOT EXISTS library_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    version_no INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name CHAR(32) NOT NULL UNIQUE,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    comment VARCHAR(255) NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_library_versions_document FOREIGN KEY (document_id) REFERENCES library_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_library_versions_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_library_versions_doc_no (document_id, version_no)
) ENGINE=InnoDB;

-- Форум: розділи, теми, повідомлення.
-- Розділи форуму. visibility: all / staff / admin — ті самі значення й правила, що у вікі та бібліотеки документів.
-- is_locked: розділ закритий для нових тем (тема від модератора можлива; наявні теми читаються).
CREATE TABLE IF NOT EXISTS forum_boards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(500) NULL,
    visibility VARCHAR(10) NOT NULL DEFAULT 'all',
    sort_order INT NOT NULL DEFAULT 100,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Теми. reply_count / last_post_at / last_post_by — похідні величини (перераховуються з повідомлень після кожної зміни),
-- збережені, щоб список тем не рахував повідомлення на кожен рядок. is_pinned — закріплена вгорі; is_locked — нові відповіді закриті.
CREATE TABLE IF NOT EXISTS forum_topics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    author_id INT NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    reply_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_post_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_post_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_forum_topics_board FOREIGN KEY (board_id) REFERENCES forum_boards(id) ON DELETE CASCADE,
    CONSTRAINT fk_forum_topics_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_forum_topics_last_by FOREIGN KEY (last_post_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_forum_topics_board (board_id, is_pinned, last_post_at)
) ENGINE=InnoDB;

-- Повідомлення. Перше повідомлення теми (найменший id) — її початковий текст. Текст — Markdown (безпечна підмножина).
CREATE TABLE IF NOT EXISTS forum_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    topic_id INT NOT NULL,
    author_id INT NULL,
    body MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at DATETIME NULL,
    edited_by INT NULL,
    CONSTRAINT fk_forum_posts_topic FOREIGN KEY (topic_id) REFERENCES forum_topics(id) ON DELETE CASCADE,
    CONSTRAINT fk_forum_posts_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_forum_posts_edited FOREIGN KEY (edited_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_forum_posts_topic (topic_id, id)
) ENGINE=InnoDB;

-- Вкладення сторінки. Файли лежать у storage/uploads (поза веб-коренем) під випадковими іменами, як і решта файлів;
-- віддаються через /wiki/files/{id} з перевіркою видимості САМОЇ сторінки. Видалення сторінки видаляє записи
-- (каскадом), а файли з диска прибирає код.
CREATE TABLE IF NOT EXISTS wiki_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_id INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name CHAR(32) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT NOT NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wiki_attachments_page FOREIGN KEY (page_id) REFERENCES wiki_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_wiki_attachments_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_wiki_attachments_page (page_id)
) ENGINE=InnoDB;
