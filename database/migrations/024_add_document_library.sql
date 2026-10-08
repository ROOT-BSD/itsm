-- Міграція 024: бібліотека документів (розділ «Документи») — файли, не прив'язані до тікета чи задачі.
--
-- Нічого не змінює в наявних даних і поведінці: бібліотека порожня, доки хтось не завантажить перший документ.
--
-- На новій інсталяції (schema.sql "з нуля") таблиці вже включені, міграція не потрібна.
--
-- Застосування:
--   mysql -u root -p --default-character-set=utf8mb4 ІМ_Я_БД < database/migrations/024_add_document_library.sql
--
-- Скрипт безпечний для повторного запуску (CREATE TABLE IF NOT EXISTS).

-- Розділи (плоский перелік). Видалення розділу не видаляє документи: вони переходять до «Без розділу».
CREATE TABLE IF NOT EXISTS library_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    sort_order INT NOT NULL DEFAULT 100
) ENGINE=InnoDB;

-- Документ — «обкладинка» (назва, опис, розділ, хто бачить); самі файли лежать у library_versions.
-- visibility: all / staff / admin — ті самі значення й правила, що й у вікі.
CREATE TABLE IF NOT EXISTS library_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    visibility VARCHAR(10) NOT NULL DEFAULT 'all',
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_library_documents_category FOREIGN KEY (category_id) REFERENCES library_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_library_documents_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_library_documents_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_library_documents_category (category_id),
    INDEX idx_library_documents_title (title)
) ENGINE=InnoDB;

-- Версії файлу документа. Поточна — з найбільшим version_no; старі зберігаються й доступні за прямим посиланням.
-- stored_name — випадкове ім'я у storage/uploads (те саме сховище, що й у вкладень тікетів/задач).
CREATE TABLE IF NOT EXISTS library_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    version_no INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name CHAR(32) NOT NULL UNIQUE,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    comment VARCHAR(255) NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_library_versions_document FOREIGN KEY (document_id) REFERENCES library_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_library_versions_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_library_versions_doc_no (document_id, version_no)
) ENGINE=InnoDB;
