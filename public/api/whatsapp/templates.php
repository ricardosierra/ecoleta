<?php
declare(strict_types=1);

/**
 * Consulta e envio de templates da Meta WhatsApp Cloud API.
 *
 * GET  - Lista de templates aprovados disponíveis.
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
    $token = apiSecret('WHATSAPP_ACCESS_TOKEN');
    $wabaId = apiSecret('WHATSAPP_BUSINESS_ACCOUNT_ID');

    $templates = [];

    // Tenta carregar os templates da Meta Cloud API
    if ($token !== '' && $wabaId !== '') {
        $version = WHATSAPP_API_VERSION;
        $url = "https://graph.facebook.com/{$version}/{$wabaId}/message_templates?fields=name,status,language,category,components&limit=100";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_TIMEOUT => 10,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && is_string($res)) {
            $data = json_decode($res, true);
            if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $tpl) {
                    $status = strtoupper((string) ($tpl['status'] ?? ''));
                    if ($status !== 'APPROVED' && $status !== '') {
                        continue;
                    }

                    $bodyText = '';
                    $paramsCount = 0;
                    foreach ($tpl['components'] ?? [] as $comp) {
                        if (($comp['type'] ?? '') === 'BODY') {
                            $bodyText = (string) ($comp['text'] ?? '');
                            // Conta variáveis {{1}}, {{2}}...
                            if (preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $m)) {
                                $paramsCount = count(array_unique($m[1]));
                            }
                            break;
                        }
                    }

                    $templates[] = [
                        'name' => (string) $tpl['name'],
                        'language' => (string) ($tpl['language'] ?? 'pt_BR'),
                        'category' => (string) ($tpl['category'] ?? 'UTILITY'),
                        'status' => $status,
                        'body_text' => $bodyText,
                        'params_count' => $paramsCount,
                    ];
                }
            }
        }
    }

    // Se a Meta não retornou ou retornou vazio, garante os templates homologados locais
    if ($templates === []) {
        $osTpl = apiSecret('WHATSAPP_OS_TEMPLATE') ?: 'ecoleva_ordem_servico';
        $billTpl = apiSecret('WHATSAPP_BILLING_TEMPLATE') ?: 'ecoleva_fatura_mensal';

        $templates = [
            [
                'name' => $osTpl,
                'language' => apiSecret('WHATSAPP_OS_TEMPLATE_LANG') ?: 'pt_BR',
                'category' => 'UTILITY',
                'status' => 'APPROVED',
                'body_text' => 'Olá {{1}}, sua Ordem de Serviço Nº {{2}} está disponível: {{3}}',
                'params_count' => 3,
                'param_labels' => ['Nome do Cliente', 'Número da OS', 'Link da OS'],
            ],
            [
                'name' => $billTpl,
                'language' => apiSecret('WHATSAPP_BILLING_TEMPLATE_LANG') ?: 'pt_BR',
                'category' => 'UTILITY',
                'status' => 'APPROVED',
                'body_text' => 'Olá {{1}}, sua fatura da Ecoleva no valor de R$ {{2}} vence em {{3}}. Link: {{4}}',
                'params_count' => 4,
                'param_labels' => ['Nome do Cliente', 'Valor (R$)', 'Vencimento', 'Link da Fatura'],
            ],
        ];
    }

    apiJsonResponse(200, ['ok' => true, 'templates' => $templates]);
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
