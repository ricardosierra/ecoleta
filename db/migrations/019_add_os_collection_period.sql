-- 019_add_os_collection_period.sql — período recorrente da coleta na OS.

SET @ecoleta_has_collection_period := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_orders'
      AND COLUMN_NAME = 'collection_period'
);

SET @ecoleta_ddl := IF(
    @ecoleta_has_collection_period > 0,
    'DO 0',
    'ALTER TABLE service_orders ADD COLUMN collection_period VARCHAR(255) NULL AFTER collection_date'
);

PREPARE ecoleta_add_collection_period FROM @ecoleta_ddl;
EXECUTE ecoleta_add_collection_period;
DEALLOCATE PREPARE ecoleta_add_collection_period;
