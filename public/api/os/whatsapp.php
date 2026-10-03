<?php
declare(strict_types=1);

/**
 * Encaminha uma Ordem de Serviço pelo WhatsApp do robô (Cloud API da Meta).
 *
 * O outro botão da tela, o WhatsApp pessoal, não chega aqui: ele só abre um
 * link `wa.me` no navegador do operador. Este é o caminho em que o servidor
 * envia sozinho, e por isso é o único que pode disparar duas vezes sem ninguém
 * perceber — daí o `confirm` exigido para reenvio, logo abaixo.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';
require_once __DIR__ . '/../whatsapp_lib.php';
require_once __DIR__ . '/../whatsapp_store.php';
require_once __DIR__ . '/../clients/phone_lib.php';
require_once __DIR__ . '/os_lib.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();

$operator = apiRequireAdmin();

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    apiJsonResponse(405, ['error' => 'Método não permitido.'], ['Allow: POST']);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}

$id = (int) ($body['id'] ?? 0);
if ($id <= 0) {
    apiJsonResponse(400, ['error' => 'Informe a OS a encaminhar.']);
}

$db = getDbConnection();
$os = osFindById($db, $id);

if ($os === null) {
    apiJsonResponse(404, ['error' => 'Ordem de serviço não encontrada.']);
}

$destino = normalizePhone((string) ($body['whatsapp'] ?? '')) ?: normalizePhone((string) ($os['client_whatsapp'] ?? ''));

if ($destino === '') {
    apiJsonResponse(400, [
        'error' => 'Este cliente não tem WhatsApp cadastrado.',
        'code' => 'whatsapp_missing_number',
    ]);
}

// Reenvio exige confirmação explícita da tela. O servidor é quem sabe se já
// houve disparo — checar só no navegador deixaria duas abas, ou um clique
// duplo, mandando a mesma OS ao cliente duas vezes sem nenhum aviso.
//
// Esta leitura é só o aviso amigável. Quem garante que UMA chamada envia é a
// reserva atômica mais abaixo (osWhatsAppReserve): entre ler aqui e enviar passa
// a chamada à Meta, de até 20 segundos, e duas abas leem "não enviada" ao mesmo
// tempo.
$jaEnviada = trim((string) ($os['whatsapp_sent_at'] ?? '')) !== '';
if ($jaEnviada && empty($body['confirm'])) {
    apiJsonResponse(409, [
        'error' => 'Esta OS já foi enviada pelo WhatsApp do robô.',
        'code' => 'whatsapp_already_sent',
        'whatsapp_sent_at' => $os['whatsapp_sent_at'],
        'whatsapp_sent_to' => $os['whatsapp_sent_to'],
    ]);
}

// A checagem de credenciais vem depois da validação de propósito: quem clicou
// precisa ouvir "este cliente não tem WhatsApp" ou "esta OS já foi enviada"
// mesmo em um servidor sem as chaves da Meta — são problemas dele, não do
// servidor, e a confirmação de reenvio não pode depender de configuração.
if (!waIsConfigured()) {
    apiJsonResponse(503, [
        'error' => 'O WhatsApp do robô não está configurado neste servidor.',
        'code' => 'whatsapp_not_configured',
    ]);
}

// OS anterior à migration 014 pode não ter token; gera na hora em vez de mandar
// uma mensagem com um link que não abre.
$token = (string) ($os['share_token'] ?? '');
if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
    $token = osShareTokenNew();
    $stmt = $db->prepare('UPDATE service_orders SET share_token = ? WHERE id = ?');
    $stmt->execute([$token, $id]);
    $os['share_token'] = $token;
}

$shareUrl = osShareUrl($id, $token);
$texto = osWhatsAppText($os, $shareUrl);

// A conversa existe desde a primeira mensagem que o cliente nos mandou. É dela
// que sai a janela de 24h — e é ela que recebe a mensagem gravada logo abaixo,
// para a OS enviada aparecer na tela de conversas junto do resto do histórico.
$conversaId = waEnsureConversation($db, $destino, ['client_id' => $os['client_id'] ?? null]);
$conversa = waFindConversationByPhone($db, $destino);
$janelaAberta = waWindowIsOpen(
    $conversa === null ? null : ($conversa['service_window_expires_at'] ?? null)
);

// Fora da janela de 24h o envio sai como template, e a Meta COBRA. Isso o
// servidor já sabe aqui, antes de gastar: pede a confirmação do operador em vez
// de descobrir pelo campo `billable` da resposta, que só chega depois de enviado.
// O código vale para a OS nova e para o reenvio (cada confirmação é separada).
$plano = osWhatsAppSendPlan($janelaAberta, apiSecret('WHATSAPP_OS_TEMPLATE'));
if ($plano === 'cobrado' && empty($body['confirm_billable'])) {
    apiJsonResponse(409, [
        'error' => 'Fora da janela de 24 horas este envio usa template e é cobrado pela Meta.',
        'code' => 'whatsapp_billable_confirmation_required',
        'billable' => true,
    ]);
}

// Reserva ATÔMICA, antes de chamar a Meta: um UPDATE condicional que só uma das
// chamadas simultâneas vence. Perdeu, não envia: outra aba ou outro operador
// chegou primeiro, e a tela mostra a confirmação de reenvio com os dados dele.
$reserva = osWhatsAppReserve(
    $db,
    $id,
    $os['whatsapp_sent_at'] ?? null,
    $os['whatsapp_sent_to'] ?? null,
    $destino
);

if ($reserva === null) {
    $atual = osFindById($db, $id);
    apiJsonResponse(409, [
        'error' => 'Esta OS já foi enviada pelo WhatsApp do robô.',
        'code' => 'whatsapp_already_sent',
        'whatsapp_sent_at' => $atual['whatsapp_sent_at'] ?? null,
        'whatsapp_sent_to' => $atual['whatsapp_sent_to'] ?? null,
    ]);
}

try {
    $resposta = waSendOsMessage(
        $destino,
        (string) ($os['client_name'] ?? ''),
        osNumber($id),
        $shareUrl,
        $texto,
        $janelaAberta
    );
} catch (Throwable $e) {
    // Não saiu: a OS volta a constar como estava, e o operador pode tentar de
    // novo sem ver "já enviada" por um envio que não aconteceu.
    osWhatsAppRelease($db, $id, $reserva);

    if (!$e instanceof WhatsAppApiException) {
        throw $e;
    }

    // O que não saiu também fica registrado: a tela de conversas precisa
    // mostrar a tentativa, não só os acertos.
    osRecordWhatsAppSent($db, $conversaId, [
        'direction' => 'outgoing',
        'type' => $janelaAberta ? 'text' : 'template',
        'status' => 'failed',
        'body' => $texto,
        'error_message' => $e->getMessage(),
        'sent_by_user_id' => $operator['id'],
        'service_order_id' => $id,
    ]);

    // Janela de 24h fechada não é erro de configuração: é a regra da Meta. A
    // tela usa este código para oferecer o WhatsApp pessoal como saída.
    if ($e->outsideWindow) {
        apiJsonResponse(422, [
            'error' => $e->getMessage(),
            'code' => 'whatsapp_outside_window',
        ]);
    }

    apiJsonResponse(502, [
        'error' => $e->getMessage(),
        'code' => 'whatsapp_send_failed',
    ]);
}

// A Meta aceitou: a marca de envio já está na OS desde a reserva. Gravar a
// mensagem no histórico de conversas nunca derruba a resposta; se falhar, o
// envio que o cliente vai receber não vira um 500 que convida a reenviar.
osRecordWhatsAppSent($db, $conversaId, [
    'wa_message_id' => waExtractSentMessageId($resposta),
    'direction' => 'outgoing',
    'type' => $janelaAberta ? 'text' : 'template',
    'status' => 'accepted',
    'body' => $texto,
    'raw_payload' => $resposta,
    'sent_by_user_id' => $operator['id'],
    'service_order_id' => $id,
]);

logActivity(
    $db,
    null,
    'os_send_whatsapp',
    sprintf('OS #%s encaminhada por WhatsApp para %s', osNumber($id), $destino),
    $operator['id'],
    $operator['login']
);

$atualizada = osFindById($db, $id);

apiJsonResponse(200, [
    'ok' => true,
    'whatsapp_sent_to' => $destino,
    'whatsapp_sent_at' => $atualizada['whatsapp_sent_at'] ?? null,
    // Logo depois do envio a Meta só ACEITOU o pedido; entrega e leitura chegam
    // depois, pelo webhook, e a listagem de OS as devolve.
    'whatsapp_status' => 'accepted',
    'share_url' => $shareUrl,
    'billable' => $plano === 'cobrado',
]);
