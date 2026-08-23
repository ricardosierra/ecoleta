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

$groupId = (int)($body['group_id'] ?? 0);
$name = trim($body['name'] ?? '');
$powerbiUrl = trim($body['powerbi_url'] ?? '');

if (!$groupId || !$name) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do grupo e nome são obrigatórios.']);
    exit;
}

// Se o usuário colou uma tag <iframe> completa, extrai apenas o atributo src="..."
if (preg_match('/<iframe.*?src=["\']([^"\']+)["\']/i', $powerbiUrl, $matches)) {
    $powerbiUrl = $matches[1];
}

$db = getDbConnection();

// Busca o grupo existente
$stmt = $db->prepare("SELECT id, name, powerbi_url FROM `groups` WHERE id = ? LIMIT 1");
$stmt->execute([$groupId]);
$existingGroup = $stmt->fetch();

if (!$existingGroup) {
    http_response_code(404);
    echo json_encode(['error' => 'Grupo não encontrado.']);
    exit;
}

// Verifica se já existe outro grupo com esse nome
$checkDup = $db->prepare("SELECT id FROM `groups` WHERE name = ? AND id != ? LIMIT 1");
$checkDup->execute([$name, $groupId]);
if ($checkDup->fetch()) {
    http_response_code(400);
    echo json_encode(['error' => 'Já existe outro grupo com este nome.']);
    exit;
}

try {
    $updateStmt = $db->prepare("UPDATE `groups` SET name = ?, powerbi_url = ? WHERE id = ?");
    $updateStmt->execute([$name, $powerbiUrl ?: null, $groupId]);

    logActivity(
        $db,
        null,
        'edit_group',
        "Grupo '{$name}' (ID {$groupId}) atualizado por {$operatorLogin} ({$operatorRole})",
        $operatorId,
        $operatorLogin,
        $name
    );

    echo json_encode([
        'ok' => true,
        'group' => [
            'id' => $groupId,
            'name' => $name,
            'powerbi_url' => $powerbiUrl
        ],
        'message' => 'Grupo atualizado com sucesso.'
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao salvar alterações no grupo.']);
}
