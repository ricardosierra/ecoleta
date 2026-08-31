-- 006_create_clients.sql — tabela de clientes.

CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL,
    whatsapp VARCHAR(20) NULL,
    document VARCHAR(20) NULL, -- CPF or CNPJ
    monthly_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    asaas_customer_id VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_clients_document (document),
    INDEX idx_clients_asaas (asaas_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
