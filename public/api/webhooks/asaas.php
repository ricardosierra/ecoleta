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

$payment = is_array($body['payment'] ?? null) ? $body['payment'] : [];
$paymentId = trim((string) ($payment['id'] ?? ''));
$evento = (string) $body['event'];

/**
 * Evento => [novo status, de quais status ele pode partir; `null` é qualquer um].
 *
 * PAYMENT_CONFIRMED é o dinheiro confirmado, PAYMENT_RECEIVED é o crédito na
 * conta; para a régua de cobrança os dois significam a mesma coisa: pare de
 * cobrar. PAYMENT_REFUNDED e PAYMENT_DELETED desfazem, e sem eles uma cobrança
 * estornada ficaria "RECEIVED" para sempre.
 *
 * O Asaas não garante a ordem de entrega, então o que importa é o que um evento
 * ATRASADO pode desfazer. PAYMENT_OVERDUE só vale para quem ainda está em aberto:
 * chegando depois do pagamento, do estorno ou do cancelamento, ele devolvia a
 * fatura para OVERDUE e o cron voltava a cobrar quem já tinha pago.
 *
 * PAYMENT_RESTORED e PAYMENT_RECEIVED_IN_CASH_UNDONE fazem o caminho inverso, e
 * cada um só parte do estado que desfaz. Os dois, e o PAYMENT_UPDATED abaixo,
 * vêm do que a documentação do Asaas descreve; não foram exercitados contra o
 * serviço real, por isso a tabela é estreita de propósito.
 */
const ASAAS_WEBHOOK_TRANSICOES = [
    'PAYMENT_RECEIVED' => ['RECEIVED', null],
    'PAYMENT_CONFIRMED' => ['RECEIVED', null],
    'PAYMENT_OVERDUE' => ['OVERDUE', ['PENDING', 'OVERDUE']],
    'PAYMENT_REFUNDED' => ['REFUNDED', null],
    'PAYMENT_DELETED' => ['DELETED', null],
    'PAYMENT_CHARGEBACK_REQUESTED' => ['CHARGEBACK_REQUESTED', null],
    'PAYMENT_RESTORED' => ['PENDING', ['DELETED']],
    'PAYMENT_RECEIVED_IN_CASH_UNDONE' => ['PENDING', ['RECEIVED', 'CONFIRMED']],
];

/** Faturas em aberto: as únicas cujo vencimento e valor ainda podem mudar. */
const ASAAS_WEBHOOK_STATUS_EM_ABERTO = ['PENDING', 'OVERDUE'];

/** `YYYY-MM-DD` real (sem 31 de fevereiro) e plausível, ou `null`. */
function asaasWebhookDate(mixed $valor): ?string
{
    if (!is_string($valor) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
        return null;
    }

    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    if ($data === false || $data->format('Y-m-d') !== $valor) {
        return null;
    }

    $ano = (int) $data->format('Y');

    return $ano >= 2000 && $ano <= 2100 ? $valor : null;
}

/** Valor em reais que cabe em `invoices.value` (DECIMAL(10,2)), ou `null`. */
function asaasWebhookMoney(mixed $valor): ?float
{
    if (!is_int($valor) && !is_float($valor) && !(is_string($valor) && is_numeric($valor))) {
        return null;
    }

    $reais = round((float) $valor, 2);

    return is_finite($reais) && $reais > 0 && $reais < 100000000 ? $reais : null;
}

$atualizacao = null;
$novoStatus = null;
$origens = null;

if ($evento === 'PAYMENT_UPDATED') {
    // Vencimento e valor alterados no painel do Asaas. Vale o par inteiro ou
    // nada: o Asaas manda o objeto de cobrança completo, e metade dele
    // indica um payload fora do contrato.
    $vencimento = asaasWebhookDate($payment['dueDate'] ?? null);
    $valor = asaasWebhookMoney($payment['value'] ?? null);
    if ($vencimento !== null && $valor !== null) {
        $atualizacao = [$vencimento, $valor];
    }
} elseif (isset(ASAAS_WEBHOOK_TRANSICOES[$evento])) {
    [$novoStatus, $origens] = ASAAS_WEBHOOK_TRANSICOES[$evento];
}

if (($novoStatus === null && $atualizacao === null) || $paymentId === '') {
    asaasWebhookRespond(200, json_encode(['ok' => true, 'ignored' => true]));
}

$db = getDbConnection();

try {
    // A condição de origem vai no próprio UPDATE: ler o status e decidir em PHP
    // deixaria dois eventos simultâneos passarem um por cima do outro.
    if ($atualizacao !== null) {
        $emAberto = implode(',', array_fill(0, count(ASAAS_WEBHOOK_STATUS_EM_ABERTO), '?'));
        $stmt = $db->prepare("UPDATE invoices SET due_date = ?, value = ? WHERE asaas_payment_id = ? AND status IN ($emAberto)");
        $stmt->execute([$atualizacao[0], $atualizacao[1], $paymentId, ...ASAAS_WEBHOOK_STATUS_EM_ABERTO]);
    } elseif ($origens === null) {
        $stmt = $db->prepare('UPDATE invoices SET status = ? WHERE asaas_payment_id = ?');
        $stmt->execute([$novoStatus, $paymentId]);
    } else {
        $marcadores = implode(',', array_fill(0, count($origens), '?'));
        $stmt = $db->prepare("UPDATE invoices SET status = ? WHERE asaas_payment_id = ? AND status IN ($marcadores)");
        $stmt->execute([$novoStatus, $paymentId, ...$origens]);
    }
    $afetadas = $stmt->rowCount();

    // Zero linhas tem três causas: a cobrança não é nossa, o evento não cabe no
    // estado atual da fatura, ou ela já estava como o evento manda (o MySQL conta
    // só linhas que mudaram). Só a consulta separa uma da outra.
    $statusAtual = null;
    if ($afetadas === 0) {
        $busca = $db->prepare('SELECT status FROM invoices WHERE asaas_payment_id = ? LIMIT 1');
        $busca->execute([$paymentId]);
        $achado = $busca->fetchColumn();
        $statusAtual = is_string($achado) ? $achado : null;
    }
} catch (\Throwable $e) {
    error_log('Webhook do Asaas: falha ao atualizar a fatura ' . $paymentId . ': ' . $e->getMessage());
    asaasWebhookRespond(500, json_encode(['error' => 'update failed']));
}

$resposta = ['ok' => true, 'status' => $novoStatus, 'updated' => $afetadas];

if ($afetadas === 0) {
    if ($statusAtual === null) {
        // Cobrança criada fora do dashboard (ou já removida). Não é erro: só não é
        // nossa. Registrar ajuda a explicar uma fatura que "não muda de status".
        error_log(sprintf('Webhook do Asaas: pagamento %s não corresponde a nenhuma fatura local.', $paymentId));
    } elseif (
        $atualizacao !== null
            ? !in_array($statusAtual, ASAAS_WEBHOOK_STATUS_EM_ABERTO, true)
            : $statusAtual !== $novoStatus
    ) {
        // Evento fora de ordem ou que não se aplica ao estado atual: recusado de
        // propósito. Responde 200 para o Asaas não reentregar e travar a fila.
        error_log(sprintf(
            'Webhook do Asaas: %s ignorado para o pagamento %s, que está em %s.',
            $evento,
            $paymentId,
            $statusAtual
        ));
        $resposta['ignored'] = true;
    }
}

asaasWebhookRespond(200, json_encode($resposta));
