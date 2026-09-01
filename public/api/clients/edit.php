<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();
$operator = apiRequireUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido.']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

$clientId = (int)($body['client_id'] ?? 0);
if (!$clientId) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do cliente é obrigatório.']);
    exit;
}

$db = getDbConnection();

$stmt = $db->prepare("SELECT id, name, email, whatsapp, document, monthly_value, due_day, status, asaas_customer_id FROM clients WHERE id = ? LIMIT 1");
$stmt->execute([$clientId]);
$client = $stmt->fetch();

if (!$client) {
    http_response_code(404);
    echo json_encode(['error' => 'Cliente não encontrado.']);
    exit;
}

// Campos de cobrança: só atualiza o que veio no corpo, o resto fica como está.
$monthlyValue = array_key_exists('monthly_value', $body) ? (float)$body['monthly_value'] : (float)$client['monthly_value'];
$dueDay = array_key_exists('due_day', $body) ? (int)$body['due_day'] : (int)$client['due_day'];
$status = array_key_exists('status', $body) ? $body['status'] : $client['status'];

if ($monthlyValue < 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Valor mensal não pode ser negativo.']);
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

try {
    $update = $db->prepare("UPDATE clients SET monthly_value = ?, due_day = ?, status = ? WHERE id = ?");
    $update->execute([$monthlyValue, $dueDay, $status, $clientId]);

    echo json_encode([
        'ok' => true,
        'client' => [
            'id' => (int)$client['id'],
            'name' => $client['name'],
            'email' => $client['email'],
            'whatsapp' => $client['whatsapp'],
            'document' => $client['document'],
            'monthly_value' => $monthlyValue,
            'due_day' => $dueDay,
            'status' => $status,
            'asaas_customer_id' => $client['asaas_customer_id']
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao atualizar cliente no banco.']);
}
