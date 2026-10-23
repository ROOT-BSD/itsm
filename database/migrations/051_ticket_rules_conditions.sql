-- Міграція 051: розширення автоматичних правил черги тікетів.
--  * tickets.source — звідки створено тікет (web — форма в системі, portal — портал, email — пошта, api); для старих тікетів NULL;
--  * ticket_rules.requester_match — умова за заявником: адреси чи домени через кому (напр. «@firma.ua, boss@x.com»);
--  * ticket_rules.source — умова за джерелом тікета (NULL — будь-яке).
-- Ключові слова стають необов'язковими, якщо задано іншу умову (порожній рядок = без умови за текстом).
-- Скрипт безпечний для повторного запуску.
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS source ENUM('web','portal','email','api') NULL AFTER description;
ALTER TABLE ticket_rules ADD COLUMN IF NOT EXISTS requester_match VARCHAR(500) NULL AFTER from_queue_id;
ALTER TABLE ticket_rules ADD COLUMN IF NOT EXISTS source ENUM('web','portal','email','api') NULL AFTER requester_match;
