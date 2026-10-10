-- Міграція 031: підписки на розділи форуму (сповіщення про нові теми).
--
-- Користувач підписується на розділ кнопкою «Підписатися» на сторінці розділу і отримує лист, коли в ньому
-- з'являється нова тема (за умови, що адміністратор увімкнув «Сповіщення про форум» і налаштовано SMTP).
-- Підписки видаляються разом з користувачем чи розділом.
--
-- На новій інсталяції (schema.sql "з нуля") таблиця вже є, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/031_add_forum_board_subscriptions.sql
--
-- Скрипт безпечний для повторного запуску.

CREATE TABLE IF NOT EXISTS forum_board_subscriptions (
    user_id INT NOT NULL,
    board_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, board_id),
    CONSTRAINT fk_forum_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_forum_sub_board FOREIGN KEY (board_id) REFERENCES forum_boards(id) ON DELETE CASCADE,
    INDEX idx_forum_sub_board (board_id)
) ENGINE=InnoDB;
