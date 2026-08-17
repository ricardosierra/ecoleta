<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['root', 'master'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado.']);
    exit;
}

$db = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("SELECT id, login, email, role, force_password_change, created_at FROM users ORDER BY id DESC");
    echo json_encode(['ok' => true, 'users' => $stmt->fetchAll()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    
    $login = trim($body['login'] ?? '');
    $email = trim($body['email'] ?? '');
    $role = $body['role'] ?? 'user';
    
    if (!$login || !$email) {
        http_response_code(400);
        echo json_encode(['error' => 'Login e email são obrigatórios.']);
        exit;
    }
    
    if (!in_array($role, ['master', 'user'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Nível de acesso inválido.']);
        exit;
    }
    
    // Gerar senha aleatória (ex: 8 caracteres)
    $generatedPassword = bin2hex(random_bytes(4));
    $hash = password_hash($generatedPassword, PASSWORD_DEFAULT);
    
    try {
        $stmt = $db->prepare("INSERT INTO users (login, email, password_hash, role, force_password_change) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([$login, $email, $hash, $role]);
        $id = $db->lastInsertId();
        
        echo json_encode([
            'ok' => true, 
            'user' => ['id' => $id, 'login' => $login, 'email' => $email, 'role' => $role],
            'generated_password' => $generatedPassword
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            http_response_code(400);
            echo json_encode(['error' => 'Login ou email já existe.']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao criar usuário.']);
        }
    }
    exit;
}

http_response_code(405);
