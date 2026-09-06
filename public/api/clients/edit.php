<?php
declare(strict_types=1);

/**
 * Edição de cliente: valor mensal, dia de vencimento, status e WhatsApp.
 *
 * Exige administrador pelo mesmo motivo de `clients/index.php`: são os campos
 * que decidem quanto e quando cada cliente é cobrado, e desativar um cliente o
 * tira da cobrança automática do mês.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';
require_once __DIR__ . '/../asaas_lib.php';
require_once __DIR__ . '/phone_lib.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();
$operator = apiRequireAdmin();

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

// Campos de cobrança e contato: só atualiza o que veio no corpo, o resto fica como está.
$monthlyValue = array_key_exists('monthly_value', $body) ? (float)$body['monthly_value'] : (float)$client['monthly_value'];
$dueDay = array_key_exists('due_day', $body) ? (int)$body['due_day'] : (int)$client['due_day'];
$status = array_key_exists('status', $body) ? $body['status'] : $client['status'];
$whatsapp = array_key_exists('whatsapp', $body) ? normalizePhone($body['whatsapp'] === null ? null : (string) $body['whatsapp']) : $client['whatsapp'];

$name = array_key_exists('name', $body) ? trim((string) $body['name']) : $client['name'];
$email = array_key_exists('email', $body) ? trim((string) $body['email']) : $client['email'];
$document = array_key_exists('document', $body) ? (preg_replace('/\D+/', '', (string) $body['document']) ?: null) : $client['document'];
if ($name === '') apiJsonResponse(400, ['error' => 'Nome é obrigatório.']);
if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) apiJsonResponse(400, ['error' => 'E-mail inválido.']);
if ($whatsapp && !preg_match('/^55[1-9][0-9]{9,10}$/', $whatsapp)) apiJsonResponse(400, ['error' => 'WhatsApp inválido. Informe DDD e telefone.']);
if ($document !== null && $document !== '') {
    $duplicate = $db->prepare('SELECT id FROM clients WHERE document = ? AND id <> ? LIMIT 1');
    $duplicate->execute([$document, $clientId]);
    if ($duplicate->fetch()) apiJsonResponse(409, ['error' => 'Cliente com este documento já existe.']);
}

if (!is_finite($monthlyValue) || $monthlyValue < 0) {
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

// Mesma regra do cadastro: sem CPF/CNPJ o Asaas recusa gerar a cobrança, e a
// recusa acontece só no dia 30, dentro do try/catch do cron, sem chegar a
// ninguém. Ligar a cobrança de um cliente sem documento é criar esse silêncio.
//
// A trava só olha para o que ESTA requisição está pedindo: quem só mexe no dia
// de vencimento ou no status de um cadastro antigo — que pode ter valor mensal
// sem documento — não fica travado por causa de um campo que nem enviou. Nesses
// casos quem avisa é o cron, que registra o cadastro incompleto no log.
if ((array_key_exists('monthly_value', $body) || array_key_exists('document', $body)) && $monthlyValue > 0
    && trim((string) $document) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Cliente com cobrança mensal precisa de CPF ou CNPJ — o Asaas recusa gerar a fatura sem ele.']);
    exit;
}

if (($whatsapp !== $client['whatsapp'] || $name !== $client['name'] || $email !== $client['email'] || $document !== $client['document']) && !empty($client['asaas_customer_id'])) {
    try {
        asaasUpdateCustomer($client['asaas_customer_id'], ['mobilePhone' => $whatsapp, 'name' => $name, 'email' => $email, 'cpfCnpj' => $document]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao atualizar no Asaas: ' . $e->getMessage()]);
        exit;
    }
}

try {
    $update = $db->prepare("UPDATE clients SET monthly_value = ?, due_day = ?, status = ?, whatsapp = ?, name = ?, email = ?, document = ? WHERE id = ?");
    $update->execute([$monthlyValue, $dueDay, $status, $whatsapp, $name, $email, $document, $clientId]);

    echo json_encode([
        'ok' => true,
        'client' => [
            'id' => (int)$client['id'],
            'name' => $name,
            'email' => $email,
            'whatsapp' => $whatsapp,
            'document' => $document,
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
