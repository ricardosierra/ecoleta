<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';
require_once __DIR__ . '/../security_alerts.php';
require_once __DIR__ . '/../auth/password_audit_lib.php';

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
$stmt = $db->prepare("SELECT id, login, email, role, group_id, password_locked FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$targetUserId]);
$targetUser = $stmt->fetch();

if (!$targetUser) {
    http_response_code(404);
    echo json_encode(['error' => 'Usuário não encontrado.']);
    exit;
}

// Validação de permissões
if (!apiRoleCanEditUser($operatorRole, $targetUser['role'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Permissão negada. Usuários Master só podem editar contas de Usuário Padrão.']);
    exit;
}

// Master nunca promove ninguém: a gravação sai sempre como 'user'. Root grava o
// papel pedido, ou mantém o atual quando o corpo manda algo que não é papel.
// Editando a própria conta, o papel atual é mantido: ver apiEffectiveRoleOnEdit().
$role = apiEffectiveRoleOnEdit($operatorRole, $role, (string) $targetUser['role'], $targetUserId === $operatorId);

// Se o papel for 'user', o grupo é obrigatório
if (apiRoleRequiresGroup($role) && !$groupId) {
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

$passwordLocked = (int) ($targetUser['password_locked'] ?? 0);
if ($operatorRole === API_ROLE_ROOT && isset($body['password_locked'])) {
    $passwordLocked = !empty($body['password_locked']) ? 1 : 0;
}

$newPassword = trim($body['new_password'] ?? '');
$generateNewPassword = !empty($body['generate_password']);
$generatedPassword = null;

// Se a conta estiver bloqueada e houver tentativa de alterar/gerar senha:
if (!empty($targetUser['password_locked']) && ($generateNewPassword || $newPassword)) {
    if ($operatorRole !== API_ROLE_ROOT || $passwordLocked === 1) {
        apiSendSecurityAlert(
            'Tentativa Bloqueada de Alterar Senha',
            "Tentativa de alterar senha do usuário '{$targetUser['login']}' com trava ativada",
            [
                'Usuário Alvo' => $targetUser['login'],
                'E-mail Alvo' => $targetUser['email'] ?? 'Não cadastrado',
                'Solicitante' => "{$operatorLogin} ({$operatorRole})",
                'Status' => 'BLOQUEADO (Conta com trava ativada)',
            ]
        );
        http_response_code(403);
        echo json_encode(['error' => 'A troca de senha deste usuário está bloqueada pelo administrador. Desbloqueie a conta antes de alterar a senha.']);
        exit;
    }
}

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
            SET login = ?, email = ?, role = ?, group_id = ?, password_hash = ?, force_password_change = 1, password_locked = ? 
            WHERE id = ?
        ");
        $updateStmt->execute([$login, $email, $role, $groupId, $hash, $passwordLocked, $targetUserId]);
        attributePasswordHashChange($db, (int) $targetUserId, $hash, 'reset_password', (int) $operatorId, (string) $operatorLogin);
    } elseif ($newPassword) {
        if (strlen($newPassword) < 6) {
            http_response_code(400);
            echo json_encode(['error' => 'A nova senha deve ter pelo menos 6 caracteres.']);
            exit;
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $db->prepare("
            UPDATE users 
            SET login = ?, email = ?, role = ?, group_id = ?, password_hash = ?, force_password_change = 1, password_locked = ? 
            WHERE id = ?
        ");
        $updateStmt->execute([$login, $email, $role, $groupId, $hash, $passwordLocked, $targetUserId]);
        attributePasswordHashChange($db, (int) $targetUserId, $hash, 'change_password', (int) $operatorId, (string) $operatorLogin);
    } else {
        $updateStmt = $db->prepare("
            UPDATE users 
            SET login = ?, email = ?, role = ?, group_id = ?, password_locked = ? 
            WHERE id = ?
        ");
        $updateStmt->execute([$login, $email, $role, $groupId, $passwordLocked, $targetUserId]);
    }

    $groupDesc = $groupName ? " no grupo '{$groupName}'" : " (sem grupo)";
    $pwdDesc = $generateNewPassword ? " com nova senha temporária gerada" : ($newPassword ? " com senha redefinida" : "");
    $lockDesc = ($passwordLocked !== (int)($targetUser['password_locked'] ?? 0))
        ? ($passwordLocked === 1 ? " (trava de senha ativada)" : " (trava de senha desativada)")
        : "";
    logActivity(
        $db,
        $targetUserId,
        'edit_user',
        "Dados do usuário '{$login}' ({$role}) atualizados por {$operatorLogin} ({$operatorRole}){$groupDesc}{$pwdDesc}{$lockDesc}",
        $operatorId,
        $operatorLogin,
        $login
    );

    if ($generateNewPassword || $newPassword) {
        apiSendSecurityAlert(
            'Senha do Usuário Alterada pelo Painel',
            "A senha do usuário '{$login}' foi alterada por {$operatorLogin} ({$operatorRole})",
            [
                'Usuário Alvo' => $login,
                'E-mail Alvo' => $email,
                'Executor' => "{$operatorLogin} ({$operatorRole})",
                'Status' => $generateNewPassword ? 'Nova senha temporária gerada' : 'Senha redefinida',
            ]
        );
    }

    if ($passwordLocked !== (int) ($targetUser['password_locked'] ?? 0)) {
        $lockState = $passwordLocked === 1 ? 'bloqueada' : 'desbloqueada';
        apiSendSecurityAlert(
            'Status da Trava de Senha Alterado',
            "A troca de senha do usuário '{$login}' foi {$lockState} por {$operatorLogin} ({$operatorRole})",
            [
                'Usuário Alvo' => $login,
                'E-mail Alvo' => $email,
                'Executor' => "{$operatorLogin} ({$operatorRole})",
                'Novo Status' => $passwordLocked === 1 ? 'TRAVA ATIVADA' : 'TRAVA DESATIVADA',
            ]
        );
    }

    echo json_encode([
        'ok' => true,
        'user' => [
            'id' => $targetUserId,
            'login' => $login,
            'email' => $email,
            'role' => $role,
            'group_id' => $groupId,
            'group_name' => $groupName,
            'password_locked' => (bool) $passwordLocked
        ],
        'generated_password' => $generatedPassword,
        'message' => 'Usuário atualizado com sucesso.'
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao salvar alterações no usuário.']);
}
