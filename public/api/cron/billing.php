<?php
declare(strict_types=1);

/**
 * Faturamento mensal automático.
 *
 * Roda por cron (HTTP ou CLI), UMA VEZ POR DIA, e a cada execução faz duas coisas:
 *
 *   garante a fatura de cada cliente ativo com valor mensal positivo
 *       o vencimento do mês corrente, enquanto ele não passou, e o do mês seguinte
 *       a partir do dia 30 (ou do último dia do mês, quando o mês não chega ao 30).
 *       O que já existe é reconhecido e pulado, então rodar todo dia é seguro e
 *       recupera sozinho um dia perdido, uma falha do Asaas e o cliente cadastrado
 *       no meio do mês. Cria a cobrança no Asaas, grava em `invoices` e manda o
 *       e-mail e o WhatsApp com o Pix e o link do boleto.
 *
 *   dias 3 e 7
 *       relembra o que continua `PENDING`/`OVERDUE` com vencimento dentro do mês.
 *
 * A decisão de quando cobrar e o documento que o cliente recebe moram em
 * `billing_lib.php`, onde a suíte consegue exercitá-los; o ciclo em si
 * (`billingRunCycle`) mora em `billing_delivery.php`, pelo mesmo motivo. Aqui fica
 * só o que é porta de entrada: segredo, relógio e resposta.
 *
 * Cada execução deixa um registro (`billingRecordRun`) que a tela de Faturas lê:
 * é o que avisa a operadora quando o agendamento do servidor deixa de rodar.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../asaas_lib.php';
require_once __DIR__ . '/../billing_lib.php';
require_once __DIR__ . '/../os/os_lib.php';

// ── Quem pode disparar ───────────────────────────────────────────────────────
// Falha fechado: sem CRON_SECRET configurado o endpoint não roda. A versão
// anterior caía em um valor embutido no código ('ecoleva_cron_secret'), que está
// publicado neste repositório — qualquer pessoa na internet podia emitir as
// cobranças do mês e disparar os e-mails.
//
// O segredo vem preferencialmente pelo cabeçalho `X-Cron-Secret`, que é o que a
// documentação sempre prometeu (.env.example) e o que não fica gravado no log de
// acesso do servidor. O `?secret=` continua aceito para não quebrar um cron já
// agendado, mas o cabeçalho é o caminho a usar.
$cronSecret = apiSecret('CRON_SECRET');

if ($cronSecret === '') {
    error_log('cron/billing.php: CRON_SECRET não configurado — execução recusada.');
    http_response_code(503);
    exit("CRON_SECRET não configurado no servidor.\n");
}

$enviado = trim(apiRequestHeader('X-Cron-Secret'));
if ($enviado === '') {
    $enviado = trim((string) ($_GET['secret'] ?? ''));
}

// A scheduler running PHP locally already has the server's filesystem access.
if (PHP_SAPI === 'cli' && getenv('ECOLETA_TEST_CONTEXT') === false) {
    $enviado = $cronSecret;
}

if ($enviado === '' || !hash_equals($cronSecret, $enviado)) {
    error_log('cron/billing.php: segredo inválido vindo de ' . apiClientIp());
    http_response_code(403);
    exit("Acesso negado.\n");
}

require_once __DIR__ . '/../billing_delivery.php';

// Uma fatura emitida no Asaas e ainda não gravada é o pior estado possível: a
// execução precisa terminar mesmo que quem agendou o cron desconecte, e o limite
// de 30 segundos de uma hospedagem compartilhada é curto para uma carteira grande.
ignore_user_abort(true);
if (function_exists('set_time_limit')) {
    @set_time_limit(300);
}

$db = getDbConnection();
$today = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$summary = billingRunCycle($db, $today);
billingRecordRun($db, $summary);

$generated = $summary['generated'];
$reminders = $summary['reminders'];
$errors = $summary['errors'];

if ($errors) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'generated' => $generated, 'reminders' => $reminders, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
} else {
    echo sprintf("Cron rodou com sucesso. Faturas geradas: %d. Lembretes enviados: %d.\n", $generated, $reminders);
}
