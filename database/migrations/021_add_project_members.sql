-- Міграція 021: учасники проєкту (надання доступу до проєкту обраним користувачам).
--
-- Створює таблицю project_members. До цієї міграції проєкт бачили лише автор, відповідальний і адміністратор;
-- тепер доступ можна надати й іншим користувачам (Проєкти → сторінка проєкту → «Доступ до проєкту»).
-- Наявних проєктів міграція не змінює: учасників у них поки немає, тож хто що бачив — те й бачить.
--
-- На новій інсталяції (schema.sql "з нуля") таблиця вже включена, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/021_add_project_members.sql
--
-- Скрипт безпечний для повторного запуску (CREATE TABLE IF NOT EXISTS).

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
