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
$login = trim($body['login'] ?? '');
$email = trim($body['email'] ?? '');
$role = $body['role'] ?? '';
$groupId = isset($body['group_id']) && $body['group_id'] !== '' ? (int)$body['group_id'] : null;

if (!$targetUserId || !$login || !$email) {
    http_response_code(400);
    echo json_encode(['error' => 'ID, login e email são obrigatórios.']);
    exit;
}

$db = getDbConnection();

// Busca o usuário alvo
$stmt = $db->prepare("SELECT id, login, email, role, group_id FROM users WHERE id = ? LIMIT 1");
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

// Validação de permissões
if ($operatorRole === 'master') {
    if ($targetUser['role'] === 'root' || $targetUser['role'] === 'master') {
        http_response_code(403);
        echo json_encode(['error' => 'Permissão negada. Usuários Master só podem editar contas de Usuário Padrão.']);
        exit;
    }
    // Master não pode promover ninguém a master ou root
    $role = 'user';
} else {
    // Para root, se não passar role válido, mantém o atual
    if (!in_array($role, ['root', 'master', 'user'])) {
        $role = $targetUser['role'];
    }
}

// Se o papel for 'user', o grupo é obrigatório
if ($role === 'user' && !$groupId) {
    http_response_code(400);
    echo json_encode(['error' => 'Usuários com perfil padrão devem ser associados obrigatoriamente a um grupo.']);
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

// Verifica unicidade de login e email
$checkUnique = $db->prepare("SELECT id, login, email FROM users WHERE (login = ? OR email = ?) AND id != ? LIMIT 1");
$checkUnique->execute([$login, $email, $targetUserId]);
$duplicate = $checkUnique->fetch();

if ($duplicate) {
    http_response_code(400);
    if ($duplicate['login'] === $login) {
        echo json_encode(['error' => 'Este login já está em uso por outro usuário.']);
    } else {
        echo json_encode(['error' => 'Este e-mail já está em uso por outro usuário.']);
    }
    exit;
}

$newPassword = trim($body['new_password'] ?? '');
$generateNewPassword = !empty($body['generate_password']);
$generatedPassword = null;

try {
    if ($generateNewPassword) {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$';
        $generatedPassword = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < 10; $i++) {
            $generatedPassword .= $chars[random_int(0, $max)];
        }
        $hash = password_hash($generatedPassword, PASSWORD_DEFAULT);
        $updateStmt = $db->prepare("
            UPDATE users 
            SET login = ?, email = ?, role = ?, group_id = ?, password_hash = ?, force_password_change = 1 
            WHERE id = ?
        ");
        $updateStmt->execute([$login, $email, $role, $groupId, $hash, $targetUserId]);
    } elseif ($newPassword) {
        if (strlen($newPassword) < 6) {
            http_response_code(400);
            echo json_encode(['error' => 'A nova senha deve ter pelo menos 6 caracteres.']);
            exit;
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $db->prepare("
            UPDATE users 
            SET login = ?, email = ?, role = ?, group_id = ?, password_hash = ?, force_password_change = 1 
            WHERE id = ?
        ");
        $updateStmt->execute([$login, $email, $role, $groupId, $hash, $targetUserId]);
    } else {
        $updateStmt = $db->prepare("
            UPDATE users 
            SET login = ?, email = ?, role = ?, group_id = ? 
            WHERE id = ?
        ");
        $updateStmt->execute([$login, $email, $role, $groupId, $targetUserId]);
    }

    $groupDesc = $groupName ? " no grupo '{$groupName}'" : " (sem grupo)";
    $pwdDesc = $generateNewPassword ? " com nova senha temporária gerada" : ($newPassword ? " com senha redefinida" : "");
    logActivity(
        $db,
        $targetUserId,
        'edit_user',
        "Dados do usuário '{$login}' ({$role}) atualizados por {$operatorLogin} ({$operatorRole}){$groupDesc}{$pwdDesc}",
        $operatorId,
        $operatorLogin,
        $login
    );

    echo json_encode([
        'ok' => true,
        'user' => [
            'id' => $targetUserId,
            'login' => $login,
            'email' => $email,
            'role' => $role,
            'group_id' => $groupId,
            'group_name' => $groupName
        ],
        'generated_password' => $generatedPassword,
        'message' => 'Usuário atualizado com sucesso.'
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao salvar alterações no usuário.']);
}
