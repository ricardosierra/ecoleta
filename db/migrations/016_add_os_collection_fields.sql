-- 016_add_os_collection_fields.sql — novos campos na Ordem de Serviço (OS):
-- endereço da coleta, horário aproximado e material coletado.
--
-- Padrão idempotente das migrations anteriores: MySQL 8 não tem
-- `ADD COLUMN IF NOT EXISTS`, então a checagem vai ao information_schema
-- e o ALTER só é montado quando a coluna falta.

SET @ecoleta_has_collection_address := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_orders'
      AND COLUMN_NAME = 'collection_address'
);

SET @ecoleta_ddl := IF(
    @ecoleta_has_collection_address > 0,
    'DO 0',
    'ALTER TABLE service_orders ADD COLUMN collection_address VARCHAR(255) NULL AFTER client_id'
);

PREPARE ecoleta_add_collection_address FROM @ecoleta_ddl;
EXECUTE ecoleta_add_collection_address;
DEALLOCATE PREPARE ecoleta_add_collection_address;

SET @ecoleta_has_approximate_time := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_orders'
      AND COLUMN_NAME = 'approximate_time'
);

SET @ecoleta_ddl := IF(
    @ecoleta_has_approximate_time > 0,
    'DO 0',
    'ALTER TABLE service_orders ADD COLUMN approximate_time VARCHAR(50) NULL AFTER collection_date'
);

PREPARE ecoleta_add_approximate_time FROM @ecoleta_ddl;
EXECUTE ecoleta_add_approximate_time;
DEALLOCATE PREPARE ecoleta_add_approximate_time;

SET @ecoleta_has_material_collected := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_orders'
      AND COLUMN_NAME = 'material_collected'
);

SET @ecoleta_ddl := IF(
    @ecoleta_has_material_collected > 0,
    'DO 0',
    'ALTER TABLE service_orders ADD COLUMN material_collected VARCHAR(255) NULL AFTER approximate_time'
);

PREPARE ecoleta_add_material_collected FROM @ecoleta_ddl;
EXECUTE ecoleta_add_material_collected;
DEALLOCATE PREPARE ecoleta_add_material_collected;
