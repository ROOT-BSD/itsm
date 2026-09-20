-- Міграція 002: відновлення кирилиці, пошкодженої подвійним UTF-8 кодуванням.
--
-- КОНТЕКСТ: якщо schema.sql/seed.sql застосовувалися командою
--   mysql -u root -p ІМ_Я_БД < database/seed.sql
-- БЕЗ прапорця --default-character-set=utf8mb4, клієнт mysql міг прочитати
-- файл в іншому кодуванні (типово latin1) і записати кирилицю подвійно
-- перекодованою. Візуально це непомітно в самому mysql-клієнті, але
-- проявляється "кракозябрами" при виводі через веб-інтерфейс.
--
-- ЯК ЗАСТОСУВАТИ (прапорець тут ОБОВ'ЯЗКОВИЙ — інакше сам скрипт
-- запише ще один шар пошкодження):
--   mysql --default-character-set=utf8mb4 -u root -p ІМ_Я_БД < database/migrations/002_fix_utf8_double_encoding.sql
--
-- БЕЗПЕЧНО ДЛЯ ПОВТОРНОГО ЗАПУСКУ: кожен UPDATE спрацьовує лише для рядків,
-- де виявлено ознаку пошкодження (символ 'Ð', типовий для подвійного
-- UTF-8-кодування кирилиці) — тобто дані, які ви вручну змінили на щось інше,
-- залишаться недоторканими.

-- ---------- Діагностика ДО виправлення ----------
SELECT 'ДО виправлення — рядків з ознакою пошкодження:' AS info;
SELECT
    (SELECT COUNT(*) FROM roles WHERE name LIKE '%Ð%' OR description LIKE '%Ð%') AS roles_corrupted,
    (SELECT COUNT(*) FROM task_types WHERE name LIKE '%Ð%') AS task_types_corrupted,
    (SELECT COUNT(*) FROM task_statuses WHERE name LIKE '%Ð%') AS task_statuses_corrupted,
    (SELECT COUNT(*) FROM ticket_queues WHERE name LIKE '%Ð%' OR description LIKE '%Ð%') AS ticket_queues_corrupted,
    (SELECT COUNT(*) FROM users WHERE email = 'admin@example.local' AND full_name LIKE '%Ð%') AS admin_name_corrupted;

-- ---------- Ролі ----------
UPDATE roles SET name = 'Адміністратор системи', description = 'Повний доступ, керування користувачами й налаштуваннями'
    WHERE code = 'admin' AND (name LIKE '%Ð%' OR description LIKE '%Ð%');
UPDATE roles SET name = 'Керівник ІТ-підрозділу', description = 'Управління проєктами/задачами/звітністю, аналітика'
    WHERE code = 'it_manager' AND (name LIKE '%Ð%' OR description LIKE '%Ð%');
UPDATE roles SET name = 'Системний адміністратор', description = 'Робота з призначеними задачами/інцидентами, облік часу'
    WHERE code = 'sysadmin' AND (name LIKE '%Ð%' OR description LIKE '%Ð%');
UPDATE roles SET name = 'Оператор служби підтримки', description = 'Обробка тікетів, черги, контроль SLA'
    WHERE code = 'support_operator' AND (name LIKE '%Ð%' OR description LIKE '%Ð%');
UPDATE roles SET name = 'Спостерігач', description = 'Перегляд без редагування'
    WHERE code = 'observer' AND (name LIKE '%Ð%' OR description LIKE '%Ð%');
UPDATE roles SET name = 'Заявник', description = 'Подання тікетів, перегляд статусу власних звернень'
    WHERE code = 'requester' AND (name LIKE '%Ð%' OR description LIKE '%Ð%');

-- ---------- Типи задач ----------
UPDATE task_types SET name = 'Інцидент' WHERE code = 'incident' AND name LIKE '%Ð%';
UPDATE task_types SET name = 'Запит на обслуговування' WHERE code = 'service_request' AND name LIKE '%Ð%';
UPDATE task_types SET name = 'Проблема' WHERE code = 'problem' AND name LIKE '%Ð%';
UPDATE task_types SET name = 'Планове завдання' WHERE code = 'planned_task' AND name LIKE '%Ð%';

-- ---------- Статуси задач ----------
UPDATE task_statuses SET name = 'Нова' WHERE code = 'new' AND name LIKE '%Ð%';
UPDATE task_statuses SET name = 'В роботі' WHERE code = 'in_progress' AND name LIKE '%Ð%';
UPDATE task_statuses SET name = 'Очікує' WHERE code = 'on_hold' AND name LIKE '%Ð%';
UPDATE task_statuses SET name = 'Вирішена' WHERE code = 'resolved' AND name LIKE '%Ð%';
UPDATE task_statuses SET name = 'Закрита' WHERE code = 'closed' AND name LIKE '%Ð%';

-- ---------- Черга підтримки за замовчуванням ----------
-- (немає стабільного текстового коду — орієнтуємось на id=1, це єдина черга із seed.sql)
UPDATE ticket_queues SET name = 'Загальна підтримка', description = 'Черга за замовчуванням для звернень співробітників'
    WHERE id = 1 AND (name LIKE '%Ð%' OR description LIKE '%Ð%');

-- ---------- Тестовий адміністратор ----------
-- Якщо ви вже перейменували цей обліковий запис на щось своє — рядок нижче
-- його не торкнеться (спрацьовує лише за наявності ознаки пошкодження).
UPDATE users SET full_name = 'Адміністратор Системи'
    WHERE email = 'admin@example.local' AND full_name LIKE '%Ð%';

-- ---------- Діагностика ПІСЛЯ виправлення ----------
SELECT 'ПІСЛЯ виправлення — рядків з ознакою пошкодження (мають бути 0):' AS info;
SELECT
    (SELECT COUNT(*) FROM roles WHERE name LIKE '%Ð%' OR description LIKE '%Ð%') AS roles_corrupted,
    (SELECT COUNT(*) FROM task_types WHERE name LIKE '%Ð%') AS task_types_corrupted,
    (SELECT COUNT(*) FROM task_statuses WHERE name LIKE '%Ð%') AS task_statuses_corrupted,
    (SELECT COUNT(*) FROM ticket_queues WHERE name LIKE '%Ð%' OR description LIKE '%Ð%') AS ticket_queues_corrupted,
    (SELECT COUNT(*) FROM users WHERE email = 'admin@example.local' AND full_name LIKE '%Ð%') AS admin_name_corrupted;

-- ПРИМІТКА: якщо після виправлення лічильники все ще НЕ нульові — ймовірно,
-- ви вже перейменували ці записи вручну на щось своє (тоді все гаразд, це
-- не стосується цієї міграції), АБО ваші власні дані (назви проєктів, задач,
-- коментарі, введені через веб-інтерфейс) також постраждали від іншої причини —
-- у такому разі зверніться до розробника з експортом бази для діагностики.
