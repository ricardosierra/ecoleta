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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

// root gera senha para qualquer conta; master, apenas para contas 'user'.
if (!apiRoleCanGeneratePassword($operatorRole, (string) $targetUser['role'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Permissão negada. Usuários Master só podem gerar senhas para usuários comuns.']);
    exit;
}

// Gera senha temporária de 10 caracteres
$chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$';
$generatedPassword = '';
$max = strlen($chars) - 1;
for ($i = 0; $i < 10; $i++) {
    $generatedPassword .= $chars[random_int(0, $max)];
}

$passwordHash = password_hash($generatedPassword, PASSWORD_DEFAULT);

$updateStmt = $db->prepare("UPDATE users SET password_hash = ?, force_password_change = 1 WHERE id = ?");
$updateStmt->execute([$passwordHash, $targetUserId]);

// Grava no log de auditoria
logActivity(
    $db,
    $targetUserId,
    'reset_password',
    "Nova senha temporária gerada por {$operatorLogin} ({$operatorRole})",
    $operatorId,
    $operatorLogin,
    $targetUser['login']
);

echo json_encode([
    'ok' => true,
    'user_id' => $targetUserId,
    'login' => $targetUser['login'],
    'generated_password' => $generatedPassword,
    'message' => 'Senha gerada com sucesso. O usuário precisará trocá-la no próximo acesso.'
]);
