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

if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode(['error' => 'A nova senha deve ter pelo menos 6 caracteres.']);
    exit;
}

$db = getDbConnection();
$hash = password_hash($newPassword, PASSWORD_DEFAULT);

$stmt = $db->prepare("UPDATE users SET password_hash = ?, force_password_change = 0 WHERE id = ?");
$stmt->execute([$hash, $actor['id']]);

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
