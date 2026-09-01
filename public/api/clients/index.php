<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';
require_once __DIR__ . '/../asaas_lib.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();
$operator = apiRequireUser(); // Only requires user or higher

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
    $whatsapp = trim($body['whatsapp'] ?? '');
    $document = trim($body['document'] ?? '');
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
        $stmt->execute([$name, $email, $whatsapp, $document, $monthlyValue, $dueDay, $status, $asaasCustomerId]);
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
