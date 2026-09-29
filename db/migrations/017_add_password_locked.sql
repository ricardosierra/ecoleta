-- 017_add_password_locked.sql — coluna de trava de troca de senha em users.
--
-- Padrão idempotente: MySQL não tem ADD COLUMN IF NOT EXISTS,
-- então a checagem vai ao information_schema.

SET @ecoleta_has_password_locked := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'password_locked'
);

SET @ecoleta_ddl := IF(
    @ecoleta_has_password_locked > 0,
    'DO 0',
    'ALTER TABLE users ADD COLUMN password_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER force_password_change'
);

PREPARE stmt FROM @ecoleta_ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
