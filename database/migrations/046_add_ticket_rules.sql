-- Міграція 046: автоматичні правила черги тікетів.
--
-- Правило спрацьовує при СТВОРЕННІ тікета (форма, портал, пошта, API), якщо в темі/описі є одне з ключових слів
-- (і, за потреби, тікет потрапив у певну чергу). Дії: перенести в іншу чергу, призначити оператора, надіслати сповіщення.
-- Перше правило за порядком (sort_order, id), що збіглося, виконується і зупиняє перевірку.
-- scope_user_id = NULL — глобальне правило адміністратора системи; інакше правило адміністратора підрозділу,
-- яке діє лише на тікети заявників його підрозділу.
--
-- Скрипт безпечний для повторного запуску.

CREATE TABLE IF NOT EXISTS ticket_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    scope_user_id INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    from_queue_id INT NULL,
    keywords TEXT NOT NULL,
    match_in ENUM('subject','description','both') NOT NULL DEFAULT 'both',
    action_queue_id INT NULL,
    action_operator_id INT NULL,
    notify_emails VARCHAR(500) NULL,
    notify_operator TINYINT(1) NOT NULL DEFAULT 0,
    hits INT NOT NULL DEFAULT 0,
    last_hit_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ticket_rules_order (is_active, sort_order, id),
    FOREIGN KEY (scope_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (from_queue_id) REFERENCES ticket_queues(id) ON DELETE CASCADE,
    FOREIGN KEY (action_queue_id) REFERENCES ticket_queues(id) ON DELETE SET NULL,
    FOREIGN KEY (action_operator_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
