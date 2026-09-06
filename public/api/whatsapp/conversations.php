<?php
declare(strict_types=1);

/**
 * Lista de conversas do painel de WhatsApp.
 *
 * Acesso restrito por `waRequirePanelAccess()`: `root` E na lista de e-mails de
 * `apiRoleCanViewWhatsAppPanel()`. Conversa de cliente é material sensível —
 * nem todo administrador do dashboard precisa lê-la.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';
require_once __DIR__ . '/../whatsapp_store.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();

$db = getDbConnection();
waRequirePanelAccess($db);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    apiJsonResponse(405, ['error' => 'Método não permitido.'], ['Allow: GET']);
}

$stmt = $db->query('
    SELECT c.id, c.phone, c.wa_id, c.profile_name, c.client_id, c.status, c.unread_count,
           c.last_inbound_at, c.last_message_at, c.last_message_preview, c.last_message_direction,
           c.service_window_expires_at, c.created_at,
           cl.name AS client_name
      FROM whatsapp_conversations c
      LEFT JOIN clients cl ON cl.id = c.client_id
     ORDER BY (c.last_message_at IS NULL), c.last_message_at DESC, c.id DESC
     LIMIT 300
');

$agora = waNow();
$conversas = [];

foreach ($stmt->fetchAll() as $linha) {
    $conversas[] = [
        'id' => (int) $linha['id'],
        'phone' => (string) $linha['phone'],
        // O nome do cadastro vence o do perfil do WhatsApp: é como a equipe
        // chama o cliente, e o perfil é o que a pessoa escolheu para si.
        'name' => (string) ($linha['client_name'] ?? $linha['profile_name'] ?? ''),
        'profile_name' => $linha['profile_name'],
        'client_id' => $linha['client_id'] === null ? null : (int) $linha['client_id'],
        'client_name' => $linha['client_name'],
        'status' => (string) $linha['status'],
        'unread_count' => (int) $linha['unread_count'],
        'last_message_at' => waToIso($linha['last_message_at']),
        'last_message_preview' => $linha['last_message_preview'],
        'last_message_direction' => $linha['last_message_direction'],
        'window' => waWindowState($linha['service_window_expires_at'], $agora),
    ];
}

apiJsonResponse(200, ['ok' => true, 'conversations' => $conversas]);
