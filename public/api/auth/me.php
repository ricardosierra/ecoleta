<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';

startSecureSession();
apiSendJsonHeaders();

// Endpoint de leitura: é aqui que o token CSRF da sessão é emitido, inclusive
// para quem ainda não fez login — o próprio POST de login precisa dele.
$csrfToken = apiCsrfToken();

$sessionUserId = apiSessionUserId();
if ($sessionUserId === null) {
    apiJsonResponse(401, ['error' => 'Não autenticado.', 'csrf_token' => $csrfToken]);
}

// É aqui que a sessão se põe em dia com o banco quando a tela abre: papel e
// login mudados são regravados na sessão, e conta excluída, ou com a senha
// trocada depois do login, tem a sessão destruída. Sem o csrf_token na resposta
// de propósito: o token era o da sessão que acabou de morrer, e a tela pede um
// novo (a este mesmo endpoint) antes do próximo login.
if (apiResolveSessionActor($sessionUserId) === null) {
    apiJsonResponse(401, ['error' => 'Não autenticado.']);
}

$db = getDbConnection();
$stmt = $db->prepare("
    SELECT u.id, u.login, u.email, u.role, u.group_id, u.force_password_change, u.password_locked,
           g.name AS group_name, g.powerbi_url AS group_powerbi_url
    FROM users u
    LEFT JOIN `groups` g ON u.group_id = g.id
    WHERE u.id = ?
");
$stmt->execute([$sessionUserId]);
$user = $stmt->fetch();

if (!$user) {
    // A conta sumiu entre a conferência acima e esta leitura: derruba tudo.
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
        'password_locked' => (bool) ($user['password_locked'] ?? false),
    ],
]);
