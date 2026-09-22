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
    role_id INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
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

CREATE TABLE IF NOT EXISTS attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NULL,
    uploaded_by INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
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
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    user_id INT NULL,
    changes JSON NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------- Service Desk / тікети для всіх співробітників (Епік 12) ----------

CREATE TABLE IF NOT EXISTS ticket_queues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sla_policies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    queue_id INT NOT NULL,
    first_response_minutes INT NOT NULL,
    resolution_minutes INT NOT NULL,
    FOREIGN KEY (queue_id) REFERENCES ticket_queues(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    queue_id INT NOT NULL,
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
