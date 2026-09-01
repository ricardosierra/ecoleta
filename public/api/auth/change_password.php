<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';

startSecureSession();
apiRequireCsrfToken();

apiSendJsonHeaders();

// Único endpoint que ainda lia $_SESSION['user_id'] na mão. Todo o resto trata
// sessão de papel desconhecido como não autenticada; aqui ela passava. Não dava
// escalada — a troca sempre mira o id da própria sessão, nunca o do corpo — mas
// deixava uma porta com regra própria.
$actor = apiRequireAuthenticated();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido.']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true) ?? [];
$newPassword = (string) ($body['new_password'] ?? '');
$emailInput = trim((string) ($body['email'] ?? ''));

if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode(['error' => 'A nova senha deve ter pelo menos 6 caracteres.']);
    exit;
}

$db = getDbConnection();

// E-mail é pedido no primeiro acesso de quem foi criado sem um. Se a conta já
// tem e-mail, o campo é ignorado — a troca por aqui é do próprio usuário, e a
// alteração de e-mail é feita na gestão de usuários.
$stmtCurrent = $db->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
$stmtCurrent->execute([$actor['id']]);
$currentEmail = $stmtCurrent->fetchColumn();
$missingEmail = $currentEmail === null || $currentEmail === '';

$emailToStore = null;
if ($missingEmail) {
    if ($emailInput === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Informe um e-mail para concluir o primeiro acesso.']);
        exit;
    }
    if (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'E-mail inválido.']);
        exit;
    }

    $stmtDup = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
    $stmtDup->execute([$emailInput, $actor['id']]);
    if ($stmtDup->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Este e-mail já está em uso por outro usuário.']);
        exit;
    }

    $emailToStore = $emailInput;
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);

if ($emailToStore !== null) {
    $stmt = $db->prepare("UPDATE users SET password_hash = ?, email = ?, force_password_change = 0 WHERE id = ?");
    $stmt->execute([$hash, $emailToStore, $actor['id']]);
} else {
    $stmt = $db->prepare("UPDATE users SET password_hash = ?, force_password_change = 0 WHERE id = ?");
    $stmt->execute([$hash, $actor['id']]);
}

// Troca de senha é mudança de privilégio: novo ID de sessão e novo token CSRF.
apiRegenerateSession();
$csrfToken = apiRotateCsrfToken();

// Grava no histórico de atividades
logActivity(
    $db,
    $actor['id'],
    'change_password',
    'Senha alterada com sucesso pelo próprio usuário',
    $actor['id'],
    $actor['login'],
    $actor['login']
);

apiJsonResponse(200, ['ok' => true, 'csrf_token' => $csrfToken]);
