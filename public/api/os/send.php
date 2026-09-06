<?php
declare(strict_types=1);

/**
 * Encaminha uma Ordem de Serviço por e-mail.
 *
 * O corpo é o mesmo documento da página pública (os_lib.php) mais o botão que
 * leva ao link com token — o destinatário imprime ou salva em PDF de lá.
 *
 * O WhatsApp não passa por aqui: sem API oficial no projeto, o dashboard só
 * abre o wa.me com o texto pronto e o mesmo link. Ver `lib/os-share.ts`.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';
require_once __DIR__ . '/os_lib.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();

// Mesma régua da tela: OS é módulo de administrador.
$operator = apiRequireAdmin();

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    apiJsonResponse(405, ['error' => 'Método não permitido.'], ['Allow: POST']);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}

$id = (int) ($body['id'] ?? 0);
if ($id <= 0) {
    apiJsonResponse(400, ['error' => 'Informe a OS a encaminhar.']);
}

$db = getDbConnection();
$os = osFindById($db, $id);

if ($os === null) {
    apiJsonResponse(404, ['error' => 'Ordem de serviço não encontrada.']);
}

// Destinatário: o que o operador digitou vence; sem isso, o e-mail do cliente.
$destino = trim((string) ($body['email'] ?? ''));
if ($destino === '') {
    $destino = trim((string) ($os['client_email'] ?? ''));
}

if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) {
    apiJsonResponse(400, [
        'error' => $destino === ''
            ? 'Este cliente não tem e-mail cadastrado. Informe um destinatário.'
            : 'E-mail de destino inválido.',
    ]);
}

// Uma quebra de linha no destinatário injetaria cabeçalhos no envelope SMTP.
// FILTER_VALIDATE_EMAIL já recusa, mas a garantia é barata demais para omitir.
if (preg_match('/[\r\n]/', $destino) === 1) {
    apiJsonResponse(400, ['error' => 'E-mail de destino inválido.']);
}

// OS anterior à migration 014 pode ter chegado sem token; gera na hora em vez
// de mandar um link que não abre.
$token = (string) ($os['share_token'] ?? '');
if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
    $token = osShareTokenNew();
    $stmt = $db->prepare('UPDATE service_orders SET share_token = ? WHERE id = ?');
    $stmt->execute([$token, $id]);
    $os['share_token'] = $token;
}

$baseUrl = osBaseUrl();
$shareUrl = osShareUrl($id, $token, $baseUrl);

// Reply-To no e-mail de quem apertou o botão: a resposta do cliente cai em uma
// caixa de gente, não no remetente automático.
$stmtOperador = $db->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
$stmtOperador->execute([$operator['id']]);
$replyTo = $stmtOperador->fetchColumn();
$replyTo = is_string($replyTo) && $replyTo !== '' ? $replyTo : null;

$enviado = osSendMail(
    $destino,
    osEmailSubject($os),
    osEmailHtml($os, $baseUrl, $shareUrl),
    osEmailText($os, $shareUrl),
    $replyTo
);

if (!$enviado) {
    error_log(sprintf('Falha ao enviar a OS #%d para %s.', $id, $destino));
    apiJsonResponse(502, ['error' => 'Não foi possível enviar o e-mail agora. Tente novamente.']);
}

$stmt = $db->prepare('UPDATE service_orders SET sent_at = CURRENT_TIMESTAMP, sent_to = ? WHERE id = ?');
$stmt->execute([$destino, $id]);

logActivity(
    $db,
    null,
    'os_send_email',
    sprintf('OS #%s encaminhada para %s', osNumber($id), $destino),
    $operator['id'],
    $operator['login']
);

$atualizada = osFindById($db, $id);

apiJsonResponse(200, [
    'ok' => true,
    'sent_to' => $destino,
    'sent_at' => $atualizada['sent_at'] ?? null,
    'share_url' => $shareUrl,
]);
