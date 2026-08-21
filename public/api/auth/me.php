<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado.']);
    exit;
}

$db = getDbConnection();
$stmt = $db->prepare("
    SELECT u.id, u.login, u.email, u.role, u.group_id, u.force_password_change,
           g.name AS group_name, g.powerbi_url AS group_powerbi_url
    FROM users u
    LEFT JOIN `groups` g ON u.group_id = g.id
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não encontrado.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'user' => [
        'id' => (int)$user['id'],
        'login' => $user['login'],
        'email' => $user['email'],
        'role' => $user['role'],
        'group_id' => $user['group_id'] ? (int)$user['group_id'] : null,
        'group_name' => $user['group_name'] ?? null,
        'group_powerbi_url' => $user['group_powerbi_url'] ?? null,
        'force_password_change' => (bool)$user['force_password_change']
    ]
]);
