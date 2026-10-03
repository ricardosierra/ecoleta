<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/os/os_lib.php';

// Destinatário padrão do alerta. SECURITY_ALERT_EMAIL (constante do env.php ou
// variável de ambiente) o substitui sem mexer no código: a conta de quem
// administra a instalação pode mudar, e o alerta não deve depender de um
// endereço pessoal gravado aqui.
const SECURITY_ALERT_PRIMARY_EMAIL = 'sierra.csi@gmail.com';

/**
 * Para quem o alerta vai. Valor ausente ou que não é e-mail volta ao padrão:
 * configuração torta não pode calar o alerta.
 */
function apiSecurityAlertRecipient(): string
{
    $to = apiSecret('SECURITY_ALERT_EMAIL');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return SECURITY_ALERT_PRIMARY_EMAIL;
    }

    return $to;
}

/**
 * Assunto do e-mail. Vai direto para o cabeçalho, e o texto do evento carrega
 * login digitado por alguém: quebra de linha ali seria injeção de cabeçalho
 * (um `Bcc:` colado depois do `\r\n`).
 */
function apiSecurityAlertSubject(string $subject): string
{
    $oneLine = preg_replace('/[\r\n\x00]+/', ' ', $subject) ?? '';

    return '[ALERTA ECOLEVA] ' . $oneLine;
}

/**
 * Monta o HTML e o texto puro do alerta. Função pura: o que entra aqui sem
 * escapar (login, título do evento, user-agent) é escapado aqui, e é por estar
 * separada do envio que a suíte consegue ler o documento.
 *
 * O título do evento e o assunto contêm o login do usuário afetado ("A senha do
 * usuário '{login}' foi alterada"). Só a tabela de detalhes escapava; o <title>
 * e o <h2> recebiam o texto cru, e um login com `<a href>` chegava ao Gmail do
 * administrador como link clicável.
 *
 * @param array<string, mixed> $details Detalhes da ocorrência
 * @return array{html: string, text: string}
 */
function apiSecurityAlertDocument(
    string $subject,
    string $eventTitle,
    array $details,
    string $now,
    string $ip,
    string $userAgent
): array {
    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $detailsListHtml = '';
    $detailsListText = '';

    $fields = [
        'Evento' => $eventTitle,
        'Data/Hora' => $now . ' (Horário de Brasília)',
        'IP de Origem' => $ip,
        'User-Agent' => $userAgent,
    ];

    foreach ($details as $k => $v) {
        if ($v !== null && $v !== '') {
            $fields[$k] = (string) $v;
        }
    }

    foreach ($fields as $label => $val) {
        $detailsListHtml .= sprintf(
            '<tr><td style="padding: 8px 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 35%%;">%s</td><td style="padding: 8px 12px; color: #111827; border-bottom: 1px solid #e5e7eb;">%s</td></tr>',
            $escape((string) $label),
            $escape($val)
        );
        $detailsListText .= sprintf("%s: %s\n", $label, $val);
    }

    $subjectHtml = $escape($subject);
    $eventTitleHtml = $escape($eventTitle);
    $nowHtml = $escape($now);

    $html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>{$subjectHtml}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f3f4f6; margin: 0; padding: 24px; color: #1f2937;">
  <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e5e7eb; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
    <div style="background-color: #111827; padding: 20px 24px; border-bottom: 3px solid #10b981;">
      <h1 style="color: #ffffff; margin: 0; font-size: 18px; font-weight: 700; letter-spacing: 0.5px;">ALERTA DE SEGURANÇA — ECOLEVA</h1>
    </div>
    <div style="padding: 24px;">
      <h2 style="margin-top: 0; color: #111827; font-size: 16px;">{$eventTitleHtml}</h2>
      <p style="color: #4b5563; font-size: 14px; line-height: 1.5;">Uma atividade de segurança foi registrada no painel administrativo.</p>

      <table style="width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 13px; background-color: #f9fafb; border-radius: 8px; overflow: hidden;">
        <tbody>
          {$detailsListHtml}
        </tbody>
      </table>

      <div style="margin-top: 24px; padding: 12px 16px; background-color: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 4px; font-size: 12px; color: #92400e;">
        Se esta ação não foi autorizada por você, acesse imediatamente o painel ou o servidor para verificar a conta afetada.
      </div>
    </div>
    <div style="padding: 16px 24px; background-color: #f9fafb; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280; text-align: center;">
      Sistema de Monitoramento Ecoleva &bull; {$nowHtml}
    </div>
  </div>
</body>
</html>
HTML;

    $text = "ALERTA DE SEGURANÇA — ECOLEVA\n=================================\n\n";
    $text .= "{$eventTitle}\n\n";
    $text .= $detailsListText;
    $text .= "\nSe esta ação não foi autorizada por você, acesse imediatamente o sistema para averiguação.\n";

    return ['html' => $html, 'text' => $text];
}

/**
 * Envia um alerta de segurança imediato por e-mail para o administrador técnico
 * (destinatário em apiSecurityAlertRecipient()).
 *
 * @param string $subject Assunto da notificação
 * @param string $eventTitle Título legível do evento
 * @param array<string, mixed> $details Detalhes da ocorrência (usuário alvo, executor, IP, status, etc.)
 */
function apiSendSecurityAlert(string $subject, string $eventTitle, array $details): bool
{
    try {
        $now = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i:s');
        $ip = apiClientIp();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Não informado', 0, 255);

        $document = apiSecurityAlertDocument($subject, $eventTitle, $details, $now, $ip, $userAgent);

        return osSendMail(
            apiSecurityAlertRecipient(),
            apiSecurityAlertSubject($subject),
            $document['html'],
            $document['text']
        );
    } catch (\Throwable $e) {
        error_log('Falha ao enviar alerta de segurança: ' . $e->getMessage());
        return false;
    }
}
