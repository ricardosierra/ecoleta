<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$userId = (int)($_GET['user_id'] ?? 0);

// Somente root/master podem ver logs de outros, usuário comum só vê o próprio
if ($_SESSION['role'] !== 'root' && $_SESSION['role'] !== 'master') {
    if ($userId !== (int)$_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso negado.']);
        exit;
    }
}

if (!$userId) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do usuário não fornecido.']);
    exit;
}

$db = getDbConnection();

// Primeiro verifica se o usuário existe para pegar os dados
$userStmt = $db->prepare("SELECT id, login, email, role, created_at FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$user = $userStmt->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'Usuário não encontrado.']);
    exit;
}

$stmt = $db->prepare("SELECT id, ip_address, user_agent, logged_at FROM access_logs WHERE user_id = ? ORDER BY logged_at DESC LIMIT 100");
$stmt->execute([$userId]);

echo json_encode(['ok' => true, 'user' => $user, 'logs' => $stmt->fetchAll()]);
