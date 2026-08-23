<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../rate_limit.php';

startSecureSession();
apiRequireCsrfToken();

/**
 * Hash descartável usado só para gastar o mesmo tempo de CPU quando o login
 * não existe. Sem ele, a diferença de tempo entre "usuário inexistente" e
 * "senha errada" já entrega quais logins são válidos.
 */
const LOGIN_DECOY_HASH = '$2y$12$sjULG/Le8MN5X93yGQdlcuTfN/W8HBBzEnzKHNSyBge1kzqfrM2mK';

// Resposta única para qualquer falha de credencial — nunca revela se o login existe.
const LOGIN_GENERIC_ERROR = 'Credenciais inválidas.';

/** Traduz segundos de bloqueio em uma espera legível. */
function loginWaitLabel(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . ' segundos';
    }

    $minutes = (int) ceil($seconds / 60);

    return $minutes === 1 ? '1 minuto' : $minutes . ' minutos';
}

/** Encerra a requisição com 429 e Retry-After. */
function loginRespondRateLimited(int $seconds): void
{
    apiJsonResponse(
        429,
        [
            'error' => 'Muitas tentativas de login. Tente novamente em ' . loginWaitLabel($seconds) . '.',
            'code' => 'rate_limited',
            'retry_after' => $seconds,
        ],
        ['Retry-After: ' . $seconds]
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiJsonResponse(405, ['error' => 'Método não permitido.']);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true) ?? [];
$login = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($login === '' || $password === '') {
    apiJsonResponse(400, ['error' => 'Usuário e senha são obrigatórios.']);
}

$db = getDbConnection();
$throttleIp = apiThrottleIp();

loginThrottleCleanup($db);

// Bloqueio ativo para este par (login, IP): responde 429 antes de tocar no banco de usuários.
$retryAfter = loginThrottleRetryAfter($db, $login, $throttleIp);
if ($retryAfter > 0) {
    loginRespondRateLimited($retryAfter);
}

$stmt = $db->prepare("SELECT id, login, password_hash, role FROM users WHERE login = ? OR email = ? LIMIT 1");
$stmt->execute([$login, $login]);
$user = $stmt->fetch();

// Verificação sempre executada: usuário inexistente gasta o mesmo tempo do usuário real.
$passwordOk = password_verify($password, is_array($user) ? (string) $user['password_hash'] : LOGIN_DECOY_HASH);

if (!$user || !$passwordOk) {
    $blockedFor = loginThrottleRegisterFailure($db, $login, $throttleIp);

    if ($blockedFor > 0) {
        loginRespondRateLimited($blockedFor);
    }

    apiJsonResponse(401, ['error' => LOGIN_GENERIC_ERROR]);
}

// Autenticado: zera o contador e troca o ID de sessão (anti session fixation).
loginThrottleClear($db, $login, $throttleIp);
apiRegenerateSession();
$csrfToken = apiRotateCsrfToken();

$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['login'] = $user['login'];
$_SESSION['role'] = $user['role'];
$_SESSION['last_activity'] = time();

// Registra acesso
$logStmt = $db->prepare("INSERT INTO access_logs (user_id, ip_address, user_agent) VALUES (?, ?, ?)");
$logStmt->execute([
    $user['id'],
    apiClientIp(),
    substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255),
]);

// Registra no histórico de atividades
logActivity(
    $db,
    (int) $user['id'],
    'login',
    'Login realizado com sucesso',
    (int) $user['id'],
    $user['login'],
    $user['login']
);

// Busca dados completos incluindo o grupo
$stmtDetails = $db->prepare("
    SELECT u.id, u.login, u.email, u.role, u.group_id, u.force_password_change,
           g.name AS group_name, g.powerbi_url AS group_powerbi_url
    FROM users u
    LEFT JOIN `groups` g ON u.group_id = g.id
    WHERE u.id = ?
");
$stmtDetails->execute([$user['id']]);
$userDetails = $stmtDetails->fetch();

apiJsonResponse(200, [
    'ok' => true,
    'csrf_token' => $csrfToken,
    'user' => [
        'id' => (int) $userDetails['id'],
        'login' => $userDetails['login'],
        'email' => $userDetails['email'],
        'role' => $userDetails['role'],
        'group_id' => $userDetails['group_id'] ? (int) $userDetails['group_id'] : null,
        'group_name' => $userDetails['group_name'] ?? null,
        'group_powerbi_url' => $userDetails['group_powerbi_url'] ?? null,
        'force_password_change' => (bool) $userDetails['force_password_change'],
    ],
]);
