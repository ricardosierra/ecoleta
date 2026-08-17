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
$stmt = $db->prepare("SELECT id, login, email, role, force_password_change FROM users WHERE id = ?");
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
        'id' => $user['id'],
        'login' => $user['login'],
        'email' => $user['email'],
        'role' => $user['role'],
        'force_password_change' => (bool)$user['force_password_change']
    ]
]);
