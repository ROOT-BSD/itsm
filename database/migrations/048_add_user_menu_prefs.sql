-- Міграція 048: особисті налаштування меню — які розділи користувач не хоче бачити у верхньому меню.
-- Зберігаються лише ПРИХОВАНІ пункти (за замовчуванням видно все). Скрипт безпечний для повторного запуску.
CREATE TABLE IF NOT EXISTS user_menu_prefs (
    user_id INT NOT NULL,
    item VARCHAR(30) NOT NULL,
    PRIMARY KEY (user_id, item),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
