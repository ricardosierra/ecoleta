<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

$db = getDbConnection();

// GET: Consulta pública ou administrativa
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $isPublic = isset($_GET['public']) && $_GET['public'] === '1';

    if ($isPublic) {
        $stmt = $db->query("
            SELECT id, name, src, order_index
            FROM partners
            WHERE is_active = 1
            ORDER BY order_index ASC, id ASC
        ");
        echo json_encode([
            'ok' => true,
            'partners' => $stmt->fetchAll()
        ]);
        exit;
    }

    // Acesso administrativo completo
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['root', 'master'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso negado.']);
        exit;
    }

    $stmt = $db->query("
        SELECT id, name, src, order_index, is_active, created_at, updated_at
        FROM partners
        ORDER BY order_index ASC, id ASC
    ");
    echo json_encode([
        'ok' => true,
        'partners' => $stmt->fetchAll()
    ]);
    exit;
}

// POST: Criar novo parceiro
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['root', 'master'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso negado.']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];

    $name = trim($body['name'] ?? '');
    $src = trim($body['src'] ?? '');
    $isActive = isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1;
    $orderIndex = isset($body['order_index']) ? (int)$body['order_index'] : 0;

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

    try {
        $stmt = $db->prepare("
            INSERT INTO partners (name, src, order_index, is_active)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$name, $src, $orderIndex, $isActive]);
        $newId = (int)$db->lastInsertId();

        $operatorLogin = $_SESSION['login'] ?? 'admin';
        $operatorRole = $_SESSION['role'];
        $operatorId = (int)$_SESSION['user_id'];

        logActivity(
            $db,
            null,
            'create_partner',
            "Parceiro '{$name}' cadastrado por {$operatorLogin} ({$operatorRole})",
            $operatorId,
            $operatorLogin,
            $name
        );

        echo json_encode([
            'ok' => true,
            'partner' => [
                'id' => $newId,
                'name' => $name,
                'src' => $src,
                'order_index' => $orderIndex,
                'is_active' => $isActive,
            ],
            'message' => 'Parceiro cadastrado com sucesso.'
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao salvar parceiro no banco de dados.']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido.']);
