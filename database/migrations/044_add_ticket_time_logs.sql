-- Міграція 044: облік часу по тікетах.
--
--  * ticket_time_logs — години, витрачені персоналом підтримки на тікет (дата, категорія активності, коментар).
--    Використовується у «Звітах → Облік часу» (разом із часом по задачах) та у «Звіті по тікетах».
--
-- Скрипт безпечний для повторного запуску.

CREATE TABLE IF NOT EXISTS ticket_time_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT NOT NULL,
    activity_category VARCHAR(100) NULL,
    hours DECIMAL(5,2) NOT NULL,
    log_date DATE NOT NULL,
    comment VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ticket_time_logs_ticket (ticket_id),
    KEY idx_ticket_time_logs_date (log_date),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
