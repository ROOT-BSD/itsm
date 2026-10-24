-- Міграція 054: розширення правил черги тікетів і центр сповіщень.
--  * tickets.priority — пріоритет тікета (low / normal / high / critical), за замовчуванням normal;
--  * ticket_rules.trigger_event — коли спрацьовує правило: created (створення, як раніше), status (зміна статусу), comment (новий коментар);
--    when_status — для події status: цільовий статус (NULL — будь-який); comment_by — для події comment: хто написав (any / requester / staff);
--  * умови за вкладеннями: att_mode (any / with / without), att_min_count, att_min_mb, att_max_mb (загальний розмір файлів тікета);
--  * дії: action_priority — встановити пріоритет; notify_system_operator / notify_system_users — сповіщення в системі (центр сповіщень, без пошти);
--  * match_in отримує значення comment (шукати слова в тексті коментаря);
--  * user_notifications — центр сповіщень (дзвіночок у верхньому меню).
-- Скрипт безпечний для повторного запуску.
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS priority ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal' AFTER status;
ALTER TABLE ticket_rules ADD COLUMN IF NOT EXISTS trigger_event ENUM('created','status','comment') NOT NULL DEFAULT 'created' AFTER sort_order;
ALTER TABLE ticket_rules ADD COLUMN IF NOT EXISTS when_status VARCHAR(20) NULL AFTER trigger_event;
ALTER TABLE ticket_rules ADD COLUMN IF NOT EXISTS comment_by ENUM('any','requester','staff') NOT NULL DEFAULT 'any' AFTER when_status;
ALTER TABLE ticket_rules ADD COLUMN IF NOT EXISTS att_mode ENUM('any','with','without') NOT NULL DEFAULT 'any';
ALTER TABLE ticket_rules ADD COLUMN IF NOT EXISTS att_min_count INT UNSIGNED NULL;
ALTER TABLE ticket_rules ADD COLUMN IF NOT EXISTS att_min_mb DECIMAL(7,2) NULL;
ALTER TABLE ticket_rules ADD COLUMN IF NOT EXISTS att_max_mb DECIMAL(7,2) NULL;
ALTER TABLE ticket_rules ADD COLUMN IF NOT EXISTS action_priority ENUM('low','normal','high','critical') NULL;
ALTER TABLE ticket_rules ADD COLUMN IF NOT EXISTS notify_system_operator TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE ticket_rules ADD COLUMN IF NOT EXISTS notify_system_users VARCHAR(500) NULL;
ALTER TABLE ticket_rules MODIFY COLUMN match_in ENUM('subject','description','both','comment') NOT NULL DEFAULT 'both';

CREATE TABLE IF NOT EXISTS user_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    body VARCHAR(500) NULL,
    url VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    KEY idx_user_notifications_user (user_id, read_at, id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
