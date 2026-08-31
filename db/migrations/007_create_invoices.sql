-- 007_create_invoices.sql — tabela de faturas (cobranças do Asaas).

CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    asaas_payment_id VARCHAR(255) NOT NULL,
    value DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'PENDING', -- PENDING, RECEIVED, OVERDUE
    invoice_url VARCHAR(255) NULL,
    pix_qrcode_text TEXT NULL,
    pix_qrcode_url VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_invoices_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_invoices_asaas (asaas_payment_id),
    INDEX idx_invoices_status (status),
    INDEX idx_invoices_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
