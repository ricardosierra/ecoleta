<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();

$db = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("SELECT id, name, logo_url, is_active FROM site_clients ORDER BY name ASC");
    $companies = $stmt->fetchAll();
    echo json_encode(['ok' => true, 'companies' => $companies]);
    exit;
}

$operator = apiRequireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    
    $action = $body['action'] ?? '';
    
    if ($action === 'create') {
        $name = trim($body['name'] ?? '');
        $logo_url = trim($body['logo_url'] ?? '');
        
        if (!$name || !$logo_url) {
            http_response_code(400);
            echo json_encode(['error' => 'Nome e logo são obrigatórios.']);
            exit;
        }
        
        $stmt = $db->prepare("INSERT INTO site_clients (name, logo_url, is_active) VALUES (?, ?, 1)");
        $stmt->execute([$name, $logo_url]);
        
        echo json_encode(['ok' => true, 'id' => $db->lastInsertId()]);
        exit;
    }
    
    if ($action === 'toggle_active') {
        $id = (int)($body['id'] ?? 0);
        $is_active = (int)($body['is_active'] ?? 1);
        
        $stmt = $db->prepare("UPDATE site_clients SET is_active = ? WHERE id = ?");
        $stmt->execute([$is_active, $id]);
        
        echo json_encode(['ok' => true]);
        exit;
    }
    
    if ($action === 'delete') {
        $id = (int)($body['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM site_clients WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['ok' => true]);
        exit;
    }
    
    http_response_code(400);
    echo json_encode(['error' => 'Ação inválida.']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido.']);
