-- Мітки тікетів, правила для задач, умова «оператор» і дії «коментар/мітка» у правилах тікетів.
CREATE TABLE IF NOT EXISTS ticket_labels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    color VARCHAR(20) NOT NULL DEFAULT 'secondary',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_label_links (
    ticket_id INT NOT NULL,
    label_id INT NOT NULL,
    PRIMARY KEY (ticket_id, label_id),
    KEY idx_tll_label (label_id),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (label_id) REFERENCES ticket_labels(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE ticket_rules
    ADD COLUMN IF NOT EXISTS cond_operator ENUM('any','assigned','unassigned') NOT NULL DEFAULT 'any',
    ADD COLUMN IF NOT EXISTS cond_operator_id INT NULL,
    ADD COLUMN IF NOT EXISTS action_label_id INT NULL,
    ADD COLUMN IF NOT EXISTS action_comment VARCHAR(1000) NULL;

CREATE TABLE IF NOT EXISTS task_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    trigger_event ENUM('created','status') NOT NULL DEFAULT 'created',
    when_status_id INT NULL,
    keywords TEXT NULL,
    match_in ENUM('title','description','both') NOT NULL DEFAULT 'both',
    project_id INT NULL,
    cond_priority ENUM('low','normal','high','critical') NULL,
    cond_assignee ENUM('any','assigned','unassigned') NOT NULL DEFAULT 'any',
    action_assignee_id INT NULL,
    action_priority ENUM('low','normal','high','critical') NULL,
    notify_system_assignee TINYINT(1) NOT NULL DEFAULT 0,
    notify_system_users VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    last_hit_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_task_rules_active (is_active, trigger_event, sort_order)
) ENGINE=InnoDB;
