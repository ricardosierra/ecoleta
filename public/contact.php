<?php
// Desenvolvido por Sierra Tecnologia — https://sierratecnologia.com.br
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// ── Configuração ─────────────────────────────────────────────────────────────
// Substitua pelo e-mail de destino antes do go-live.
define('CONTACT_TO_EMAIL', getenv('CONTACT_TO_EMAIL') ?: 'contato@ecoleta.com');
define('CONTACT_FROM',     'noreply@ecoleta.com');
// ─────────────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido.']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Requisição inválida.']);
    exit;
}

// Honeypot — resposta 200 silenciosa para bots
if (!empty($body['website'])) {
    echo json_encode(['ok' => true]);
    exit;
}

// Validação (espelha o Zod schema em lib/contact-schema.ts)
$issues = [];

$nome         = trim((string)($body['nome']         ?? ''));
$email        = trim((string)($body['email']        ?? ''));
$telefone     = trim((string)($body['telefone']     ?? ''));
$empresa      = trim((string)($body['empresa']      ?? ''));
$tipoOperacao = (string)($body['tipoOperacao']      ?? '');
$mensagem     = trim((string)($body['mensagem']     ?? ''));

$tiposValidos = ['Empresa', 'Indústria', 'Evento', 'Condomínio', 'Obra', 'Outro'];

if (mb_strlen($nome)     < 2  || mb_strlen($nome)     > 120) $issues[] = ['path' => 'nome',         'message' => 'Informe seu nome.'];
if (!filter_var($email, FILTER_VALIDATE_EMAIL)               ) $issues[] = ['path' => 'email',        'message' => 'E-mail inválido.'];
if (mb_strlen($telefone) < 8  || mb_strlen($telefone) > 40)   $issues[] = ['path' => 'telefone',     'message' => 'Informe um telefone válido.'];
if (mb_strlen($empresa)  < 2  || mb_strlen($empresa)  > 160)  $issues[] = ['path' => 'empresa',      'message' => 'Informe a empresa.'];
if (!in_array($tipoOperacao, $tiposValidos, true))             $issues[] = ['path' => 'tipoOperacao', 'message' => 'Selecione o tipo de operação.'];
if (mb_strlen($mensagem) < 10 || mb_strlen($mensagem) > 4000) $issues[] = ['path' => 'mensagem',     'message' => 'Conte um pouco sobre sua operação.'];

if (!empty($issues)) {
    http_response_code(400);
    echo json_encode(['error' => 'Dados inválidos.', 'issues' => $issues]);
    exit;
}

// Sanitização
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
function noInject(string $s): string {
    return trim(preg_replace('/[\r\n]/', ' ', $s) ?? '');
}

$sNome         = noInject($nome);
$sEmail        = noInject($email);
$sTelefone     = noInject($telefone);
$sEmpresa      = noInject($empresa);
$sTipoOperacao = noInject($tipoOperacao);
$now           = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i');

// Corpo HTML (template igual ao route.ts Node.js)
$htmlBody = <<<HTML
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#242424;">
  <h1 style="font-size:18px;margin:0 0 16px;color:#0D1F0F;">Novo contato pelo site Ecoleta</h1>
  <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
    <tr><td style="padding:8px 0;color:#5a5a5a;width:160px;">Nome</td><td style="padding:8px 0;font-weight:600;">{$sNome}</td></tr>
    <tr><td style="padding:8px 0;color:#5a5a5a;">E-mail</td><td style="padding:8px 0;"><a href="mailto:{$sEmail}" style="color:#2D5934;">{$sEmail}</a></td></tr>
    <tr><td style="padding:8px 0;color:#5a5a5a;">Telefone/WhatsApp</td><td style="padding:8px 0;">{$sTelefone}</td></tr>
    <tr><td style="padding:8px 0;color:#5a5a5a;">Empresa</td><td style="padding:8px 0;font-weight:600;">{$sEmpresa}</td></tr>
    <tr><td style="padding:8px 0;color:#5a5a5a;">Tipo de operação</td><td style="padding:8px 0;">{$sTipoOperacao}</td></tr>
  </table>
  <div style="background:#ECF5FB;border-left:4px solid #7ED957;padding:16px;border-radius:6px;margin-bottom:16px;">
    <p style="margin:0 0 8px;color:#5a5a5a;font-size:13px;text-transform:uppercase;letter-spacing:0.1em;">Mensagem</p>
    <p style="margin:0;white-space:pre-wrap;">{$mensagem}</p>
  </div>
  <p style="margin:0;color:#5a5a5a;font-size:12px;">Origem: Site Ecoleta · {$now}</p>
</div>
HTML;

// Corpo texto puro (fallback)
$textBody = "Novo contato recebido pelo site Ecoleta\n\n"
          . "Nome: {$sNome}\n"
          . "E-mail: {$sEmail}\n"
          . "Telefone/WhatsApp: {$sTelefone}\n"
          . "Empresa: {$sEmpresa}\n"
          . "Tipo de operação: {$sTipoOperacao}\n\n"
          . "Mensagem:\n{$mensagem}\n\n"
          . "Data/Hora: {$now}\nOrigem: Site Ecoleta";

// Monta e-mail multipart (HTML + texto)
$boundary = '----=_' . bin2hex(random_bytes(8));
$subject  = '=?UTF-8?B?' . base64_encode('Novo contato pelo site Ecoleta') . '?=';
$headers  = implode("\r\n", [
    'From: Site Ecoleta <' . CONTACT_FROM . '>',
    "Reply-To: {$sEmail}",
    'MIME-Version: 1.0',
    "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
    'X-Mailer: PHP/' . PHP_VERSION,
]);

$mime = "--{$boundary}\r\n"
      . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
      . quoted_printable_encode($textBody)
      . "\r\n--{$boundary}\r\n"
      . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
      . quoted_printable_encode($htmlBody)
      . "\r\n--{$boundary}--";

$sent = mail(CONTACT_TO_EMAIL, $subject, $mime, $headers);

if ($sent) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Falha ao enviar a mensagem.']);
}
