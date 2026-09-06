<?php
declare(strict_types=1);

/**
 * Listagem e cadastro de clientes.
 *
 * Exige administrador. A tela (`app/dashboard/clientes/page.tsx`) sempre desenhou
 * "Acesso negado." para quem não é `root`/`master`, mas o backend aceitava
 * qualquer sessão — e uma conta `user`, que no dashboard só enxerga o próprio
 * painel, podia ler a carteira inteira (nome, e-mail, WhatsApp, CPF/CNPJ e valor
 * mensal de cada cliente) e ainda cadastrar cliente novo, com efeito no Asaas.
 * Mesma régua do módulo de OS.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';
require_once __DIR__ . '/../asaas_lib.php';
require_once __DIR__ . '/phone_lib.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();
$operator = apiRequireAdmin();

$db = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("SELECT id, name, email, whatsapp, document, monthly_value, due_day, status, asaas_customer_id, created_at FROM clients ORDER BY id DESC");
    echo json_encode(['ok' => true, 'clients' => $stmt->fetchAll()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    
    $name = trim($body['name'] ?? '');
    $email = trim($body['email'] ?? '');
    $whatsapp = normalizePhone($body['whatsapp'] ?? '');
    $document = preg_replace('/\D+/', '', (string) ($body['document'] ?? '')) ?? '';
    $monthlyValue = (float)($body['monthly_value'] ?? 0);
    $dueDay = (int)($body['due_day'] ?? 10);
    $status = $body['status'] ?? 'active';

    if (!$name) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome é obrigatório.']);
        exit;
    }

    if ($dueDay < 1 || $dueDay > 31) {
        http_response_code(400);
        echo json_encode(['error' => 'Dia de vencimento deve estar entre 1 e 31.']);
        exit;
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Status inválido. Use "active" ou "inactive".']);
        exit;
    }

    // Cliente com valor mensal precisa de documento, senão a cobrança do dia 30
    // falha em silêncio.
    //
    // O Asaas ACEITA cadastrar um cliente sem CPF/CNPJ — o que ele recusa é a
    // cobrança: "Para criar esta cobrança é necessário preencher o CPF ou CNPJ do
    // cliente". Como o cron trata cada cliente dentro de um try/catch para não
    // derrubar os outros, o erro ia só para o error_log e o cliente
    // simplesmente nunca era faturado, mês após mês, sem ninguém perceber.
    if ($monthlyValue > 0 && $document === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Cliente com cobrança mensal precisa de CPF ou CNPJ — o Asaas recusa gerar a fatura sem ele.']);
        exit;
    }

    if (!is_finite($monthlyValue) || $monthlyValue < 0) {
        apiJsonResponse(400, ['error' => 'Valor mensal não pode ser negativo ou inválido.']);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiJsonResponse(400, ['error' => 'E-mail inválido.']);
    }
    if ($whatsapp !== '' && !preg_match('/^55[1-9][0-9]{9,10}$/', $whatsapp)) {
        apiJsonResponse(400, ['error' => 'WhatsApp inválido. Informe DDD e telefone.']);
    }
    // NULL permits multiple customers without a document; an empty string is unique.
    if ($document !== '') {
        $duplicate = $db->prepare('SELECT id FROM clients WHERE document = ? LIMIT 1');
        $duplicate->execute([$document]);
        if ($duplicate->fetch()) apiJsonResponse(409, ['error' => 'Cliente com este documento já existe.']);
    }

    // Create customer in Asaas
    $asaasCustomerId = null;
    try {
        $asaasCustomerId = asaasCreateCustomer($name, $email, $document, $whatsapp);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao registrar no Asaas: ' . $e->getMessage()]);
        exit;
    }

    try {
        $stmt = $db->prepare("INSERT INTO clients (name, email, whatsapp, document, monthly_value, due_day, status, asaas_customer_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $email, $whatsapp, $document !== '' ? $document : null, $monthlyValue, $dueDay, $status, $asaasCustomerId]);
        $id = (int)$db->lastInsertId();

        echo json_encode([
            'ok' => true,
            'client' => [
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'whatsapp' => $whatsapp,
                'document' => $document,
                'monthly_value' => $monthlyValue,
                'due_day' => $dueDay,
                'status' => $status,
                'asaas_customer_id' => $asaasCustomerId
            ]
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            http_response_code(400);
            echo json_encode(['error' => 'Cliente com este documento já existe.']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao criar cliente no banco.']);
        }
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido.']);
