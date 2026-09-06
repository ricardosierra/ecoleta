<?php
declare(strict_types=1);

/**
 * Faturamento mensal automático.
 *
 * Roda por cron (HTTP ou CLI) e faz duas coisas, cada uma no seu dia:
 *
 *   dia 30 (ou o último do mês, quando o mês não chega ao 30)
 *       gera a cobrança do mês seguinte no Asaas para cada cliente ativo com
 *       valor mensal positivo, grava em `invoices` e manda o e-mail com o Pix e
 *       o link do boleto.
 *
 *   dias 3 e 7
 *       relembra o que continua `PENDING` com vencimento dentro do mês.
 *
 * A decisão de quando cobrar e o documento que o cliente recebe moram em
 * `billing_lib.php`, onde a suíte consegue exercitá-los. Aqui fica só o efeito
 * colateral: segredo, banco, Asaas e e-mail.
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
$db = getDbConnection();
$today = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$generated = 0;
$reminders = 0;
$errors = [];

if (billingShouldIssue($today)) {
    $clients = $db->query("SELECT * FROM clients WHERE monthly_value > 0 AND status = 'active'")->fetchAll();
    foreach ($clients as $client) {
        try {
            $invoice = billingIssueInvoice($db, $client, (float) $client['monthly_value'], billingDueDate($today, (int) $client['due_day']));
            if ($invoice['created']) $generated++;
            $delivery = billingDeliverInvoice($db, $invoice, $client);
            if ($delivery['errors']) $errors[] = ['client_id' => $client['id'], 'delivery' => $delivery];
        } catch (Throwable $e) {
            $errors[] = ['client_id' => $client['id'], 'error' => $e->getMessage()];
        }
    }
}

if (billingShouldRemind($today)) {
    $stmt = $db->prepare("SELECT i.*, c.name, c.email, c.whatsapp FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.status IN ('PENDING', 'OVERDUE') AND c.status = 'active' AND i.due_date BETWEEN ? AND ?");
    $stmt->execute([$today->format('Y-m-01'), $today->format('Y-m-t')]);
    foreach ($stmt->fetchAll() as $invoice) {
        $client = ['id' => $invoice['client_id'], 'name' => $invoice['name'], 'email' => $invoice['email'], 'whatsapp' => $invoice['whatsapp']];
        $delivery = billingDeliverInvoice($db, $invoice, $client, 'reminder:' . $today->format('Y-m-d'));
        if ($delivery['email'] === 'sent' || $delivery['whatsapp'] === 'accepted') $reminders++;
        if ($delivery['errors']) $errors[] = ['invoice_id' => $invoice['id'], 'delivery' => $delivery];
    }
}

if ($errors) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'generated' => $generated, 'reminders' => $reminders, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
} else {
    echo sprintf("Cron rodou com sucesso. Faturas geradas: %d. Lembretes enviados: %d.\n", $generated, $reminders);
}
