-- Міграція 010: додає UNIQUE обмеження на sla_policies.queue_id.
--
-- Потрібно для сторінки керування SLA-нормативами через інтерфейс
-- (Адмін-панель → Черги тікетів) — без UNIQUE коректний "upsert" (створити
-- норматив, якщо його ще немає, або оновити наявний) неможливий.
--
-- Якщо на чергу випадково існує кілька рядків нормативу (раніше це було
-- можливим — обмеження не було), спершу лишає лише НАЙНОВІШИЙ рядок
-- (найбільший id) для кожної черги, а решту видаляє. Це стається лише якщо
-- хтось додавав нормативи напряму в БД в обхід застосунку.
--
-- На новій інсталяції (schema.sql "з нуля") обмеження вже включене, міграція
-- не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/010_add_sla_policies_unique_queue.sql
--
-- Скрипт безпечний для повторного запуску: перевіряє, чи обмеження вже існує.

-- 1. Дедуплікація (якщо є) — лишаємо рядок з найбільшим id на чергу.
DELETE sp1 FROM sla_policies sp1
INNER JOIN sla_policies sp2 ON sp1.queue_id = sp2.queue_id AND sp1.id < sp2.id;

-- 2. Саме UNIQUE-обмеження.
-- УВАГА: перевіряємо NON_UNIQUE = 0, а не назву індексу. СУБД автоматично
-- створює для зовнішнього ключа звичайний (не унікальний) індекс з ТІЄЮ Ж
-- назвою "queue_id" — перевірка лише за назвою хибно вважала б обмеження
-- вже існуючим.
SET @unique_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sla_policies'
      AND COLUMN_NAME = 'queue_id' AND NON_UNIQUE = 0
);

SET @sql = IF(
    @unique_exists = 0,
    'ALTER TABLE sla_policies ADD UNIQUE KEY uk_sla_policies_queue_id (queue_id)',
    'SELECT "Обмеження UNIQUE на sla_policies.queue_id вже існує — міграцію пропущено" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
