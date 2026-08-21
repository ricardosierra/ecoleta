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

$db = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("
        SELECT u.id, u.login, u.email, u.role, u.group_id, g.name AS group_name, u.force_password_change, u.created_at 
        FROM users u 
        LEFT JOIN `groups` g ON u.group_id = g.id 
        ORDER BY u.id DESC
    ");
    echo json_encode(['ok' => true, 'users' => $stmt->fetchAll()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    
    $login = trim($body['login'] ?? '');
    $email = trim($body['email'] ?? '');
    $role = $body['role'] ?? 'user';
    $groupId = isset($body['group_id']) && $body['group_id'] !== '' ? (int)$body['group_id'] : null;
    
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

    // Usuário comum deve obrigatoriamente ter um grupo
    if ($role === 'user' && !$groupId) {
        http_response_code(400);
        echo json_encode(['error' => 'Usuários com perfil padrão devem ser associados obrigatoriamente a um grupo.']);
        exit;
    }

    // Se o operador for master, só pode criar usuário comum
    if ($_SESSION['role'] === 'master' && $role !== 'user') {
        http_response_code(403);
        echo json_encode(['error' => 'Usuários Master só podem criar contas de Usuário Padrão.']);
        exit;
    }

    // Se fornecido grupo, valida se existe
    $groupName = null;
    if ($groupId) {
        $chkGroup = $db->prepare("SELECT id, name FROM `groups` WHERE id = ? LIMIT 1");
        $chkGroup->execute([$groupId]);
        $grp = $chkGroup->fetch();
        if (!$grp) {
            http_response_code(400);
            echo json_encode(['error' => 'Grupo selecionado não existe.']);
            exit;
        }
        $groupName = $grp['name'];
    }
    
    // Gerar senha aleatória legível (10 caracteres)
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$';
    $generatedPassword = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < 10; $i++) {
        $generatedPassword .= $chars[random_int(0, $max)];
    }
    
    $hash = password_hash($generatedPassword, PASSWORD_DEFAULT);
    
    try {
        $stmt = $db->prepare("INSERT INTO users (login, email, password_hash, role, group_id, force_password_change) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute([$login, $email, $hash, $role, $groupId]);
        $id = (int)$db->lastInsertId();
        
        $operatorLogin = $_SESSION['login'] ?? 'admin';
        $operatorRole = $_SESSION['role'];
        $operatorId = (int)$_SESSION['user_id'];

        $groupDesc = $groupName ? " no grupo '{$groupName}'" : "";
        logActivity(
            $db,
            $id,
            'create_user',
            "Usuário '{$login}' ({$role}){$groupDesc} cadastrado por {$operatorLogin} ({$operatorRole})",
            $operatorId,
            $operatorLogin,
            $login
        );

        echo json_encode([
            'ok' => true, 
            'user' => [
                'id' => $id, 
                'login' => $login, 
                'email' => $email, 
                'role' => $role,
                'group_id' => $groupId,
                'group_name' => $groupName
            ],
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
echo json_encode(['error' => 'Método não permitido.']);
