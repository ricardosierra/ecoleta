<?php
declare(strict_types=1);

/**
 * Instalação única e explícita do usuário root.
 *
 * Substitui o bootstrap implícito que existia em auth/login.php (qualquer
 * pessoa que acertasse a senha padrão criava um root). Regras:
 *
 *  - Só existe enquanto DASHBOARD_INSTALL_TOKEN estiver definido no env.php.
 *    Sem token definido o arquivo responde 404, como o setup_db.php.
 *  - Exige POST com o cabeçalho X-Install-Token igual ao token configurado.
 *  - Só cria o root se ainda não existir nenhum usuário com esse papel.
 *  - Depois de rodar, grava .install-lock ao lado e passa a responder 404
 *    (auto-desativação); a própria existência do root também já basta.
 *
 * Uso (uma única vez, depois do primeiro deploy):
 *
 *   curl -X POST https://SEU-DOMINIO/api/install.php \
 *        -H "X-Install-Token: <valor de DASHBOARD_INSTALL_TOKEN>" \
 *        -H "Content-Type: application/json" \
 *        -d '{"login":"admin","email":"admin@exemplo.com"}'
 *
 * A senha vem na resposta (uma única vez) e precisa ser trocada no primeiro
 * acesso. Depois disso, apague DASHBOARD_INSTALL_TOKEN do .env.
 */

require_once __DIR__ . '/db.php';

const INSTALL_LOCK_FILE = __DIR__ . '/.install-lock';
const INSTALL_TOKEN_MIN_LENGTH = 24;
const INSTALL_PASSWORD_MIN_LENGTH = 12;

/** Some da vista: qualquer recusa devolve 404 e deixa o motivo apenas no log do servidor. */
function installUnavailable(string $reason): void
{
    error_log(sprintf('install.php indisponível (%s) — origem %s', $reason, apiClientIp()));
    http_response_code(404);
    exit;
}

function installDisableSelf(): void
{
    if (@file_put_contents(INSTALL_LOCK_FILE, 'instalado em ' . gmdate('c') . "\n", LOCK_EX) === false) {
        // Sem permissão de escrita o arquivo continua acessível, mas a checagem
        // de "já existe root" abaixo mantém a instalação bloqueada.
        error_log('install.php não conseguiu gravar .install-lock — a trava passa a depender da existência do root.');
    }
}

if (file_exists(INSTALL_LOCK_FILE)) {
    installUnavailable('lock presente');
}

$installToken = apiSecret('DASHBOARD_INSTALL_TOKEN');
if ($installToken === '') {
    installUnavailable('DASHBOARD_INSTALL_TOKEN não definido');
}

if (strlen($installToken) < INSTALL_TOKEN_MIN_LENGTH) {
    installUnavailable('DASHBOARD_INSTALL_TOKEN com menos de ' . INSTALL_TOKEN_MIN_LENGTH . ' caracteres');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    installUnavailable('método ' . (string) ($_SERVER['REQUEST_METHOD'] ?? '?'));
}

$sentToken = trim(apiRequestHeader('X-Install-Token'));
if ($sentToken === '' || !hash_equals($installToken, $sentToken)) {
    installUnavailable('token de instalação inválido');
}

$db = getDbConnection();

$existing = $db->query("SELECT COUNT(*) AS total FROM users WHERE role = 'root'")->fetch();
if ((int) ($existing['total'] ?? 0) > 0) {
    installDisableSelf();
    installUnavailable('já existe um usuário root');
}

$body = json_decode((string) file_get_contents('php://input'), true) ?? [];

$login = trim((string) ($body['login'] ?? ''));
if ($login === '') {
    $login = apiSecret('DASHBOARD_ROOT_LOGIN');
}
if ($login === '') {
    $login = 'admin';
}

if (!preg_match('/^[A-Za-z0-9._@-]{3,50}$/', $login)) {
    apiJsonResponse(400, ['error' => 'Login inválido. Use de 3 a 50 caracteres entre letras, números, ponto, hífen, underline ou arroba.']);
}

$email = trim((string) ($body['email'] ?? ''));
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    apiJsonResponse(400, ['error' => 'E-mail inválido.']);
}

// Senha informada pelo instalador ou sorteada aqui e devolvida uma única vez.
$password = (string) ($body['password'] ?? '');
$generated = false;

if ($password === '') {
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < 20; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    $generated = true;
} elseif (strlen($password) < INSTALL_PASSWORD_MIN_LENGTH) {
    apiJsonResponse(400, ['error' => 'A senha do root deve ter pelo menos ' . INSTALL_PASSWORD_MIN_LENGTH . ' caracteres.']);
}

try {
    $stmt = $db->prepare("
        INSERT INTO users (login, password_hash, email, role, group_id, force_password_change)
        VALUES (?, ?, ?, 'root', NULL, 1)
    ");
    $stmt->execute([$login, password_hash($password, PASSWORD_DEFAULT), $email !== '' ? $email : null]);
    $rootId = (int) $db->lastInsertId();
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        apiJsonResponse(409, ['error' => 'Já existe um usuário com este login ou e-mail.']);
    }

    error_log('Falha ao criar o usuário root na instalação: ' . $e->getMessage());
    apiJsonResponse(500, ['error' => 'Não foi possível concluir a instalação.']);
}

logActivity(
    $db,
    $rootId,
    'install',
    "Usuário root '{$login}' criado pela instalação inicial",
    $rootId,
    $login,
    $login
);

installDisableSelf();

apiJsonResponse(201, [
    'ok' => true,
    'login' => $login,
    // A senha só volta quando foi sorteada aqui — senha enviada pelo instalador não é ecoada.
    'password' => $generated ? $password : null,
    'message' => 'Root criado. Guarde a senha, troque-a no primeiro acesso e remova DASHBOARD_INSTALL_TOKEN do .env.',
]);
