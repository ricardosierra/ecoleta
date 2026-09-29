-- 018_password_hash_history.sql — Histórico de alterações e auditoria de hash de senhas
--
-- Registra todo hash gerado ou alterado para qualquer usuário, permitindo detectar
-- e auditar trocas de senha, resets e invalidações de credenciais.

CREATE TABLE IF NOT EXISTS password_hash_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    old_hash VARCHAR(255) NULL,
    new_hash VARCHAR(255) NOT NULL,
    changed_by_user_id INT NULL,
    changed_by_login VARCHAR(100) NULL,
    change_type VARCHAR(50) NOT NULL DEFAULT 'direct_sql',
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Sem FK de propósito: apagar o usuário não pode apagar a trilha dele.
    INDEX idx_phh_user (user_id),
    INDEX idx_phh_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO password_hash_history (user_id, old_hash, new_hash, change_type, created_at)
SELECT id, NULL, password_hash, 'initial_state', created_at
FROM users
WHERE id NOT IN (SELECT DISTINCT user_id FROM password_hash_history);

DROP TRIGGER IF EXISTS trg_users_password_change;

CREATE TRIGGER trg_users_password_change
AFTER UPDATE ON users
FOR EACH ROW
INSERT INTO password_hash_history (user_id, old_hash, new_hash, change_type, created_at)
SELECT OLD.id, OLD.password_hash, NEW.password_hash, 'db_trigger', CURRENT_TIMESTAMP
WHERE OLD.password_hash <> NEW.password_hash;

DROP TRIGGER IF EXISTS trg_users_password_insert;

CREATE TRIGGER trg_users_password_insert
AFTER INSERT ON users
FOR EACH ROW
INSERT INTO password_hash_history (user_id, old_hash, new_hash, change_type, created_at)
VALUES (NEW.id, NULL, NEW.password_hash, 'db_trigger', CURRENT_TIMESTAMP);

DROP TRIGGER IF EXISTS trg_users_password_delete;

CREATE TRIGGER trg_users_password_delete
BEFORE DELETE ON users
FOR EACH ROW
INSERT INTO password_hash_history (user_id, old_hash, new_hash, change_type, created_at)
VALUES (OLD.id, OLD.password_hash, OLD.password_hash, 'user_deleted', CURRENT_TIMESTAMP);
