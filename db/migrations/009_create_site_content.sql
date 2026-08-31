-- 009_create_site_content.sql
-- Tabelas para gerenciamento do conteúdo do site (indicadores e clientes).

CREATE TABLE IF NOT EXISTS site_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    logo_url VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS site_indicators (
    id INT AUTO_INCREMENT PRIMARY KEY,
    indicator_key VARCHAR(50) NOT NULL UNIQUE,
    value VARCHAR(50) NOT NULL,
    label VARCHAR(100) NOT NULL,
    symbol_type ENUM('text', 'icon') NOT NULL DEFAULT 'text',
    symbol_value VARCHAR(100) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS site_indicator_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    indicator_key VARCHAR(50) NOT NULL,
    old_value VARCHAR(50) NOT NULL,
    new_value VARCHAR(50) NOT NULL,
    changed_by_id INT NULL,
    changed_by_login VARCHAR(50) NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_history_key (indicator_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inserir indicadores padrão se a tabela estiver vazia
INSERT IGNORE INTO site_indicators (indicator_key, value, label, symbol_type, symbol_value) VALUES
('custo', '↓R$', 'Redução de custo operacional', 'text', '↓R$'),
('aterro', '92%', 'Menos envio ao aterro', 'text', '92%'),
('docs', 'CheckIcon', 'Operação organizada e documentada', 'icon', 'CheckIcon'),
('seguranca', 'ScaleIcon', 'Segurança jurídica e ambiental', 'icon', 'ScaleIcon'),
('esg', 'ESG', 'Valor e reputação para sua marca', 'text', 'ESG');
