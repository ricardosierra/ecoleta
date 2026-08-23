<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';

startSecureSession();
apiRequireCsrfToken();

apiSendJsonHeaders();

$actor = apiRequireAuthenticated();

$userId = (int)($_GET['user_id'] ?? 0);

// Somente root/master podem ver logs de outros; usuário comum só vê o próprio.
if (!apiRoleCanViewUserLogs($actor['role'], $actor['id'], $userId)) {
    http_response_code(403);
    echo json_encode(['error' => API_ACCESS_DENIED]);
    exit;
}

if (!$userId) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do usuário não fornecido.']);
    exit;
}

$db = getDbConnection();

// Busca dados do usuário
$userStmt = $db->prepare("
    SELECT u.id, u.login, u.email, u.role, u.group_id, g.name AS group_name, u.force_password_change, u.created_at 
    FROM users u 
    LEFT JOIN `groups` g ON u.group_id = g.id 
    WHERE u.id = ?
");
$userStmt->execute([$userId]);
$user = $userStmt->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'Usuário não encontrado.']);
    exit;
}

// Busca histórico completo de atividades
$stmt = $db->prepare("
    SELECT id, user_id, target_login, action, description, performed_by_id, performed_by_login, ip_address, user_agent, created_at 
    FROM activity_logs 
    WHERE user_id = ? OR target_login = ? 
    ORDER BY created_at DESC, id DESC 
    LIMIT 100
");
$stmt->execute([$userId, $user['login']]);
$logs = $stmt->fetchAll();

// Se não houver logs de atividade (ex: registros antigos), busca na tabela de access_logs para retrocompatibilidade
if (empty($logs)) {
    $legacyStmt = $db->prepare("
        SELECT id, user_id, ? AS target_login, 'login' AS action, 'Login no sistema' AS description, 
               user_id AS performed_by_id, ? AS performed_by_login, ip_address, user_agent, logged_at AS created_at 
        FROM access_logs 
        WHERE user_id = ? 
        ORDER BY logged_at DESC 
        LIMIT 100
    ");
    $legacyStmt->execute([$user['login'], $user['login'], $userId]);
    $logs = $legacyStmt->fetchAll();
}

echo json_encode([
    'ok' => true,
    'user' => $user,
    'logs' => $logs
]);
