<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();
$operator = apiRequireAuthenticated();

$db = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("
        SELECT o.*, c.name as client_name
        FROM service_orders o
        JOIN clients c ON o.client_id = c.id
        ORDER BY o.id DESC
    ");
    echo json_encode(['ok' => true, 'service_orders' => $stmt->fetchAll()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    
    $clientId = (int)($body['client_id'] ?? 0);
    $weight = trim($body['weight'] ?? '');
    $collectionDate = trim($body['collection_date'] ?? '');
    $bagsCount = isset($body['bags_count']) && $body['bags_count'] !== '' ? (int)$body['bags_count'] : null;
    $containersCount = isset($body['containers_count']) && $body['containers_count'] !== '' ? (int)$body['containers_count'] : null;
    $responsible = trim($body['responsible'] ?? '');
    
    if (!$clientId) {
        http_response_code(400);
        echo json_encode(['error' => 'Cliente é obrigatório.']);
        exit;
    }
    
    $colDate = $collectionDate ? $collectionDate : null;
    
    try {
        $stmt = $db->prepare("INSERT INTO service_orders (client_id, weight, collection_date, bags_count, containers_count, responsible) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$clientId, $weight, $colDate, $bagsCount, $containersCount, $responsible]);
        
        $id = (int)$db->lastInsertId();
        
        echo json_encode(['ok' => true, 'id' => $id]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao criar OS.']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido.']);
