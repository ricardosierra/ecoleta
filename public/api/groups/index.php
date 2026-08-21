<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['root', 'master'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado. Apenas administradores e masters podem gerenciar grupos.']);
    exit;
}

$db = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("
        SELECT g.id, g.name, g.powerbi_url, g.created_at, g.updated_at,
               COUNT(u.id) AS users_count
        FROM `groups` g
        LEFT JOIN users u ON u.group_id = g.id
        GROUP BY g.id, g.name, g.powerbi_url, g.created_at, g.updated_at
        ORDER BY g.name ASC
    ");
    echo json_encode(['ok' => true, 'groups' => $stmt->fetchAll()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    
    $name = trim($body['name'] ?? '');
    $powerbiUrl = trim($body['powerbi_url'] ?? '');
    
    if (!$name) {
        http_response_code(400);
        echo json_encode(['error' => 'O nome do grupo é obrigatório.']);
        exit;
    }
    
    // Se o usuário colou uma tag <iframe> completa, extrai apenas o atributo src="..."
    if (preg_match('/<iframe.*?src=["\']([^"\']+)["\']/i', $powerbiUrl, $matches)) {
        $powerbiUrl = $matches[1];
    }
    
    try {
        $stmt = $db->prepare("INSERT INTO `groups` (name, powerbi_url) VALUES (?, ?)");
        $stmt->execute([$name, $powerbiUrl ?: null]);
        $id = (int)$db->lastInsertId();
        
        $operatorLogin = $_SESSION['login'] ?? 'admin';
        $operatorRole = $_SESSION['role'];
        $operatorId = (int)$_SESSION['user_id'];

        logActivity(
            $db,
            null,
            'create_group',
            "Grupo '{$name}' criado por {$operatorLogin} ({$operatorRole})",
            $operatorId,
            $operatorLogin,
            $name
        );

        echo json_encode([
            'ok' => true,
            'group' => [
                'id' => $id,
                'name' => $name,
                'powerbi_url' => $powerbiUrl,
                'users_count' => 0
            ],
            'message' => 'Grupo criado com sucesso.'
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            http_response_code(400);
            echo json_encode(['error' => 'Já existe um grupo com este nome.']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao criar grupo.']);
        }
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido.']);
