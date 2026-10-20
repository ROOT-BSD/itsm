-- Міграція 047: реєстр обладнання й ПЗ (CMDB-мінімум).
--
--  * assets        — картки активів (тип, назва, інвентарний і серійний №, виробник/модель, власник, місце, гарантія, статус);
--  * asset_links   — зв'язки між активами («підключено до», «встановлено на», «входить до складу»);
--  * asset_tickets — прив'язка активів до тікетів.
-- Історія змін активу ведеться в журналі аудиту (entity_type = 'asset').
--
-- Скрипт безпечний для повторного запуску.

CREATE TABLE IF NOT EXISTS assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_type VARCHAR(30) NOT NULL DEFAULT 'other',
    name VARCHAR(200) NOT NULL,
    inventory_number VARCHAR(80) NULL,
    serial_number VARCHAR(120) NULL,
    manufacturer VARCHAR(120) NULL,
    model VARCHAR(120) NULL,
    status ENUM('in_use','in_stock','repair','written_off') NOT NULL DEFAULT 'in_use',
    owner_user_id INT NULL,
    location VARCHAR(200) NULL,
    network_id VARCHAR(120) NULL,
    purchase_date DATE NULL,
    warranty_until DATE NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_assets_inventory (inventory_number),
    KEY idx_assets_owner (owner_user_id),
    KEY idx_assets_type_status (asset_type, status),
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    linked_id INT NOT NULL,
    relation ENUM('connected_to','installed_on','part_of') NOT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_links (asset_id, linked_id, relation),
    KEY idx_asset_links_linked (linked_id),
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (linked_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_tickets (
    asset_id INT NOT NULL,
    ticket_id INT NOT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (asset_id, ticket_id),
    KEY idx_asset_tickets_ticket (ticket_id),
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
