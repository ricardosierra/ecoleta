-- 011_client_billing_fields.sql — dia de vencimento e status de cobrança no cliente.
--
-- Mesmo padrão da 003: MySQL 8 não tem `ADD COLUMN IF NOT EXISTS`, então a
-- checagem é feita no information_schema e o ALTER só é montado quando a
-- coluna falta — rodar de novo num banco que já tem as colunas não faz nada.

SET @ecoleta_has_due_day := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clients'
      AND COLUMN_NAME = 'due_day'
);

SET @ecoleta_ddl := IF(
    @ecoleta_has_due_day > 0,
    'DO 0',
    'ALTER TABLE clients ADD COLUMN due_day TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER monthly_value'
);

PREPARE ecoleta_add_due_day FROM @ecoleta_ddl;
EXECUTE ecoleta_add_due_day;
DEALLOCATE PREPARE ecoleta_add_due_day;

SET @ecoleta_has_status := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clients'
      AND COLUMN_NAME = 'status'
);

SET @ecoleta_ddl := IF(
    @ecoleta_has_status > 0,
    'DO 0',
    'ALTER TABLE clients ADD COLUMN status ENUM(''active'', ''inactive'') NOT NULL DEFAULT ''active'' AFTER due_day'
);

PREPARE ecoleta_add_status FROM @ecoleta_ddl;
EXECUTE ecoleta_add_status;
DEALLOCATE PREPARE ecoleta_add_status;
