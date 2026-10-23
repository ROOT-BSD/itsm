-- Міграція 052: «Журнал робіт» задачі — єдиний журнал замість окремих «Коментарів» та «Обліку часу».
-- Запис журналу = рядок time_logs: текст (comment), години (можуть бути 0 — запис без часу), дата, категорія.
--  * hours — тепер DECIMAL(8,4) (хвилини без втрат), за замовчуванням 0;
--  * comment — TEXT (було VARCHAR(255)): повноцінний текст запису з мітками зображень [[img:ID]];
--  * edited_at — позначка «змінено»;
--  * from_comment_id — звідки запис, якщо це перенесений старий коментар (захист від подвійного перенесення).
-- Старі коментарі (таблиця comments) переносяться в журнал із 0 годин; сама таблиця comments не видаляється (резервна копія).
-- Записи з 0 годин не потрапляють у звіти обліку часу. Скрипт безпечний для повторного запуску.
ALTER TABLE time_logs MODIFY COLUMN hours DECIMAL(8,4) NOT NULL DEFAULT 0;
ALTER TABLE time_logs MODIFY COLUMN comment TEXT NULL;
ALTER TABLE time_logs ADD COLUMN IF NOT EXISTS edited_at DATETIME NULL;
ALTER TABLE time_logs ADD COLUMN IF NOT EXISTS from_comment_id INT NULL;
INSERT INTO time_logs (task_id, user_id, hours, log_date, comment, created_at, from_comment_id)
SELECT c.task_id, c.author_id, 0, DATE(c.created_at), c.body, c.created_at, c.id
FROM comments c
WHERE NOT EXISTS (SELECT 1 FROM time_logs t WHERE t.from_comment_id = c.id);
-- «Витрачено годин» тепер завжди дорівнює сумі годин журналу — вирівнюємо для задач, у яких є записи.
UPDATE tasks t SET t.actual_hours = (SELECT COALESCE(SUM(tl.hours), 0) FROM time_logs tl WHERE tl.task_id = t.id)
WHERE EXISTS (SELECT 1 FROM time_logs x WHERE x.task_id = t.id);
