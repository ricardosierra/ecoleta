<?php
declare(strict_types=1);

/**
 * Webhook do Asaas — é o que marca uma fatura como paga ou vencida.
 *
 * Este arquivo é público por obrigação: o Asaas chama sem sessão e sem token de
 * CSRF. Quem autentica é o token de acesso que o próprio painel do Asaas envia
 * no cabeçalho `asaas-access-token`, configurado junto com a URL do webhook.
 *
 * Falha fechado, sem `ASAAS_WEBHOOK_TOKEN` configurado não processa nada. Até a
 * versão anterior o endpoint aceitava qualquer corpo de qualquer origem, e um
 * POST de três linhas — `{"event":"PAYMENT_RECEIVED","payment":{"id":"..."}}` —
 * dava baixa em qualquer fatura para quem soubesse (ou adivinhasse) o id da
 * cobrança. Uma fatura marcada como recebida sem pagamento não volta sozinha:
 * some da régua de lembretes e ninguém percebe.
 *
 * Responde 200 para evento que consegue ler mas não usa: erro devolvido vira
 * reentrega, e o Asaas fila os eventos seguintes até o webhook voltar a responder.
 */

require_once __DIR__ . '/../db.php';

/** Resposta curta e encerramento. */
function asaasWebhookRespond(int $status, string $body): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }

    http_response_code($status);
    echo $body;
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    asaasWebhookRespond(405, json_encode(['error' => 'Método não permitido.']));
}

$esperado = apiSecret('ASAAS_WEBHOOK_TOKEN');

if ($esperado === '') {
    error_log('Webhook do Asaas: ASAAS_WEBHOOK_TOKEN não configurado — evento recusado.');
    asaasWebhookRespond(503, json_encode(['error' => 'unconfigured']));
}

$enviado = trim(apiRequestHeader('asaas-access-token'));

if ($enviado === '' || !hash_equals($esperado, $enviado)) {
    error_log('Webhook do Asaas: token inválido vindo de ' . apiClientIp());
    asaasWebhookRespond(401, json_encode(['error' => 'unauthorized']));
}

$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body) || !isset($body['event'])) {
    asaasWebhookRespond(400, json_encode(['error' => 'Invalid payload']));
}

$paymentId = trim((string) ($body['payment']['id'] ?? ''));

// PAYMENT_CONFIRMED é o dinheiro confirmado, PAYMENT_RECEIVED é o crédito na
// conta; para a régua de cobrança os dois significam a mesma coisa: pare de
// cobrar. PAYMENT_REFUNDED e PAYMENT_DELETED desfazem, e sem eles uma cobrança
// estornada ficaria "RECEIVED" para sempre.
$novoStatus = match ((string) $body['event']) {
    'PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED' => 'RECEIVED',
    'PAYMENT_OVERDUE' => 'OVERDUE',
    'PAYMENT_REFUNDED' => 'REFUNDED',
    'PAYMENT_DELETED' => 'DELETED',
    'PAYMENT_CHARGEBACK_REQUESTED' => 'CHARGEBACK_REQUESTED',
    default => null,
};

if ($novoStatus === null || $paymentId === '') {
    asaasWebhookRespond(200, json_encode(['ok' => true, 'ignored' => true]));
}

$db = getDbConnection();

try {
    $stmt = $db->prepare('UPDATE invoices SET status = ? WHERE asaas_payment_id = ?');
    $stmt->execute([$novoStatus, $paymentId]);
    $afetadas = $stmt->rowCount();
} catch (\Throwable $e) {
    error_log('Webhook do Asaas: falha ao atualizar a fatura ' . $paymentId . ': ' . $e->getMessage());
    asaasWebhookRespond(500, json_encode(['error' => 'update failed']));
}

if ($afetadas === 0) {
    // Cobrança criada fora do dashboard (ou já removida). Não é erro: só não é
    // nossa. Registrar ajuda a explicar uma fatura que "não muda de status".
    error_log(sprintf('Webhook do Asaas: pagamento %s não corresponde a nenhuma fatura local.', $paymentId));
}

asaasWebhookRespond(200, json_encode(['ok' => true, 'status' => $novoStatus, 'updated' => $afetadas]));
