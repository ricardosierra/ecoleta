<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';

startSecureSession();
apiRequireCsrfToken();

apiSendJsonHeaders();

// Papel exigido em um lugar só: public/api/authz.php. Recusa com 403 e encerra.
$operator = apiRequireAdmin();
$operatorId = $operator['id'];
$operatorRole = $operator['role'];
$operatorLogin = $operator['login'];

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

// root exclui qualquer conta menos a própria; master, apenas contas 'user'.
if (!apiRoleCanDeleteUser($operatorRole, $operatorId, (string) $targetUser['role'], $targetUserId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Permissão negada. Usuários Master só podem excluir usuários comuns.']);
    exit;
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
