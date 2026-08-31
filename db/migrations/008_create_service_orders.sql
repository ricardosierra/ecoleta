-- 008_create_service_orders.sql — tabela de ordens de serviço (OS).

CREATE TABLE IF NOT EXISTS service_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    weight VARCHAR(50) NULL,
    collection_date DATE NULL,
    bags_count INT NULL,
    containers_count INT NULL,
    responsible VARCHAR(255) NULL,
    signature_text VARCHAR(255) NOT NULL DEFAULT 'Responsável Técnica - ECOLEVA',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_service_orders_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_service_orders_date (collection_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
