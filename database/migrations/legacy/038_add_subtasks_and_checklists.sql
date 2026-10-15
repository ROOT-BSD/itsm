-- Міграція 038: підзадачі та чек-листи в задачах.
--
--  * tasks.parent_task_id — батьківська задача (колонка є в schema.sql давно, але на старих базах її може не бути;
--    додаємо, якщо відсутня). Підзадача — повноцінна задача (власний виконавець, статус, термін), глибина — один рівень.
--    Видалення батьківської задачі НЕ видаляє підзадачі: вони стають самостійними (ON DELETE SET NULL).
--  * task_checklist_items — пункти чек-листа задачі (текст, позначка «зроблено», хто й коли відзначив).
--
-- На новій інсталяції (schema.sql "з нуля") усе вже є, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/legacy/038_add_subtasks_and_checklists.sql
--
-- Скрипт безпечний для повторного запуску.

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'parent_task_id');
SET @ddl := IF(@has = 0,
    'ALTER TABLE tasks ADD COLUMN parent_task_id INT NULL AFTER project_id, ADD KEY idx_tasks_parent (parent_task_id), ADD CONSTRAINT fk_tasks_parent FOREIGN KEY (parent_task_id) REFERENCES tasks(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS task_checklist_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    body VARCHAR(255) NOT NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    done_by INT NULL,
    done_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_checklist_task (task_id, id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (done_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
