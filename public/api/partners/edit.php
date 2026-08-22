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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido.']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

$id = isset($body['id']) ? (int)$body['id'] : 0;
$name = trim($body['name'] ?? '');
$src = trim($body['src'] ?? '');
$isActive = isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1;
$orderIndex = isset($body['order_index']) ? (int)$body['order_index'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do parceiro inválido.']);
    exit;
}

if (!$name) {
    http_response_code(400);
    echo json_encode(['error' => 'O nome da empresa parceira é obrigatório.']);
    exit;
}

if (!$src) {
    http_response_code(400);
    echo json_encode(['error' => 'O caminho da logo (ou URL) é obrigatório.']);
    exit;
}

$db = getDbConnection();

try {
    $stmt = $db->prepare("
        UPDATE partners 
        SET name = ?, src = ?, order_index = ?, is_active = ?
        WHERE id = ?
    ");
    $stmt->execute([$name, $src, $orderIndex, $isActive, $id]);

    $operatorLogin = $_SESSION['login'] ?? 'admin';
    $operatorRole = $_SESSION['role'];
    $operatorId = (int)$_SESSION['user_id'];

    logActivity(
        $db,
        null,
        'edit_partner',
        "Parceiro '{$name}' (ID {$id}) atualizado por {$operatorLogin} ({$operatorRole})",
        $operatorId,
        $operatorLogin,
        $name
    );

    echo json_encode([
        'ok' => true,
        'partner' => [
            'id' => $id,
            'name' => $name,
            'src' => $src,
            'order_index' => $orderIndex,
            'is_active' => $isActive,
        ],
        'message' => 'Parceiro atualizado com sucesso.'
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao atualizar parceiro no banco de dados.']);
}
