<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

startSecureSession();
apiRequireCsrfToken();

apiSendJsonHeaders();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['root', 'master'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado.']);
    exit;
}

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

$operatorRole = $_SESSION['role'];
$operatorId = (int)$_SESSION['user_id'];
$operatorLogin = $_SESSION['login'] ?? 'admin';

// Regras de permissão:
// root: pode para todos
// master: apenas para quem não for root nem master (ou seja, apenas 'user')
if ($operatorRole === 'master') {
    if ($targetUser['role'] === 'root' || $targetUser['role'] === 'master') {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada. Usuários Master só podem gerar senhas para usuários comuns.']);
        exit;
    }
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
