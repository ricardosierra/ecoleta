<?php
// Desenvolvido por Sierra Tecnologia — https://sierratecnologia.com.br
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// ── Configuração ─────────────────────────────────────────────────────────────
// Destino e remetente vêm de public/api/env.php (gerado pelo deploy) ou de
// variável de ambiente do servidor. Os valores abaixo são o padrão de quando
// nada está configurado: constante vazia, como o deploy grava quando a variável
// não está no .env, também significa "não configurado".
const CONTACT_TO_DEFAULT   = 'diretoria@econformidade.com.br';
const CONTACT_FROM_DEFAULT = 'noreply@ecoleva.com';

// Limite de envio por IP. Só conta o que viraria e-mail: requisição inválida e
// honeypot não gastam cota.
const CONTACT_RATE_PER_MINUTE = 5;
const CONTACT_RATE_PER_HOUR   = 30;
// ─────────────────────────────────────────────────────────────────────────────

// Mesmo arquivo que public/api/db.php carrega. `ECOLETA_ENV_FILE` troca o
// caminho e `none` não carrega nenhum (é o que a suíte de testes usa, para o
// env.php de produção da máquina de quem edita nunca entrar nos testes).
// Arquivo ausente não é erro: sobram as variáveis de ambiente e os padrões.
$envOverride = trim((string) (getenv('ECOLETA_ENV_FILE') ?: ''));
$envFile     = $envOverride !== '' ? $envOverride : __DIR__ . '/api/env.php';
if ($envOverride !== 'none' && is_file($envFile)) {
    require_once $envFile;
}

/**
 * Lê uma configuração: constante do env.php primeiro, variável de ambiente
 * depois. Vazio conta como ausente e cai em $default.
 */
function contactSetting(string $name, string $default = ''): string
{
    $value = defined($name) ? trim((string) constant($name)) : '';
    if ($value === '') {
        $value = trim((string) (getenv($name) ?: ''));
    }

    return $value !== '' ? $value : $default;
}

/**
 * Endereço de e-mail vindo da configuração. Valor malformado volta ao padrão e
 * deixa o motivo no log: um destino com quebra de linha viraria cabeçalho extra
 * (Bcc) no e-mail, e um erro de digitação não pode fazer o formulário parar.
 */
function contactAddress(string $name, string $default): string
{
    $value = contactSetting($name);
    if ($value === '') {
        return $default;
    }
    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        error_log("contact.php: {$name} não é um e-mail válido; usando o padrão.");

        return $default;
    }

    return $value;
}

/**
 * Entrega o e-mail. Único ponto que chama mail(), de propósito: é o que a suíte
 * enxerga e controla (via sendmail_path), sem nenhum envio real.
 *
 * `MAIL_TRANSPORT=log` desliga o envio, como já faz a Ordem de Serviço: em
 * máquina local mail() cairia em um sendmail inexistente.
 */
function contactDeliverMail(string $to, string $subject, string $mime, string $headers): bool
{
    if (strcasecmp(contactSetting('MAIL_TRANSPORT'), 'log') === 0) {
        error_log('MAIL_TRANSPORT=log: e-mail do formulário de contato não foi enviado.');

        return true;
    }

    return mail($to, $subject, $mime, $headers);
}

/**
 * Registra uma tentativa de envio deste IP e diz se ela passa.
 *
 * O estado mora em arquivos na pasta temporária do sistema, sem banco: uma
 * lista de instantes por IP, em arquivo cujo nome é um hash do IP. Nada de
 * endereço, nome ou e-mail em claro, nem no nome nem no conteúdo.
 *
 * Falha ABERTA: qualquer problema para criar, travar, ler ou gravar o estado
 * deixa o visitante passar. Perder o limite por um instante é melhor do que
 * perder um contato de cliente porque o /tmp encheu.
 *
 * Tentativa barrada não é registrada, para que insistir durante o bloqueio não
 * empurre a janela para a frente.
 *
 * @return array{allowed: bool, retryAfter: int} segundos até poder tentar de novo
 */
function contactRateCheck(string $ip, int $now): array
{
    $open = ['allowed' => true, 'retryAfter' => 0];

    $dir = rtrim(sys_get_temp_dir(), '/\\') . '/ecoleta-contact-rl';
    if (is_link($dir)) {
        return $open;
    }
    // mkdir sem recursão: se a pasta temporária nem existe, não inventa caminho.
    if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) {
        return $open;
    }

    $path = $dir . '/' . hash('sha256', 'contact|' . $ip) . '.json';
    if (is_link($path)) {
        return $open;
    }
    $isNew = !is_file($path);

    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return $open;
    }

    $result = $open;
    if (flock($handle, LOCK_EX)) {
        $decoded = json_decode((string) stream_get_contents($handle), true);
        $stamps  = [];
        foreach (is_array($decoded) ? $decoded : [] as $stamp) {
            // Só instantes da última hora; o que está no futuro (relógio que
            // voltou, arquivo adulterado) é descartado.
            if (is_int($stamp) && $stamp > $now - 3600 && $stamp <= $now) {
                $stamps[] = $stamp;
            }
        }
        sort($stamps);

        $lastMinute = array_values(array_filter($stamps, static fn (int $s): bool => $s > $now - 60));
        $wait       = 0;
        if (count($lastMinute) >= CONTACT_RATE_PER_MINUTE) {
            $wait = max($wait, $lastMinute[0] + 60 - $now);
        }
        if (count($stamps) >= CONTACT_RATE_PER_HOUR) {
            // A vaga abre quando a tentativa que fecha o lote de 30 completa uma hora.
            $wait = max($wait, $stamps[count($stamps) - CONTACT_RATE_PER_HOUR] + 3600 - $now);
        }

        if ($wait > 0) {
            $result = ['allowed' => false, 'retryAfter' => max(1, $wait)];
        } else {
            $stamps[] = $now;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string) json_encode($stamps));
            fflush($handle);
        }
        flock($handle, LOCK_UN);
    }
    fclose($handle);

    // IP novo é a hora de varrer: arquivo parado há mais de uma hora não tem mais
    // nada a contar. Fazer isso só na criação mantém o custo proporcional ao
    // número de visitantes novos, sem tarefa agendada.
    if ($isNew) {
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if ($file !== $path && !is_link($file) && (int) @filemtime($file) < $now - 3600) {
                @unlink($file);
            }
        }
    }

    return $result;
}

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

// Validação (mesmas regras do formulário em components/ContactForm.tsx; o Zod de
// lib/contact-schema.ts NÃO roda aqui, então este é o único validador de servidor)
$issues = [];

$nome         = trim((string)($body['nome']         ?? ''));
$email        = trim((string)($body['email']        ?? ''));
$telefone     = trim((string)($body['telefone']     ?? ''));
$empresa      = trim((string)($body['empresa']      ?? ''));
$tipoOperacao = (string)($body['tipoOperacao']      ?? '');
$mensagem     = trim((string)($body['mensagem']     ?? ''));

$tiposValidos = ['Empresa', 'Indústria', 'Evento', 'Condomínio', 'Obra', 'Outro'];

// Curto demais e comprido demais são problemas diferentes: dizer "informe seu
// nome" a quem digitou 130 letras só confunde.
if (mb_strlen($nome) < 2)         $issues[] = ['path' => 'nome',     'message' => 'Informe seu nome.'];
elseif (mb_strlen($nome) > 120)   $issues[] = ['path' => 'nome',     'message' => 'O nome pode ter no máximo 120 caracteres.'];
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $issues[] = ['path' => 'email', 'message' => 'E-mail inválido.'];
if (mb_strlen($telefone) < 8)       $issues[] = ['path' => 'telefone', 'message' => 'Informe um telefone válido.'];
elseif (mb_strlen($telefone) > 40)  $issues[] = ['path' => 'telefone', 'message' => 'O telefone pode ter no máximo 40 caracteres.'];
if (mb_strlen($empresa) < 2)        $issues[] = ['path' => 'empresa',  'message' => 'Informe a empresa.'];
elseif (mb_strlen($empresa) > 160)  $issues[] = ['path' => 'empresa',  'message' => 'O nome da empresa pode ter no máximo 160 caracteres.'];
if (!in_array($tipoOperacao, $tiposValidos, true)) $issues[] = ['path' => 'tipoOperacao', 'message' => 'Selecione o tipo de operação.'];
if (mb_strlen($mensagem) < 10)       $issues[] = ['path' => 'mensagem', 'message' => 'Conte um pouco sobre sua operação.'];
elseif (mb_strlen($mensagem) > 4000) $issues[] = ['path' => 'mensagem', 'message' => 'A mensagem pode ter no máximo 4000 caracteres.'];

if (!empty($issues)) {
    http_response_code(400);
    echo json_encode(['error' => 'Dados inválidos.', 'issues' => $issues]);
    exit;
}

// Limite por IP. REMOTE_ADDR e nada mais: cabeçalhos como X-Forwarded-For o
// visitante forja, e bastaria variar um deles para escapar do limite.
$ip   = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$rate = contactRateCheck($ip !== '' ? $ip : 'unknown', time());
if (!$rate['allowed']) {
    header('Retry-After: ' . $rate['retryAfter']);
    http_response_code(429);
    echo json_encode([
        'error'      => 'Você enviou muitas mensagens em pouco tempo. Aguarde um pouco e tente de novo.',
        'retryAfter' => $rate['retryAfter'],
    ], JSON_UNESCAPED_UNICODE);
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

// No HTML, tudo que o visitante digitou passa por esc(): este e-mail chega à
// diretoria no template confiável "Novo contato", e um <a> ou <img onerror>
// colado no campo Nome virava marcação ativa. Até o e-mail precisa: o filtro do
// PHP aceita parte local entre aspas, e as aspas fechariam o atributo href.
$hNome         = esc($sNome);
$hEmail        = esc($sEmail);
$hTelefone     = esc($sTelefone);
$hEmpresa      = esc($sEmpresa);
$hTipoOperacao = esc($sTipoOperacao);
$hMensagem     = esc($mensagem);

// Corpo HTML (template igual ao route.ts Node.js)
$htmlBody = <<<HTML
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#242424;">
  <h1 style="font-size:18px;margin:0 0 16px;color:#0D1F0F;">Novo contato pelo site Ecoleva</h1>
  <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
    <tr><td style="padding:8px 0;color:#5a5a5a;width:160px;">Nome</td><td style="padding:8px 0;font-weight:600;">{$hNome}</td></tr>
    <tr><td style="padding:8px 0;color:#5a5a5a;">E-mail</td><td style="padding:8px 0;"><a href="mailto:{$hEmail}" style="color:#2D5934;">{$hEmail}</a></td></tr>
    <tr><td style="padding:8px 0;color:#5a5a5a;">Telefone/WhatsApp</td><td style="padding:8px 0;">{$hTelefone}</td></tr>
    <tr><td style="padding:8px 0;color:#5a5a5a;">Empresa</td><td style="padding:8px 0;font-weight:600;">{$hEmpresa}</td></tr>
    <tr><td style="padding:8px 0;color:#5a5a5a;">Tipo de operação</td><td style="padding:8px 0;">{$hTipoOperacao}</td></tr>
  </table>
  <div style="background:#ECF5FB;border-left:4px solid #7ED957;padding:16px;border-radius:6px;margin-bottom:16px;">
    <p style="margin:0 0 8px;color:#5a5a5a;font-size:13px;text-transform:uppercase;letter-spacing:0.1em;">Mensagem</p>
    <p style="margin:0;white-space:pre-wrap;">{$hMensagem}</p>
  </div>
  <p style="margin:0;color:#5a5a5a;font-size:12px;">Origem: Site Ecoleva · {$now}</p>
</div>
HTML;

// Corpo texto puro (fallback). Texto não interpreta marcação: sem escape, o que
// o visitante escreveu chega como escreveu.
$textBody = "Novo contato recebido pelo site Ecoleva\n\n"
          . "Nome: {$sNome}\n"
          . "E-mail: {$sEmail}\n"
          . "Telefone/WhatsApp: {$sTelefone}\n"
          . "Empresa: {$sEmpresa}\n"
          . "Tipo de operação: {$sTipoOperacao}\n\n"
          . "Mensagem:\n{$mensagem}\n\n"
          . "Data/Hora: {$now}\nOrigem: Site Ecoleva";

$destino   = contactAddress('CONTACT_TO_EMAIL', CONTACT_TO_DEFAULT);
$remetente = contactAddress('CONTACT_FROM_EMAIL', CONTACT_FROM_DEFAULT);

// Monta e-mail multipart (HTML + texto)
$boundary = '----=_' . bin2hex(random_bytes(8));
$subject  = '=?UTF-8?B?' . base64_encode('Novo contato pelo site Ecoleva') . '?=';
$headers  = implode("\r\n", [
    'From: Site Ecoleva <' . $remetente . '>',
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

if (contactDeliverMail($destino, $subject, $mime, $headers)) {
    echo json_encode(['ok' => true]);
} else {
    // Sem nome, e-mail, telefone ou mensagem do visitante: o log do servidor não
    // é lugar de dado pessoal. O visitante recebe erro (nunca "ok"), para não
    // achar que falou com a diretoria quando a mensagem se perdeu.
    error_log('contact.php: mail() recusou o envio do formulário de contato.');
    http_response_code(500);
    echo json_encode([
        'error' => 'Não foi possível enviar sua mensagem agora. Tente novamente ou fale pelo WhatsApp.',
    ], JSON_UNESCAPED_UNICODE);
}
