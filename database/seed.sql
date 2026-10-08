-- УВАГА: без USE — назву БД задає install.sh:
--   mysql -u root -p ІМ_Я_БД < database/seed.sql


-- Ролі (розділ 5 ТЗ)
INSERT INTO roles (code, name, description) VALUES
('admin', 'Адміністратор системи', 'Повний доступ, керування користувачами й налаштуваннями'),
('unit_admin', 'Адміністратор Підрозділу', 'Повний доступ до даних і користувачів власного організаційного підрозділу (AD OU)'),
('it_manager', 'Керівник ІТ-підрозділу', 'Управління проєктами/задачами/звітністю, аналітика'),
('sysadmin', 'Системний адміністратор', 'Робота з призначеними задачами/інцидентами, облік часу'),
('support_operator', 'Оператор служби підтримки', 'Обробка тікетів, черги, контроль SLA'),
('observer', 'Спостерігач', 'Перегляд без редагування'),
('requester', 'Заявник', 'Подання тікетів, перегляд статусу власних звернень');

-- Типи задач (Епік 3)
INSERT INTO task_types (code, name) VALUES
('incident', 'Інцидент'),
('service_request', 'Запит на обслуговування'),
('problem', 'Проблема'),
('planned_task', 'Планове завдання');

-- Статуси задач
INSERT INTO task_statuses (code, name, is_closed, sort_order) VALUES
('new', 'Нова', 0, 1),
('in_progress', 'В роботі', 0, 2),
('on_hold', 'Очікує', 0, 3),
('resolved', 'Вирішена', 0, 4),
('closed', 'Закрита', 1, 5);

-- Черга підтримки за замовчуванням (Епік 12)
INSERT INTO ticket_queues (name, description) VALUES
('Загальна підтримка', 'Черга за замовчуванням для звернень співробітників');

INSERT INTO sla_policies (queue_id, first_response_minutes, resolution_minutes) VALUES
(1, 60, 480); -- відповідь протягом 1 год, вирішення протягом 8 год (приклад)

-- Тестовий адміністратор: email admin@example.local / пароль admin123
-- ОБОВ'ЯЗКОВО змінити пароль після першого входу перед продуктивним використанням!
INSERT INTO users (full_name, email, password_hash, auth_source, role_id, is_active) VALUES
('Адміністратор Системи', 'admin@example.local', '$2y$10$2gdKeHfbl1DRzfOqR.udPuzCCG7TVG2OjZ5io2btaU5ezmeSzlTuu', 'local', 1, 1);

-- Блокування облікового запису після невдалих спроб входу (Адмін-панель → Безпека входу).
INSERT INTO app_settings (setting_key, setting_value) VALUES
('max_login_attempts', '5'),
('lockout_minutes', '15'),
-- Email-to-ticket (Адмін-панель → Пошта → тікети): вимкнено, доки не налаштовано підключення в .env
('email_ticket_enabled', '0'),
('email_ticket_queue_id', '0'),   -- 0 = перша черга
('email_autoreply_enabled', '0'),
-- Email-сповіщення про активність — чотири незалежні перемикачі (Адмін-панель → Пошта → тікети)
('email_notify_tickets_enabled', '0'),    -- нова відповідь, зміна статусу тікета
('email_notify_projects_enabled', '0'),   -- створення проєкту/призначення відповідального
('email_notify_tasks_enabled', '0'),      -- створення задачі/призначення виконавця
('email_notify_reminders_enabled', '0'),  -- нагадування про термін виконання задачі (2 дні/1 день/настав)
-- Active Directory (Адмін-панель → Active Directory): вимкнено, доки не налаштовано підключення в .env
('ad_sync_enabled', '0');
