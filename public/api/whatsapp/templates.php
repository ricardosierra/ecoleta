<?php
declare(strict_types=1);

/**
 * Consulta e envio de templates da Meta WhatsApp Cloud API.
 *
 * GET  - Lista de templates da conta, cada um com o status real na Meta.
 * POST - Disparo de template em conversa (funciona mesmo com a janela de 24h fechada).
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';
require_once __DIR__ . '/../whatsapp_store.php';
require_once __DIR__ . '/../whatsapp_lib.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();

$db = getDbConnection();
$actor = waRequirePanelAccess($db);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    // Lista com o status REAL de cada template. Quando a Meta não pode ser
    // consultada (token vencido, conta errada, rede), a resposta traz o erro em
    // português e, no lugar da lista, só o que está configurado no servidor,
    // marcado como não verificado. Nunca se apresenta como aprovado o que não se
    // sabe que está: a tela usa o status para decidir se deixa enviar.
    $resultado = waFetchTemplates();

    if ($resultado['error'] !== null) {
        $resultado['templates'] = waConfiguredTemplates();
    }

    apiJsonResponse(200, [
        'ok' => true,
        'templates' => $resultado['templates'],
        'source' => $resultado['error'] === null ? 'meta' : 'config',
        'error' => $resultado['error'],
        'error_detail' => $resultado['error_detail'],
    ]);
}

if ($method === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        apiJsonResponse(400, ['error' => 'Payload JSON inválido.']);
    }

    $conversationId = (int) ($body['conversation_id'] ?? 0);
    $templateName = trim((string) ($body['template_name'] ?? ''));
    $templateLang = trim((string) ($body['template_language'] ?? 'pt_BR')) ?: 'pt_BR';
    $params = is_array($body['parameters'] ?? null) ? $body['parameters'] : [];

    if ($conversationId <= 0 || $templateName === '') {
        apiJsonResponse(400, ['error' => 'Conversa ou nome do template inválido.']);
    }

    $stmt = $db->prepare('SELECT * FROM whatsapp_conversations WHERE id = ? LIMIT 1');
    $stmt->execute([$conversationId]);
    $conversa = $stmt->fetch();

    if (!$conversa) {
        apiJsonResponse(404, ['error' => 'Conversa não encontrada.']);
    }

    if (!waIsConfigured()) {
        apiJsonResponse(503, [
            'error' => 'O WhatsApp do robô não está configurado neste servidor.',
            'code' => 'whatsapp_not_configured',
        ]);
    }

    $destinatario = preg_replace('/\D+/', '', (string) $conversa['phone']) ?? '';
    $components = [];

    if ($params !== []) {
        $parametersObj = [];
        foreach ($params as $paramValue) {
            $parametersObj[] = [
                'type' => 'text',
                'text' => (string) $paramValue,
            ];
        }
        $components[] = [
            'type' => 'body',
            'parameters' => $parametersObj,
        ];
    }

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $destinatario,
        'type' => 'template',
        'template' => [
            'name' => $templateName,
            'language' => ['code' => $templateLang],
        ],
    ];

    if ($components !== []) {
        $payload['template']['components'] = $components;
    }

    try {
        $response = waRequest($payload);
    } catch (\Throwable $e) {
        apiJsonResponse(502, ['error' => 'Falha ao enviar template: ' . $e->getMessage()]);
    }

    $waMessageId = waExtractSentMessageId($response);
    $nowUtc = waNow();

    $bodyPreview = "[Template: {$templateName}]" . ($params !== [] ? ' ' . implode(' | ', array_map('strval', $params)) : '');

    $msgId = waRecordMessage($db, $conversationId, [
        'wa_message_id' => $waMessageId,
        'direction' => 'outgoing',
        'type' => 'template',
        'status' => 'accepted',
        'body' => $bodyPreview,
        'raw_payload' => $response,
        'message_at' => $nowUtc,
        'sent_by_user_id' => $actor['id'] ?? null,
    ]);

    apiJsonResponse(200, [
        'ok' => true,
        'message' => [
            'id' => $msgId,
            'direction' => 'outgoing',
            'type' => 'template',
            'status' => 'accepted',
            'body' => $bodyPreview,
            'error_message' => null,
            'message_at' => waToIso($nowUtc),
            'service_order_id' => null,
        ],
    ]);
}

apiJsonResponse(405, ['error' => 'Método não permitido.'], ['Allow: GET, POST']);
