<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

startSecureSession();
apiSendJsonHeaders();

// Endpoint de leitura: é aqui que o token CSRF da sessão é emitido, inclusive
// para quem ainda não fez login — o próprio POST de login precisa dele.
$csrfToken = apiCsrfToken();

if (!isset($_SESSION['user_id'])) {
    apiJsonResponse(401, ['error' => 'Não autenticado.', 'csrf_token' => $csrfToken]);
}

$db = getDbConnection();
$stmt = $db->prepare("
    SELECT u.id, u.login, u.email, u.role, u.group_id, u.force_password_change,
           g.name AS group_name, g.powerbi_url AS group_powerbi_url
    FROM users u
    LEFT JOIN `groups` g ON u.group_id = g.id
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    // Sessão apontando para um usuário que não existe mais: derruba tudo.
    apiDestroySession();
    apiJsonResponse(401, ['error' => 'Usuário não encontrado.']);
}

apiJsonResponse(200, [
    'ok' => true,
    'csrf_token' => $csrfToken,
    'user' => [
        'id' => (int) $user['id'],
        'login' => $user['login'],
        'email' => $user['email'],
        'role' => $user['role'],
        'group_id' => $user['group_id'] ? (int) $user['group_id'] : null,
        'group_name' => $user['group_name'] ?? null,
        'group_powerbi_url' => $user['group_powerbi_url'] ?? null,
        'force_password_change' => (bool) $user['force_password_change'],
    ],
]);
