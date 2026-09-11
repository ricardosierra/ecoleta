<?php
declare(strict_types=1);

/**
 * Listagem de faturas — histórico financeiro de todos os clientes.
 *
 * Exige administrador, como a tela (`app/dashboard/faturas/page.tsx`) já fazia
 * no navegador. A resposta traz valor, vencimento, status e o Pix Copia e Cola
 * de cada cobrança: nada disso é assunto de uma conta `user`.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();
$operator = apiRequireAdmin();

$db = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("
        SELECT i.*, c.name as client_name, c.email as client_email
        FROM invoices i
        JOIN clients c ON i.client_id = c.id
        ORDER BY i.due_date DESC
    ");
    echo json_encode(['ok' => true, 'invoices' => $stmt->fetchAll()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../billing_delivery.php';
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) apiJsonResponse(400, ['error' => 'Dados inválidos.']);
    $action = $body['action'] ?? 'create';
    try {
        if ($action === 'cancel') {
            require_once __DIR__ . '/../asaas_lib.php';
            $stmt = $db->prepare('SELECT * FROM invoices WHERE id = ?');
            $stmt->execute([(int) ($body['id'] ?? 0)]);
            $invoice = $stmt->fetch();
            if (!$invoice) apiJsonResponse(404, ['error' => 'Fatura não encontrada.']);
            if ($invoice['status'] === 'DELETED') {
                apiJsonResponse(200, ['ok' => true, 'message' => 'Fatura já se encontra cancelada.']);
            }
            if (in_array($invoice['status'], ['RECEIVED', 'CONFIRMED'], true)) {
                apiJsonResponse(400, ['error' => 'Não é possível cancelar uma fatura já paga.']);
            }

            if (!empty($invoice['asaas_payment_id'])) {
                try {
                    asaasDeletePayment((string) $invoice['asaas_payment_id']);
                } catch (Throwable $e) {
                    $msg = $e->getMessage();
                    if (stripos($msg, 'não encontrada') === false && stripos($msg, 'removida') === false) {
                        throw $e;
                    }
                }
            }

            $update = $db->prepare("UPDATE invoices SET status = 'DELETED' WHERE id = ?");
            $update->execute([$invoice['id']]);

            try {
                $log = $db->prepare("INSERT INTO activity_logs (action, description, performed_by_login, ip_address, user_agent) VALUES ('invoice_cancelled', ?, ?, ?, ?)");
                $log->execute([
                    'Fatura #' . $invoice['id'] . ' (Asaas: ' . $invoice['asaas_payment_id'] . ') cancelada',
                    $operator['login'] ?? 'admin',
                    apiClientIp(),
                    'EcoletaDashboard/1.0'
                ]);
            } catch (Throwable $e) {
                // Log não bloqueia a resposta
            }

            apiJsonResponse(200, ['ok' => true, 'message' => 'Fatura cancelada com sucesso.']);
        }

        if ($action === 'send') {
            $stmt = $db->prepare('SELECT * FROM invoices WHERE id = ?');
            $stmt->execute([(int) ($body['id'] ?? 0)]);
            $invoice = $stmt->fetch();
            if (!$invoice) apiJsonResponse(404, ['error' => 'Fatura não encontrada.']);
            $clientId = $invoice['client_id'];
        } elseif ($action === 'create') {
            $clientId = (int) ($body['client_id'] ?? 0);
        } else {
            apiJsonResponse(400, ['error' => 'Ação inválida.']);
        }
        $stmt = $db->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();
        if (!$client) apiJsonResponse(404, ['error' => 'Cliente não encontrado.']);
        if ($action === 'create') {
            $dueDate = (string) ($body['due_date'] ?? '');
            if ($dueDate < (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d')) {
                apiJsonResponse(400, ['error' => 'O vencimento deve ser hoje ou uma data futura.']);
            }
            $invoice = billingIssueInvoice($db, $client, (float) ($body['value'] ?? $client['monthly_value']), $dueDate);
        }
        if ($client['status'] !== 'active') apiJsonResponse(400, ['error' => 'Cliente inativo.']);
        $delivery = billingDeliverInvoice($db, $invoice, $client);
        apiJsonResponse(200, ['ok' => true, 'invoice' => $invoice, 'delivery' => $delivery]);
    } catch (InvalidArgumentException $e) {
        apiJsonResponse(400, ['error' => $e->getMessage()]);
    } catch (Throwable $e) {
        apiJsonResponse(502, ['error' => $e->getMessage()]);
    }
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido.']);
