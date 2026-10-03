<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';
require_once __DIR__ . '/../rate_limit.php';
require_once __DIR__ . '/../security_alerts.php';
require_once __DIR__ . '/password_audit_lib.php';

startSecureSession();
apiRequireCsrfToken();

apiSendJsonHeaders();

// Único endpoint que ainda lia $_SESSION['user_id'] na mão. Todo o resto trata
// sessão de papel desconhecido como não autenticada; aqui ela passava. Não dava
// escalada — a troca sempre mira o id da própria sessão, nunca o do corpo — mas
// deixava uma porta com regra própria.
// `true`: quem está com senha temporária (force_password_change) é barrado em
// todo o resto da API e passa por aqui, que é por onde a senha temporária deixa
// de existir.
$actor = apiRequireAuthenticated(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido.']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true) ?? [];
$newPassword = (string) ($body['new_password'] ?? '');
$currentPassword = (string) ($body['current_password'] ?? '');
$emailInput = trim((string) ($body['email'] ?? ''));

if (strlen($newPassword) < API_PASSWORD_MIN_LENGTH) {
    http_response_code(400);
    echo json_encode(['error' => 'A nova senha deve ter pelo menos ' . API_PASSWORD_MIN_LENGTH . ' caracteres.']);
    exit;
}

$db = getDbConnection();

// Busca e-mail, hash vigente e status de bloqueio da conta
$stmtCurrent = $db->prepare("SELECT email, password_hash, password_locked FROM users WHERE id = ? LIMIT 1");
$stmtCurrent->execute([$actor['id']]);
$userRow = $stmtCurrent->fetch();
$currentEmail = $userRow['email'] ?? null;
$currentHash = (string) ($userRow['password_hash'] ?? '');
$passwordLocked = (bool) ($userRow['password_locked'] ?? false);

if ($passwordLocked) {
    apiSendSecurityAlert(
        'Tentativa Bloqueada de Troca de Senha',
        'Tentativa de alteração de senha em conta com troca bloqueada',
        [
            'Usuário' => $actor['login'],
            'E-mail da Conta' => $currentEmail ?: 'Não cadastrado',
            'Papel' => $actor['role'],
            'Status' => 'BLOQUEADO (Conta com trava ativada)',
        ]
    );

    logActivity(
        $db,
        $actor['id'],
        'change_password_blocked',
        'Tentativa de troca de senha bloqueada (conta travada)',
        $actor['id'],
        $actor['login'],
        $actor['login']
    );

    http_response_code(403);
    echo json_encode(['error' => 'A troca de senha desta conta está bloqueada pelo administrador.']);
    exit;
}

// Troca VOLUNTÁRIA (a conta não está com senha temporária) exige a senha atual:
// sem isso, quem pegasse uma sessão aberta trocava a senha e ficava com a conta.
// Na troca FORÇADA não se exige: a pessoa acabou de entrar com a senha temporária
// e é essa a que o administrador lhe entregou.
//
// Errar a senha atual aqui é tentar adivinhar a senha da conta por outra porta
// que não a do login, então entra nos MESMOS contadores do login — senão o
// limite de tentativas dele seria contornado por este endpoint.
if (empty($actor['force_password_change'])) {
    $throttleIp = apiThrottleIp();

    $retryAfter = loginThrottleRetryAfter($db, (string) $actor['login'], $throttleIp);
    if ($retryAfter > 0) {
        apiJsonResponse(
            429,
            [
                'error' => 'Muitas tentativas. Tente novamente em instantes.',
                'code' => 'rate_limited',
                'retry_after' => $retryAfter,
            ],
            ['Retry-After: ' . $retryAfter]
        );
    }

    if ($currentPassword === '') {
        apiJsonResponse(400, [
            'error' => 'Informe a senha atual para trocar a senha.',
            'code' => 'current_password_required',
        ]);
    }

    if (!password_verify($currentPassword, $currentHash)) {
        loginThrottleRegisterFailure($db, (string) $actor['login'], $throttleIp);

        logActivity(
            $db,
            $actor['id'],
            'change_password_wrong_current',
            'Troca de senha recusada: senha atual incorreta',
            $actor['id'],
            $actor['login'],
            $actor['login']
        );

        apiJsonResponse(403, [
            'error' => 'A senha atual não confere.',
            'code' => 'current_password_invalid',
        ]);
    }
}

// Trocar a senha pela mesma não troca nada, e deixaria a senha temporária valendo.
if ($currentHash !== '' && password_verify($newPassword, $currentHash)) {
    http_response_code(400);
    echo json_encode(['error' => 'A nova senha deve ser diferente da atual.']);
    exit;
}

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
} else {
    if ($emailInput !== '' && $emailInput !== (string) $currentEmail) {
        http_response_code(400);
        echo json_encode(['error' => 'A alteração de e-mail deve ser feita pela gestão de usuários.']);
        exit;
    }
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);

if ($emailToStore !== null) {
    $stmt = $db->prepare("UPDATE users SET password_hash = ?, email = ?, force_password_change = 0 WHERE id = ?");
    $stmt->execute([$hash, $emailToStore, $actor['id']]);
} else {
    $stmt = $db->prepare("UPDATE users SET password_hash = ?, force_password_change = 0 WHERE id = ?");
    $stmt->execute([$hash, $actor['id']]);
}
attributePasswordHashChange($db, (int) $actor['id'], $hash, 'change_password', (int) $actor['id'], (string) $actor['login']);

// Troca de senha é mudança de privilégio: novo ID de sessão e novo token CSRF.
apiRegenerateSession();
$csrfToken = apiRotateCsrfToken();
// O hash mudou: as OUTRAS sessões desta conta caem (a impressão digital delas não
// bate mais), e esta, que acabou de trocar, é religada ao hash novo.
apiBindSessionToPassword($hash);

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

// Notifica o administrador técnico por e-mail
apiSendSecurityAlert(
    'Senha Alterada pelo Usuário',
    "A senha do usuário '{$actor['login']}' foi alterada com sucesso",
    [
        'Usuário' => $actor['login'],
        'E-mail' => $emailToStore ?? $currentEmail ?? 'Não cadastrado',
        'Papel' => $actor['role'],
        'Status' => 'Senha alterada com sucesso pelo próprio usuário',
    ]
);

apiJsonResponse(200, ['ok' => true, 'csrf_token' => $csrfToken]);
