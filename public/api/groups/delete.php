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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido.']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];
$groupId = (int)($body['group_id'] ?? 0);

if (!$groupId) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do grupo é obrigatório.']);
    exit;
}

$db = getDbConnection();

// Busca o grupo existente
$stmt = $db->prepare("SELECT id, name FROM `groups` WHERE id = ? LIMIT 1");
$stmt->execute([$groupId]);
$group = $stmt->fetch();

if (!$group) {
    http_response_code(404);
    echo json_encode(['error' => 'Grupo não encontrado.']);
    exit;
}

// Verifica se existem usuários associados ao grupo
$checkUsers = $db->prepare("SELECT COUNT(*) AS total FROM users WHERE group_id = ?");
$checkUsers->execute([$groupId]);
$userCount = (int)($checkUsers->fetch()['total'] ?? 0);

if ($userCount > 0) {
    http_response_code(400);
    echo json_encode([
        'error' => "Não é possível excluir o grupo \"{$group['name']}\" pois existem {$userCount} usuário(s) associado(s) a ele. Altere o grupo desses usuários antes de excluir."
    ]);
    exit;
}

$operatorLogin = $_SESSION['login'] ?? 'admin';
$operatorRole = $_SESSION['role'];
$operatorId = (int)$_SESSION['user_id'];

try {
    $deleteStmt = $db->prepare("DELETE FROM `groups` WHERE id = ?");
    $deleteStmt->execute([$groupId]);

    logActivity(
        $db,
        null,
        'delete_group',
        "Grupo '{$group['name']}' (ID {$groupId}) excluído por {$operatorLogin} ({$operatorRole})",
        $operatorId,
        $operatorLogin,
        $group['name']
    );

    echo json_encode([
        'ok' => true,
        'message' => "Grupo \"{$group['name']}\" excluído com sucesso."
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao excluir grupo.']);
}
