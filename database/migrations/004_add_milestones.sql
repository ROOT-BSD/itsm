-- Міграція 004: додає таблицю milestones (етапи/контрольні точки проєкту)
-- та колонку tasks.milestone_id (прив'язка задачі до етапу) для дорожньої
-- карти (Епік 4). На новій інсталяції (schema.sql "з нуля") усе це вже
-- включене, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/004_add_milestones.sql
--
-- Скрипт безпечний для повторного запуску: перевіряє, чи об'єкти вже існують.

-- 1. Таблиця milestones (створюємо, лише якщо її ще немає)
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

-- 2. Колонка tasks.milestone_id (додаємо, лише якщо її ще немає)
SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tasks'
      AND COLUMN_NAME = 'milestone_id'
);

SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tasks
        ADD COLUMN milestone_id INT NULL AFTER due_date,
        ADD CONSTRAINT fk_tasks_milestone
            FOREIGN KEY (milestone_id) REFERENCES milestones(id) ON DELETE SET NULL',
    'SELECT "Колонка milestone_id вже існує — міграцію пропущено" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
