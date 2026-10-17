-- Міграція 041: пов'язані та дубльовані тікети.
--
--  * ticket_links — зв'язок двох тікетів. link_type = 'related' — симетричний «пов'язані» (зберігається один рядок,
--    ticket_id < linked_id); link_type = 'duplicate' — спрямований: ticket_id є ДУБЛЕМ тікета linked_id (оригіналу).
--    Тікет може бути дублем лише одного оригіналу (перевіряється в коді).
--
-- Скрипт безпечний для повторного запуску.

CREATE TABLE IF NOT EXISTS ticket_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    linked_id INT NOT NULL,
    link_type ENUM('related','duplicate') NOT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_links_pair (ticket_id, linked_id),
    KEY idx_ticket_links_linked (linked_id),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (linked_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
