-- 003_add_group_id.sql — vincula usuários a grupos.
--
-- Equivale ao par SHOW COLUMNS + ALTER TABLE que db.php disparava a cada
-- requisição. MySQL 8 não tem `ADD COLUMN IF NOT EXISTS`, então a checagem é
-- feita no information_schema e o ALTER só é montado quando a coluna falta:
-- rodar de novo num banco que já tem a coluna não faz nada.

SET @ecoleta_has_group_id := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'group_id'
);

SET @ecoleta_ddl := IF(
    @ecoleta_has_group_id > 0,
    'DO 0',
    'ALTER TABLE users ADD COLUMN group_id INT NULL AFTER role'
);

PREPARE ecoleta_add_group_id FROM @ecoleta_ddl;
EXECUTE ecoleta_add_group_id;
DEALLOCATE PREPARE ecoleta_add_group_id;
