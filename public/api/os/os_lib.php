<?php
declare(strict_types=1);

/**
 * Peças compartilhadas do encaminhamento de Ordem de Serviço.
 *
 * Três consumidores usam exatamente o mesmo documento:
 *   - `os/view.php`  — a página pública que o cliente abre pelo link;
 *   - `os/send.php`  — o corpo HTML do e-mail;
 *   - o dashboard    — a pré-visualização em `app/dashboard/os/page.tsx`.
 *
 * Os dois primeiros vivem aqui para não divergirem. O terceiro é React e não
 * pode compartilhar código com o PHP, mas lê os mesmos campos e desenha o mesmo
 * cabeçalho, os mesmos rótulos e a mesma assinatura — quem mexer em um lado
 * precisa olhar o outro.
 */

require_once __DIR__ . '/../security.php';

/** Assinatura digitalizada da responsável técnica, publicada em public/. */
const OS_SIGNATURE_IMAGE = 'assinatura-responsavel.png';

/** Logo usada no cabeçalho do documento (a mesma de components/Logo.tsx). */
const OS_LOGO_IMAGE = 'ecoleva-logo-dark.png';

/** Remetente do e-mail. Igual ao de public/contact.php, salvo override no env. */
const OS_MAIL_FROM_DEFAULT = 'noreply@ecoleva.com';

/**
 * Segredo do link público de uma OS.
 *
 * 32 bytes de `random_bytes` — não é derivado do id, então conhecer uma OS não
 * ajuda a adivinhar a próxima, e revogar o link é trocar esta coluna.
 */
function osShareTokenNew(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * URL absoluta da raiz do site, com barra no fim.
 *
 * `SITE_BASE_URL` no env vence sempre. Sem ela, a URL é montada a partir do
 * host da requisição — que o cliente controla pelo cabeçalho Host. Para a
 * página em si isso é inofensivo (quem abriu já está no host que digitou), mas
 * o link vai também dentro de um e-mail que NÓS enviamos: um Host forjado
 * mandaria o destinatário para o servidor de outra pessoa. Daí a validação
 * estrita abaixo e a recomendação de fixar SITE_BASE_URL em produção.
 */
function osBaseUrl(): string
{
    $configured = apiSecret('SITE_BASE_URL');
    if ($configured !== '') {
        return rtrim($configured, '/') . '/';
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || preg_match('/^[A-Za-z0-9.\-]+(:[0-9]{1,5})?$/', $host) !== 1) {
        $host = 'localhost';
    }

    // apiBasePath() devolve a pasta /api/ do deploy ('/api/' ou '/sub/api/').
    // A raiz do site é o que vem antes dela.
    $apiPath = apiBasePath();
    $root = $apiPath === '/' ? '/' : substr($apiPath, 0, -4);
    if ($root === '' || $root === false) {
        $root = '/';
    }

    return (apiIsHttps() ? 'https' : 'http') . '://' . $host . $root;
}

/** Link público de uma OS — id e token juntos; um sem o outro não abre nada. */
function osShareUrl(int $id, string $token, ?string $baseUrl = null): string
{
    $baseUrl ??= osBaseUrl();

    return $baseUrl . 'api/os/view.php?id=' . $id . '&t=' . rawurlencode($token);
}

/**
 * Uma OS com os dados do cliente. `null` quando o id não existe.
 *
 * @return array<string,mixed>|null
 */
function osFindById(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('
        SELECT o.*, c.name AS client_name, c.email AS client_email, c.whatsapp AS client_whatsapp
          FROM service_orders o
          JOIN clients c ON c.id = o.client_id
         WHERE o.id = ?
         LIMIT 1
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

/**
 * A OS de um link público, se o token conferir.
 *
 * A comparação é `hash_equals` sobre o token guardado, e não um `WHERE
 * share_token = ?`: assim o tempo de resposta não varia com quantos caracteres
 * do token o visitante acertou. Linha sem token (nunca deveria acontecer depois
 * da migration 014) nunca abre.
 *
 * @return array<string,mixed>|null
 */
function osFindShared(PDO $db, int $id, string $token): ?array
{
    if ($id <= 0 || $token === '') {
        return null;
    }

    $os = osFindById($db, $id);
    $stored = is_array($os) ? (string) ($os['share_token'] ?? '') : '';

    if ($os === null || $stored === '' || !hash_equals($stored, $token)) {
        return null;
    }

    return $os;
}

/**
 * Uma linha de `service_orders` no formato que o dashboard consome.
 *
 * O `share_token` cru não vai junto: o que a tela precisa é o link pronto, e
 * devolver só a URL mantém um único lugar (aqui) montando o endereço.
 *
 * @param array<string,mixed> $row
 * @param array{open:bool,expires_at:?string,minutes_left:?int}|null $whatsappWindow
 * @return array<string,mixed>
 */
function osPresent(array $row, ?string $baseUrl = null, ?array $whatsappWindow = null): array
{
    $id = (int) $row['id'];
    $token = (string) ($row['share_token'] ?? '');

    return [
        'id' => $id,
        'client_id' => (int) $row['client_id'],
        'client_name' => (string) ($row['client_name'] ?? ''),
        'client_email' => $row['client_email'] ?? null,
        'client_whatsapp' => $row['client_whatsapp'] ?? null,
        'weight' => $row['weight'] ?? null,
        'collection_date' => $row['collection_date'] ?? null,
        'bags_count' => $row['bags_count'] ?? null,
        'containers_count' => $row['containers_count'] ?? null,
        'responsible' => $row['responsible'] ?? null,
        'signature_text' => (string) ($row['signature_text'] ?? 'Responsável Técnica - ECOLEVA'),
        'sent_at' => $row['sent_at'] ?? null,
        'sent_to' => $row['sent_to'] ?? null,
        'whatsapp_sent_at' => $row['whatsapp_sent_at'] ?? null,
        'whatsapp_sent_to' => $row['whatsapp_sent_to'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'share_url' => $token === '' ? null : osShareUrl($id, $token, $baseUrl),
        // Janela de 24h do WhatsApp deste cliente. É o que pinta o botão do robô
        // de verde na tela: dentro da janela o envio é texto livre, que a Meta
        // não cobra. `null` quando o cliente nunca escreveu para o número.
        'whatsapp_window' => $whatsappWindow,
    ];
}

function osEsc(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Número da OS no formato exibido em toda parte: #00042. */
function osNumber(int $id): string
{
    return str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

/** Data ISO do banco em dd/mm/aaaa. String vazia e nulo viram travessão. */
function osFormatDate(?string $isoDate): string
{
    $isoDate = trim((string) $isoDate);
    if ($isoDate === '' || str_starts_with($isoDate, '0000')) {
        return '—';
    }

    $date = date_create($isoDate);

    return $date === false ? '—' : $date->format('d/m/Y');
}

/** Valor de campo opcional, já escapado, com travessão quando vazio. */
function osField($value): string
{
    $value = trim((string) ($value ?? ''));

    return $value === '' ? '—' : osEsc($value);
}

/**
 * O documento da OS em HTML — o mesmo bloco na página pública e no e-mail.
 *
 * Estilo inline em tabelas, sem CSS externo e sem flexbox, porque o mesmo
 * markup precisa sobreviver ao Gmail e ao Outlook, que descartam `<style>` e
 * não implementam layout moderno.
 *
 * @param array<string,mixed> $os
 */
function osDocumentHtml(array $os, string $baseUrl): string
{
    $numero = osNumber((int) $os['id']);
    $cliente = osEsc((string) ($os['client_name'] ?? ''));
    $data = osFormatDate(isset($os['collection_date']) ? (string) $os['collection_date'] : null);
    $peso = osField($os['weight'] ?? null);
    $responsavel = osField($os['responsible'] ?? null);
    $sacos = osField($os['bags_count'] ?? null);
    $containers = osField($os['containers_count'] ?? null);
    $assinatura = osEsc((string) ($os['signature_text'] ?? 'Responsável Técnica - ECOLEVA'));
    $logo = osEsc($baseUrl . OS_LOGO_IMAGE);
    $rubrica = osEsc($baseUrl . OS_SIGNATURE_IMAGE);

    $linhas = '';
    $campos = [
        'Cliente' => $cliente,
        'Data da coleta' => $data,
        'Pesagem' => $peso,
        'Responsável pela coleta' => $responsavel,
        'Qtd. sacos' => $sacos,
        'Qtd. contêineres' => $containers,
    ];
    foreach ($campos as $rotulo => $valor) {
        $linhas .= '<tr>'
            . '<td style="padding:10px 0;color:#5A5A5A;font-size:13px;width:190px;vertical-align:top;">' . osEsc($rotulo) . '</td>'
            . '<td style="padding:10px 0;color:#242424;font-size:15px;font-weight:600;">' . $valor . '</td>'
            . '</tr>';
    }

    return <<<HTML
<div style="max-width:640px;margin:0 auto;background:#FFFFFF;color:#242424;font-family:'Montserrat',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;padding:32px;">
  <table role="presentation" style="width:100%;border-collapse:collapse;border-bottom:2px solid #ECF5FB;">
    <tr>
      <td style="padding-bottom:24px;vertical-align:middle;">
        <img src="{$logo}" alt="Ecoleva" width="150" style="display:block;width:150px;height:auto;border:0;">
      </td>
      <td style="padding-bottom:24px;text-align:right;vertical-align:middle;">
        <div style="font-size:20px;font-weight:700;color:#2D5934;text-transform:uppercase;letter-spacing:0.08em;">Ordem de Serviço</div>
        <div style="font-size:13px;color:#5A5A5A;margin-top:4px;">Nº {$numero}</div>
      </td>
    </tr>
  </table>

  <table role="presentation" style="width:100%;border-collapse:collapse;margin-top:24px;">
    {$linhas}
  </table>

  <table role="presentation" style="width:100%;border-collapse:collapse;margin-top:56px;">
    <tr>
      <td style="text-align:center;">
        <img src="{$rubrica}" alt="" width="220" style="display:block;width:220px;height:auto;margin:0 auto -14px;border:0;">
        <div style="width:280px;margin:0 auto;border-top:1px solid #242424;"></div>
        <div style="font-size:13px;font-weight:600;color:#242424;padding-top:8px;">{$assinatura}</div>
      </td>
    </tr>
  </table>
</div>
HTML;
}

/**
 * A página pública completa: o documento acima dentro de um HTML autônomo,
 * com botão de impressão que some no papel.
 *
 * @param array<string,mixed> $os
 */
function osViewPageHtml(array $os, string $baseUrl): string
{
    $numero = osNumber((int) $os['id']);
    $cliente = osEsc((string) ($os['client_name'] ?? ''));
    $documento = osDocumentHtml($os, $baseUrl);

    return <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Ordem de Serviço Nº {$numero} — {$cliente}</title>
<link rel="icon" href="{$baseUrl}ecoleta-icon.svg">
<style>
  body { margin:0; padding:24px 16px 64px; background:#ECF5FB; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; }
  .os-acoes { max-width:640px; margin:0 auto 16px; text-align:right; }
  .os-acoes button {
    font:inherit; font-weight:600; font-size:14px; cursor:pointer;
    background:#2D5934; color:#FFFFFF; border:0; border-radius:50px; padding:10px 22px;
  }
  .os-acoes button:hover { opacity:.9; }
  .os-folha { box-shadow:0 8px 32px rgba(13,31,15,.12); border-radius:10px; overflow:hidden; max-width:640px; margin:0 auto; }
  @media print {
    body { background:#FFFFFF; padding:0; }
    .os-acoes { display:none; }
    .os-folha { box-shadow:none; border-radius:0; }
  }
</style>
</head>
<body>
  <div class="os-acoes"><button type="button" onclick="window.print()">Imprimir / Salvar PDF</button></div>
  <div class="os-folha">{$documento}</div>
</body>
</html>
HTML;
}

/**
 * Corpo do e-mail: o documento mais uma chamada para o link público.
 *
 * @param array<string,mixed> $os
 */
function osEmailHtml(array $os, string $baseUrl, string $shareUrl): string
{
    $documento = osDocumentHtml($os, $baseUrl);
    $link = osEsc($shareUrl);

    return <<<HTML
<div style="background:#ECF5FB;padding:24px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
  {$documento}
  <div style="max-width:640px;margin:0 auto;padding:0 32px 32px;background:#FFFFFF;text-align:center;">
    <a href="{$link}" style="display:inline-block;background:#2D5934;color:#FFFFFF;text-decoration:none;font-weight:600;font-size:14px;border-radius:50px;padding:14px 32px;">Abrir e imprimir a OS</a>
  </div>
</div>
HTML;
}

/**
 * Versão em texto puro do e-mail — o que aparece em cliente que não renderiza
 * HTML, e o que evita que a mensagem seja pontuada como spam por ser só imagem.
 *
 * @param array<string,mixed> $os
 */
function osEmailText(array $os, string $shareUrl): string
{
    $linhas = [
        'ORDEM DE SERVIÇO Nº ' . osNumber((int) $os['id']),
        '',
        'Cliente: ' . trim((string) ($os['client_name'] ?? '')),
        'Data da coleta: ' . osFormatDate(isset($os['collection_date']) ? (string) $os['collection_date'] : null),
        'Pesagem: ' . osPlainField($os['weight'] ?? null),
        'Responsável pela coleta: ' . osPlainField($os['responsible'] ?? null),
        'Qtd. sacos: ' . osPlainField($os['bags_count'] ?? null),
        'Qtd. contêineres: ' . osPlainField($os['containers_count'] ?? null),
        '',
        'Abrir e imprimir: ' . $shareUrl,
        '',
        (string) ($os['signature_text'] ?? 'Responsável Técnica - ECOLEVA'),
    ];

    return implode("\n", $linhas);
}

/**
 * Texto da mensagem de WhatsApp.
 *
 * É o espelho de `osShareMessage()` em `lib/os-share.ts`, que monta a mesma
 * mensagem para o WhatsApp pessoal. Os dois caminhos entregam a mesma coisa ao
 * cliente; mexeu aqui, mexa lá.
 *
 * @param array<string,mixed> $os
 */
function osWhatsAppText(array $os, string $shareUrl): string
{
    $linhas = [
        '*Ordem de Serviço Nº ' . osNumber((int) $os['id']) . '* — Ecoleva',
        '',
        'Cliente: ' . osPlainField($os['client_name'] ?? null),
        'Data da coleta: ' . osFormatDate(isset($os['collection_date']) ? (string) $os['collection_date'] : null),
        'Pesagem: ' . osPlainField($os['weight'] ?? null),
        'Qtd. sacos: ' . osPlainField($os['bags_count'] ?? null),
        'Qtd. contêineres: ' . osPlainField($os['containers_count'] ?? null),
        'Responsável pela coleta: ' . osPlainField($os['responsible'] ?? null),
    ];

    if ($shareUrl !== '') {
        $linhas[] = '';
        $linhas[] = 'Abrir e imprimir: ' . $shareUrl;
    }

    return implode("\n", $linhas);
}

/** Igual a osField(), sem escapar — o texto puro não passa por HTML. */
function osPlainField($value): string
{
    $value = trim((string) ($value ?? ''));

    return $value === '' ? '-' : $value;
}

/**
 * Assunto do e-mail, já codificado para caber em um cabeçalho ASCII.
 *
 * @param array<string,mixed> $os
 */
function osEmailSubject(array $os): string
{
    $assunto = sprintf(
        'Ordem de Serviço Nº %s — %s',
        osNumber((int) $os['id']),
        trim((string) ($os['client_name'] ?? ''))
    );

    return '=?UTF-8?B?' . base64_encode($assunto) . '?=';
}

/**
 * Envia o e-mail multipart (texto + HTML).
 *
 * `MAIL_TRANSPORT=log` no env desliga o envio e registra o destinatário no log
 * do servidor. É o modo usado pela suíte de testes e por quem roda o painel na
 * máquina local, onde `mail()` cairia em um sendmail inexistente e o endpoint
 * responderia 500 sem que nada estivesse errado.
 */
function osSendMail(string $to, string $subject, string $html, string $text, ?string $replyTo = null): bool
{
    if (strcasecmp(apiSecret('MAIL_TRANSPORT'), 'log') === 0) {
        error_log(sprintf('MAIL_TRANSPORT=log: e-mail de OS para %s não foi enviado.', $to));
        return true;
    }

    $smtpHost = apiSecret('SMTP_HOST');
    
    // Se SMTP não estiver configurado, faz fallback pro mail()
    if (empty($smtpHost)) {
        $from = apiSecret('OS_MAIL_FROM');
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $from = OS_MAIL_FROM_DEFAULT;
        }

        $boundary = '----=_' . bin2hex(random_bytes(8));

        $headers = [
            'From: Ecoleva <' . $from . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $mime = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($text)
            . "\r\n--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($html)
            . "\r\n--{$boundary}--";

        return mail($to, $subject, $mime, implode("\r\n", $headers));
    }
    
    // Envio autenticado via PHPMailer / SMTP
    $vendorPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($vendorPath)) {
        error_log('PHPMailer não instalado. Rode: composer require phpmailer/phpmailer');
        return false;
    }
    require_once $vendorPath;
    
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = apiSecret('SMTP_USER');
        $mail->Password   = apiSecret('SMTP_PASS');
        
        $smtpSecure = strcasecmp(apiSecret('SMTP_SECURE'), 'true') === 0 ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPSecure = (int)apiSecret('SMTP_PORT') === 465 ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : $smtpSecure;
        $mail->Port       = (int)apiSecret('SMTP_PORT');

        $from = apiSecret('CONTACT_FROM_EMAIL');
        if (empty($from)) $from = apiSecret('SMTP_USER');
        if (empty($from)) $from = defined('OS_MAIL_FROM_DEFAULT') ? OS_MAIL_FROM_DEFAULT : 'contato@ecolevaeco.com';
        
        $fromName = apiSecret('CONTACT_FROM_NAME') ?: 'Ecoleva';

        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyTo);
        }

        // Assunto vem codificado do osEmailSubject. PHPMailer já codifica sozinho
        if (strpos($subject, '=?UTF-8?B?') === 0) {
            $subject = base64_decode(substr($subject, 10, -2));
        }
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log("PHPMailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
