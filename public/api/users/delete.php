<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['root', 'master'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido.']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];
$targetUserId = (int)($body['user_id'] ?? 0);

if (!$targetUserId) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do usuário é obrigatório.']);
    exit;
}

$operatorId = (int)$_SESSION['user_id'];
$operatorRole = $_SESSION['role'];
$operatorLogin = $_SESSION['login'] ?? 'admin';

if ($targetUserId === $operatorId) {
    http_response_code(400);
    echo json_encode(['error' => 'Você não pode excluir a sua própria conta ativa.']);
    exit;
}

$db = getDbConnection();

// Busca usuário alvo
$stmt = $db->prepare("SELECT id, login, email, role FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$targetUserId]);
$targetUser = $stmt->fetch();

if (!$targetUser) {
    http_response_code(404);
    echo json_encode(['error' => 'Usuário não encontrado.']);
    exit;
}

// Regras de permissão:
// root: pode excluir todos (exceto a si próprio)
// master: pode excluir apenas quem não for root nem master (ou seja, apenas 'user')
if ($operatorRole === 'master') {
    if ($targetUser['role'] === 'root' || $targetUser['role'] === 'master') {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada. Usuários Master só podem excluir usuários comuns.']);
        exit;
    }
}

// Exclui o usuário
$deleteStmt = $db->prepare("DELETE FROM users WHERE id = ?");
$deleteStmt->execute([$targetUserId]);

// Grava no log de auditoria
logActivity(
    $db,
    $targetUserId,
    'delete_user',
    "Usuário '{$targetUser['login']}' ({$targetUser['role']}) excluído por {$operatorLogin} ({$operatorRole})",
    $operatorId,
    $operatorLogin,
    $targetUser['login']
);

echo json_encode([
    'ok' => true,
    'message' => 'Usuário excluído com sucesso.'
]);
