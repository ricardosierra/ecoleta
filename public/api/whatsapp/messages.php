<?php
declare(strict_types=1);

/**
 * Mensagens de uma conversa — o corpo da tela de visualização.
 *
 * GET  ?conversation_id=N  devolve a conversa e o histórico em ordem cronológica.
 * POST {conversation_id}   zera o contador de não lidas.
 *
 * A marcação de lida é POST, e não um efeito colateral do GET, porque zerar
 * contador é escrita: em GET ela escaparia do token CSRF e seria refeita por
 * qualquer prefetch do navegador.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';
require_once __DIR__ . '/../whatsapp_store.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();

$db = getDbConnection();
waRequirePanelAccess($db);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $conversationId = (int) (is_array($body) ? ($body['conversation_id'] ?? 0) : 0);

    if ($conversationId <= 0) {
        apiJsonResponse(400, ['error' => 'Conversa inválida.']);
    }

    $stmt = $db->prepare('UPDATE whatsapp_conversations SET unread_count = 0, updated_at = ? WHERE id = ?');
    $stmt->execute([waNow(), $conversationId]);

    apiJsonResponse(200, ['ok' => true]);
}

if ($method !== 'GET') {
    apiJsonResponse(405, ['error' => 'Método não permitido.'], ['Allow: GET, POST']);
}

$conversationId = (int) ($_GET['conversation_id'] ?? 0);
if ($conversationId <= 0) {
    apiJsonResponse(400, ['error' => 'Conversa inválida.']);
}

$stmt = $db->prepare('
    SELECT c.*, cl.name AS client_name
      FROM whatsapp_conversations c
      LEFT JOIN clients cl ON cl.id = c.client_id
     WHERE c.id = ?
     LIMIT 1
');
$stmt->execute([$conversationId]);
$conversa = $stmt->fetch();

if (!is_array($conversa)) {
    apiJsonResponse(404, ['error' => 'Conversa não encontrada.']);
}

$stmt = $db->prepare('
    SELECT id, wa_message_id, direction, type, status, body, error_message,
           message_at, sent_by_user_id, service_order_id
      FROM whatsapp_messages
     WHERE conversation_id = ?
     ORDER BY message_at ASC, id ASC
     LIMIT 500
');
$stmt->execute([$conversationId]);

$mensagens = [];
foreach ($stmt->fetchAll() as $linha) {
    $mensagens[] = [
        'id' => (int) $linha['id'],
        'direction' => (string) $linha['direction'],
        'type' => $linha['type'],
        'status' => $linha['status'],
        'body' => $linha['body'],
        'error_message' => $linha['error_message'],
        'message_at' => waToIso($linha['message_at']),
        'service_order_id' => $linha['service_order_id'] === null ? null : (int) $linha['service_order_id'],
    ];
}

$agora = waNow();

apiJsonResponse(200, [
    'ok' => true,
    'conversation' => [
        'id' => (int) $conversa['id'],
        'phone' => (string) $conversa['phone'],
        'name' => (string) ($conversa['client_name'] ?? $conversa['profile_name'] ?? ''),
        'profile_name' => $conversa['profile_name'],
        'client_id' => $conversa['client_id'] === null ? null : (int) $conversa['client_id'],
        'client_name' => $conversa['client_name'],
        'status' => (string) $conversa['status'],
        'unread_count' => (int) $conversa['unread_count'],
        'last_message_at' => waToIso($conversa['last_message_at']),
        'window' => waWindowState($conversa['service_window_expires_at'], $agora),
    ],
    'messages' => $mensagens,
]);
