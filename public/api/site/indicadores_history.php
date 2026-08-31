<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();

$operator = apiRequireAdmin();
$db = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("SELECT id, indicator_key, old_value, new_value, changed_by_login, created_at, ip_address FROM site_indicator_history ORDER BY id DESC LIMIT 100");
    $history = $stmt->fetchAll();
    echo json_encode(['ok' => true, 'history' => $history]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido.']);
