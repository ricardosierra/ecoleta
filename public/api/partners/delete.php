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

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do parceiro inválido.']);
    exit;
}

$db = getDbConnection();

try {
    // Busca informações antes de deletar para o log
    $fetchStmt = $db->prepare("SELECT name FROM partners WHERE id = ?");
    $fetchStmt->execute([$id]);
    $partner = $fetchStmt->fetch();

    if (!$partner) {
        http_response_code(404);
        echo json_encode(['error' => 'Parceiro não encontrado.']);
        exit;
    }

    $name = $partner['name'];

    $deleteStmt = $db->prepare("DELETE FROM partners WHERE id = ?");
    $deleteStmt->execute([$id]);

    $operatorLogin = $_SESSION['login'] ?? 'admin';
    $operatorRole = $_SESSION['role'];
    $operatorId = (int)$_SESSION['user_id'];

    logActivity(
        $db,
        null,
        'delete_partner',
        "Parceiro '{$name}' (ID {$id}) excluído por {$operatorLogin} ({$operatorRole})",
        $operatorId,
        $operatorLogin,
        $name
    );

    echo json_encode([
        'ok' => true,
        'message' => "Parceiro '{$name}' excluído com sucesso."
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao excluir parceiro no banco de dados.']);
}
