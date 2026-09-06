-- 015_whatsapp_conversations.sql — histórico de WhatsApp e a janela de 24h.
--
-- Estrutura adaptada do banlek-whatsapp-service (whatsapp_conversations e
-- whatsapp_incoming_messages), sem a parte que este projeto não tem: mídia em
-- S3, transcrição de áudio e sugestões de IA. Fica o essencial — quem falou
-- com a gente, o que foi dito nos dois sentidos, e até quando a Meta deixa
-- responder sem cobrar template.
--
-- POR QUE DATETIME E NÃO TIMESTAMP: a janela de 24h é uma comparação de
-- instantes, e TIMESTAMP é convertido pelo fuso da SESSÃO do MySQL — que não é
-- necessariamente o do PHP. Um servidor com os dois desalinhados abriria ou
-- fecharia a janela três horas cedo demais, em silêncio. Estas colunas guardam
-- UTC, escrito e comparado pelo PHP (`gmdate`), sem passar por NOW().

CREATE TABLE IF NOT EXISTS whatsapp_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- Número normalizado por normalizePhone(): só dígitos, com DDI.
    phone VARCHAR(20) NOT NULL,
    wa_id VARCHAR(20) NULL,
    profile_name VARCHAR(255) NULL,
    -- Cliente da Ecoleta, quando o número bate com um cadastro. Nulo é normal:
    -- qualquer pessoa pode escrever para o número da empresa.
    client_id INT NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    unread_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_inbound_at DATETIME NULL DEFAULT NULL,
    last_message_at DATETIME NULL DEFAULT NULL,
    last_message_preview VARCHAR(255) NULL,
    last_message_direction VARCHAR(10) NULL,
    -- Último inbound + 24h. Depois disso a Meta só entrega template aprovado.
    service_window_expires_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE INDEX idx_whatsapp_conversations_phone (phone),
    INDEX idx_whatsapp_conversations_client (client_id),
    INDEX idx_whatsapp_conversations_last_message (last_message_at),
    INDEX idx_whatsapp_conversations_window (service_window_expires_at),
    CONSTRAINT fk_whatsapp_conversations_client
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    -- `wamid...` da Meta. Único: é o que torna o webhook idempotente, porque a
    -- Meta reentrega o mesmo evento quando não recebe 200 rápido o bastante.
    -- Vários NULL convivem (mensagem nossa que nem chegou a ser aceita).
    wa_message_id VARCHAR(128) NULL,
    direction ENUM('incoming', 'outgoing') NOT NULL,
    type VARCHAR(30) NULL,
    -- accepted/sent/delivered/read/failed, atualizado pelos eventos `statuses`.
    status VARCHAR(20) NULL,
    body TEXT NULL,
    error_message TEXT NULL,
    raw_payload LONGTEXT NULL,
    message_at DATETIME NOT NULL,
    sent_by_user_id INT NULL,
    -- OS que originou a mensagem, quando saiu pelo botão do robô.
    service_order_id INT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE INDEX idx_whatsapp_messages_wa_id (wa_message_id),
    INDEX idx_whatsapp_messages_conversation (conversation_id, id),
    INDEX idx_whatsapp_messages_service_order (service_order_id),
    CONSTRAINT fk_whatsapp_messages_conversation
        FOREIGN KEY (conversation_id) REFERENCES whatsapp_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
