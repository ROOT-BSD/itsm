-- Міграція 039: шаблони проєктів і задач.
--
--  * templates — шаблон (kind = 'project' — набір задач для проєкту; kind = 'task' — одна типова задача).
--  * template_items — задачі шаблону: назва, опис, тип, пріоритет, зміщення початку/терміну в днях від дати старту,
--    пункти чек-листа й підзадачі (по одному в рядку).
--
-- Потребує міграції 038 (підзадачі та чек-листи). На новій інсталяції (schema.sql "з нуля") усе вже є.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/legacy/039_add_templates.sql
--
-- Скрипт безпечний для повторного запуску.

CREATE TABLE IF NOT EXISTS templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('project','task') NOT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    project_description TEXT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_templates_kind (kind, name),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS template_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    position INT NOT NULL DEFAULT 0,
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    type_id INT NULL,
    priority ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal',
    start_offset INT NULL,
    due_offset INT NULL,
    checklist TEXT NULL,
    subtasks TEXT NULL,
    KEY idx_template_items (template_id, position),
    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
    FOREIGN KEY (type_id) REFERENCES task_types(id) ON DELETE SET NULL
) ENGINE=InnoDB;
