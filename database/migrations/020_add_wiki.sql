-- Міграція 020: вікі (сторінки в Markdown з історією версій).
--
-- Створює таблиці wiki_pages і wiki_revisions. Початковий вміст (гайди користувача й адміністратора
-- з docs/) завантажує окремий скрипт: php bin/import-wiki-docs.php — update.sh викликає його сам.
--
-- На новій інсталяції (schema.sql "з нуля") таблиці вже включені, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/020_add_wiki.sql
--
-- Скрипт безпечний для повторного запуску (CREATE TABLE IF NOT EXISTS).

-- Вікі: сторінки в Markdown (App\Core\Markdown) з історією версій.
--   visibility: all — усі, хто увійшов; staff — усі, крім ролі «Заявник»; admin — лише адміністратор.
--   version збільшується при кожному збереженні; форма редагування передає версію, з якої почала, —
--   так збереження «поверх» чужих змін (двоє редагують одночасно) виявляється, а не мовчки затирає текст.
--   source = 'docs' — сторінка імпортована з docs/*.md (bin/import-wiki-docs.php); imported_hash — SHA-1
--   тексту при імпорті: поки поточний текст його ще збігається, сторінку ніхто не правив, і наступний
--   імпорт може безпечно оновити її новою версією документації; після ручної правки — вже ні.
CREATE TABLE IF NOT EXISTS wiki_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(80) NOT NULL UNIQUE,
    title VARCHAR(200) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    visibility VARCHAR(10) NOT NULL DEFAULT 'all',
    sort_order INT NOT NULL DEFAULT 100,
    version INT NOT NULL DEFAULT 1,
    source VARCHAR(20) NULL,
    imported_hash CHAR(40) NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wiki_pages_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_wiki_pages_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_wiki_pages_sort (sort_order, title)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wiki_revisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_id INT NOT NULL,
    version INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    edited_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wiki_revisions_page FOREIGN KEY (page_id) REFERENCES wiki_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_wiki_revisions_user FOREIGN KEY (edited_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_wiki_revisions_page (page_id, version)
) ENGINE=InnoDB;
