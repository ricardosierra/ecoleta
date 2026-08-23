<?php
declare(strict_types=1);

/**
 * Helpers de sessão, CSRF e resposta JSON compartilhados por todos os endpoints.
 *
 * PHP 8 puro, sem Composer e sem extensões além das habituais em hospedagem
 * compartilhada (pdo_mysql, json, hash).
 */

// Nome próprio do cookie de sessão: não colide com outras aplicações da mesma conta.
const API_SESSION_NAME = 'ECOLETA_SESSION';
const API_CSRF_SESSION_KEY = 'csrf_token';
const API_CSRF_HEADER = 'X-CSRF-Token';
// Sessão parada por mais tempo que isto é esvaziada (8 horas).
const API_SESSION_IDLE_TIMEOUT = 28800;

/**
 * Detecta HTTPS considerando proxies/balanceadores comuns em hospedagem
 * compartilhada. É o que define a flag `secure` do cookie: em HTTP puro
 * (ambiente local) marcar `secure` impediria o navegador de guardar o cookie
 * e o login simplesmente não funcionaria.
 */
function apiIsHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    $proto = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if ($proto !== '' && strtolower(trim(explode(',', $proto)[0])) === 'https') {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        return true;
    }

    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/**
 * Caminho do cookie de sessão: a pasta /api/ do deploy atual (a conta pode
 * publicar o site na raiz ou em um subdiretório). Restringir o cookie a /api/
 * evita que ele seja enviado em requisições de páginas e assets estáticos.
 */
function apiBasePath(): string
{
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $pos = strpos($script, '/api/');
    if ($pos === false) {
        return '/';
    }

    return substr($script, 0, $pos + 5);
}

/**
 * IP real do cliente, melhor esforço — apenas para log/auditoria.
 * NÃO usar como chave de rate limit: cabeçalhos podem ser forjados.
 */
function apiClientIp(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_CLIENT_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $candidate = (string) $candidate;
        if ($candidate === '') {
            continue;
        }
        $first = trim(explode(',', $candidate)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return substr($first, 0, 45);
        }
    }

    return 'unknown';
}

/**
 * IP usado como chave de bloqueio. Só REMOTE_ADDR, que o cliente não forja —
 * caso contrário bastaria variar X-Forwarded-For para escapar do lockout.
 */
function apiThrottleIp(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return $ip !== '' ? substr($ip, 0, 45) : 'unknown';
}

/** Lê um cabeçalho da requisição de forma portável entre SAPIs. */
function apiRequestHeader(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
        return $_SERVER[$key];
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $header => $value) {
                if (is_string($value) && strcasecmp((string) $header, $name) === 0) {
                    return $value;
                }
            }
        }
    }

    return '';
}

/**
 * Lê um segredo de configuração sem nunca cair em valor padrão.
 * Devolve string vazia quando não está definido — o chamador é obrigado a
 * tratar isso como "recurso desligado" e registrar o motivo.
 */
function apiSecret(string $name): string
{
    $value = '';
    if (defined($name)) {
        $value = (string) constant($name);
    }
    if ($value === '') {
        $value = (string) (getenv($name) ?: '');
    }

    return trim($value);
}

function apiSendJsonHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

/** Emite uma resposta JSON e encerra a requisição. */
function apiJsonResponse(int $status, array $payload, array $extraHeaders = []): void
{
    apiSendJsonHeaders();
    foreach ($extraHeaders as $header) {
        if (!headers_sent()) {
            header($header);
        }
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiSessionCookieParams(): array
{
    return [
        'lifetime' => 0,
        'path' => apiBasePath(),
        'domain' => '',
        'secure' => apiIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/**
 * Abre a sessão com cookie endurecido (HttpOnly, Secure sob HTTPS, SameSite=Lax),
 * aplica timeout de inatividade e garante um token CSRF na sessão.
 */
function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (!headers_sent()) {
        // Ignora IDs de sessão não emitidos por este servidor (anti session fixation)
        // e recusa ID vindo por querystring.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name(API_SESSION_NAME);
        session_set_cookie_params(apiSessionCookieParams());
    }

    session_start();

    $now = time();
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && ($now - $lastActivity) > API_SESSION_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    $_SESSION['last_activity'] = $now;
    apiEnsureCsrfToken();
}

/**
 * Regenera o ID da sessão preservando os dados. Deve ser chamada logo após
 * qualquer mudança de privilégio (login, troca de senha).
 */
function apiRegenerateSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    session_regenerate_id(true);
}

function apiEnsureCsrfToken(): string
{
    $current = $_SESSION[API_CSRF_SESSION_KEY] ?? null;
    if (!is_string($current) || strlen($current) !== 64) {
        $current = bin2hex(random_bytes(32));
        $_SESSION[API_CSRF_SESSION_KEY] = $current;
    }

    return $current;
}

/** Novo token CSRF — usar sempre que a sessão mudar de privilégio. */
function apiRotateCsrfToken(): string
{
    $token = bin2hex(random_bytes(32));
    $_SESSION[API_CSRF_SESSION_KEY] = $token;

    return $token;
}

function apiCsrfToken(): string
{
    return apiEnsureCsrfToken();
}

/**
 * Exige o token CSRF em qualquer método que não seja de leitura.
 * Encerra a requisição com 403 quando o token está ausente ou não confere.
 */
function apiRequireCsrfToken(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    $sent = trim(apiRequestHeader(API_CSRF_HEADER));
    $expected = $_SESSION[API_CSRF_SESSION_KEY] ?? '';

    if ($sent === '' || !is_string($expected) || $expected === '' || !hash_equals($expected, $sent)) {
        error_log(sprintf(
            'CSRF rejeitado em %s (%s) vindo de %s',
            (string) ($_SERVER['SCRIPT_NAME'] ?? 'desconhecido'),
            $method,
            apiClientIp()
        ));

        apiJsonResponse(403, [
            'error' => 'Sessão expirada ou requisição inválida. Recarregue a página e tente novamente.',
            'code' => 'csrf_invalid',
        ]);
    }
}

/** Encerra a sessão e apaga o cookie no navegador. */
function apiDestroySession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies') && !headers_sent()) {
        $params = apiSessionCookieParams();
        $params['expires'] = time() - 42000;
        unset($params['lifetime']);
        setcookie(session_name(), '', $params);
    }

    session_destroy();
}
