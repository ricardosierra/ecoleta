-- 004_seed_groups.sql — grupos padrão que o dashboard espera encontrar.
--
-- Mesmo seed que db.php fazia (SELECT + INSERT quando faltava), reescrito como
-- INSERT ... WHERE NOT EXISTS: idempotente e resolvido em uma ida ao banco.
--
-- {{NEXT_PUBLIC_POWERBI_URL}} é substituído por db/migrate.php pelo valor de
-- configuração correspondente, já escapado por PDO::quote(). Quando a variável
-- não está definida, entra string vazia — mesmo comportamento do código antigo.
-- Grupo sem URL é normal: um administrador cadastra a dele em /dashboard/grupos.

INSERT INTO `groups` (name, powerbi_url)
SELECT 'Coleta', {{NEXT_PUBLIC_POWERBI_URL}}
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `groups` WHERE name = 'Coleta');

INSERT INTO `groups` (name, powerbi_url)
SELECT 'Infectantes', ''
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `groups` WHERE name = 'Infectantes');
