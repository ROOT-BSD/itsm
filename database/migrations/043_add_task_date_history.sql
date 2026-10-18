-- Міграція 043: початкові терміни задачі й історія їх змін.
--
--  * tasks.planned_start_date / planned_due_date — ПОЧАТКОВІ значення дати початку й терміну (ставляться при першому
--    встановленні й надалі не змінюються), щоб на сторінці задачі видно було, наскільки термін зсунули.
--    Для наявних задач початковими вважаються поточні значення (історії до цієї міграції немає).
--  * task_date_changes — журнал змін дат: хто, коли, було/стало, причина (необов'язкова).
--
-- Скрипт безпечний для повторного запуску.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'planned_start_date');
SET @s := IF(@c = 0, 'ALTER TABLE tasks ADD COLUMN planned_start_date DATE NULL AFTER due_date, ADD COLUMN planned_due_date DATE NULL AFTER planned_start_date', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

UPDATE tasks SET planned_start_date = start_date WHERE planned_start_date IS NULL AND start_date IS NOT NULL;
UPDATE tasks SET planned_due_date = due_date WHERE planned_due_date IS NULL AND due_date IS NOT NULL;

CREATE TABLE IF NOT EXISTS task_date_changes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    user_id INT NULL,
    old_start_date DATE NULL,
    old_due_date DATE NULL,
    new_start_date DATE NULL,
    new_due_date DATE NULL,
    reason VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_task_date_changes_task (task_id, id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
