<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido.']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$login = trim($body['username'] ?? '');
$password = $body['password'] ?? '';

if (!$login || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Usuário e senha são obrigatórios.']);
    exit;
}

$db = getDbConnection();

// Fetch user
$stmt = $db->prepare("SELECT * FROM users WHERE login = ? LIMIT 1");
$stmt->execute([$login]);
$user = $stmt->fetch();

$adminUsername = defined('NEXT_PUBLIC_DASHBOARD_USER') && NEXT_PUBLIC_DASHBOARD_USER ? NEXT_PUBLIC_DASHBOARD_USER : 'admin';

if (!$user) {
    // Se o login for o admin padrão e não existir, verifica a senha padrão
    if ($login === $adminUsername) {
        if ($password === NEXT_PUBLIC_DASHBOARD_PASSWORD) {
            // Cria o usuário admin
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insert = $db->prepare("INSERT INTO users (login, password_hash, role, force_password_change) VALUES (?, ?, 'root', 1)");
            $insert->execute([$adminUsername, $hash]);
            $userId = (int)$db->lastInsertId();
            
            // Re-fetch user
            $stmt->execute([$adminUsername]);
            $user = $stmt->fetch();
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Credenciais inválidas.']);
            exit;
        }
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Credenciais inválidas.']);
        exit;
    }
} else {
    // Verifica a senha
    if (!password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Credenciais inválidas.']);
        exit;
    }
}

// Inicia sessão
$_SESSION['user_id'] = $user['id'];
$_SESSION['login'] = $user['login'];
$_SESSION['role'] = $user['role'];

// Registra acesso
$ip = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
$logStmt = $db->prepare("INSERT INTO access_logs (user_id, ip_address, user_agent) VALUES (?, ?, ?)");
$logStmt->execute([$user['id'], $ip, substr($ua, 0, 255)]);

echo json_encode([
    'ok' => true,
    'user' => [
        'id' => $user['id'],
        'login' => $user['login'],
        'role' => $user['role'],
        'force_password_change' => (bool)$user['force_password_change']
    ]
]);
