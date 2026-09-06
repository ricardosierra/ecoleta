-- 014_service_order_share.sql — encaminhamento da OS por e-mail e WhatsApp.
--
-- `sent_at`/`sent_to` guardam o último envio por e-mail; `whatsapp_sent_at`/
-- `whatsapp_sent_to`, o último disparo pela API Cloud do WhatsApp. São pares
-- separados de propósito: a tela avisa "esta OS já foi enviada pelo robô" antes
-- de repetir o disparo, e um e-mail anterior não deve disparar esse aviso.
--
-- `share_token` é o segredo do link público (api/os/view.php): quem recebe a OS
-- não tem login no dashboard, então a autorização é a posse do token. Fica na
-- linha, e não em um HMAC derivado de um segredo global, para que revogar um
-- link seja um UPDATE — e para não obrigar o deploy a carregar mais um segredo.
--
-- Mesmo padrão idempotente da 011: MySQL 8 não tem `ADD COLUMN IF NOT EXISTS`,
-- então a checagem vai ao information_schema e o ALTER só é montado quando a
-- coluna falta.

SET @ecoleta_has_share_token := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_orders'
      AND COLUMN_NAME = 'share_token'
);

SET @ecoleta_ddl := IF(
    @ecoleta_has_share_token > 0,
    'DO 0',
    'ALTER TABLE service_orders ADD COLUMN share_token VARCHAR(64) NULL AFTER signature_text'
);

PREPARE ecoleta_add_share_token FROM @ecoleta_ddl;
EXECUTE ecoleta_add_share_token;
DEALLOCATE PREPARE ecoleta_add_share_token;

SET @ecoleta_has_sent_at := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_orders'
      AND COLUMN_NAME = 'sent_at'
);

SET @ecoleta_ddl := IF(
    @ecoleta_has_sent_at > 0,
    'DO 0',
    'ALTER TABLE service_orders ADD COLUMN sent_at TIMESTAMP NULL DEFAULT NULL AFTER share_token'
);

PREPARE ecoleta_add_sent_at FROM @ecoleta_ddl;
EXECUTE ecoleta_add_sent_at;
DEALLOCATE PREPARE ecoleta_add_sent_at;

SET @ecoleta_has_sent_to := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_orders'
      AND COLUMN_NAME = 'sent_to'
);

SET @ecoleta_ddl := IF(
    @ecoleta_has_sent_to > 0,
    'DO 0',
    'ALTER TABLE service_orders ADD COLUMN sent_to VARCHAR(255) NULL AFTER sent_at'
);

PREPARE ecoleta_add_sent_to FROM @ecoleta_ddl;
EXECUTE ecoleta_add_sent_to;
DEALLOCATE PREPARE ecoleta_add_sent_to;

SET @ecoleta_has_wa_sent_at := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_orders'
      AND COLUMN_NAME = 'whatsapp_sent_at'
);

SET @ecoleta_ddl := IF(
    @ecoleta_has_wa_sent_at > 0,
    'DO 0',
    'ALTER TABLE service_orders ADD COLUMN whatsapp_sent_at TIMESTAMP NULL DEFAULT NULL AFTER sent_to'
);

PREPARE ecoleta_add_wa_sent_at FROM @ecoleta_ddl;
EXECUTE ecoleta_add_wa_sent_at;
DEALLOCATE PREPARE ecoleta_add_wa_sent_at;

SET @ecoleta_has_wa_sent_to := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_orders'
      AND COLUMN_NAME = 'whatsapp_sent_to'
);

SET @ecoleta_ddl := IF(
    @ecoleta_has_wa_sent_to > 0,
    'DO 0',
    'ALTER TABLE service_orders ADD COLUMN whatsapp_sent_to VARCHAR(20) NULL AFTER whatsapp_sent_at'
);

PREPARE ecoleta_add_wa_sent_to FROM @ecoleta_ddl;
EXECUTE ecoleta_add_wa_sent_to;
DEALLOCATE PREPARE ecoleta_add_wa_sent_to;

-- OS criadas antes desta migration não têm token: sem backfill, o botão de
-- encaminhar não funcionaria para o histórico existente. SHA2 de id + RAND() +
-- UUID() dá 64 hex por linha, e o UUID garante que duas linhas sorteadas no
-- mesmo instante não colidam.
UPDATE service_orders
   SET share_token = SHA2(CONCAT(id, '-', RAND(), '-', UUID()), 256)
 WHERE share_token IS NULL;

SET @ecoleta_has_share_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_orders'
      AND INDEX_NAME = 'idx_service_orders_share_token'
);

SET @ecoleta_ddl := IF(
    @ecoleta_has_share_index > 0,
    'DO 0',
    'CREATE UNIQUE INDEX idx_service_orders_share_token ON service_orders (share_token)'
);

PREPARE ecoleta_add_share_index FROM @ecoleta_ddl;
EXECUTE ecoleta_add_share_index;
DEALLOCATE PREPARE ecoleta_add_share_index;
