-- 001_initial.sql — tabelas base do dashboard.
--
-- Extração fiel do DDL que public/api/db.php executava dentro de
-- getDbConnection(), a cada requisição. Nada aqui muda o schema: são os mesmos
-- CREATE TABLE, apenas fora do caminho do request.
--
-- `users` nasce sem `group_id` de propósito. A coluna chegou depois (v1.2.0) e
-- é adicionada por 003_add_group_id.sql, do mesmo jeito que o banco de produção
-- a recebeu — assim o histórico das migrations bate com o histórico real.

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) NULL,
    role ENUM('root', 'master', 'user') NOT NULL DEFAULT 'user',
    force_password_change TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Um registro por login bem-sucedido.
CREATE TABLE IF NOT EXISTS access_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_access_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Trilha de auditoria: quem fez o quê, com quem, de onde.
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    target_login VARCHAR(50) NULL,
    action VARCHAR(50) NOT NULL,
    description VARCHAR(255) NULL,
    performed_by_id INT NULL,
    performed_by_login VARCHAR(50) NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
