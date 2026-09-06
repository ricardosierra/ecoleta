<?php
declare(strict_types=1);

/**
 * Peças puras do faturamento mensal — as que dá para testar sem rede e sem
 * banco.
 *
 * `cron/billing.php` fica só com o que é efeito colateral: conferir o segredo,
 * ler os clientes, falar com o Asaas, gravar em `invoices` e mandar o e-mail.
 * A decisão de QUANDO cobrar e o documento que o cliente recebe moram aqui.
 *
 * A separação nasceu de dois defeitos que a suíte não pegava porque nada
 * executava o arquivo do cron:
 *
 *  - o botão "Visualizar Boleto" interpolava `{$faturaUrl}`, uma variável que
 *    nunca foi atribuída — todo e-mail de fatura saiu com `href=""`;
 *  - os testes de data reimplementavam a regra do dia 30 dentro do próprio
 *    teste, então continuariam verdes se a regra do cron mudasse.
 */

require_once __DIR__ . '/security.php';

/** Dia em que as cobranças do mês seguinte são emitidas. */
const BILLING_ISSUE_DAY = 30;

/** Dias em que o que continua pendente é relembrado. */
const BILLING_REMINDER_DAYS = [3, 7];

/**
 * Hoje é dia de emitir as cobranças do mês que vem?
 *
 * Dia 30, ou o último dia do mês quando o mês não chega ao 30 (fevereiro). A
 * condição `< BILLING_ISSUE_DAY` é o que impede o dia 31 de disparar uma segunda
 * emissão nos meses de 31 dias.
 */
function billingShouldIssue(DateTimeInterface $today): bool
{
    $day = (int) $today->format('d');
    $lastDay = (int) $today->format('t');

    return $day === BILLING_ISSUE_DAY || ($day === $lastDay && $day < BILLING_ISSUE_DAY);
}

/** Hoje é dia de lembrete? */
function billingShouldRemind(DateTimeInterface $today): bool
{
    return in_array((int) $today->format('d'), BILLING_REMINDER_DAYS, true);
}

/**
 * Vencimento da cobrança de um cliente no mês seguinte, respeitando o `due_day`
 * do cadastro e o tamanho do mês (dia 31 em um mês de 30 vira dia 30).
 */
function billingDueDate(DateTimeInterface $today, int $dueDay): string
{
    $nextMonth = (new DateTimeImmutable($today->format('Y-m-d')))->modify('first day of next month');
    $lastDay = (int) $nextMonth->format('t');

    return sprintf(
        '%04d-%02d-%02d',
        (int) $nextMonth->format('Y'),
        (int) $nextMonth->format('m'),
        min(max($dueDay, 1), $lastDay)
    );
}

/** Escapa um valor para caber dentro do HTML do e-mail. */
function billingEsc($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Valor em reais no formato brasileiro, sem o "R$". */
function billingMoney($value): string
{
    return number_format((float) $value, 2, ',', '.');
}

/** Data ISO do banco em dd/mm/aaaa. */
function billingDate(string $isoDate): string
{
    $epoch = strtotime($isoDate);

    return $epoch === false ? $isoDate : date('d/m/Y', $epoch);
}

/**
 * O documento da fatura em HTML — o mesmo no e-mail de emissão e no lembrete.
 *
 * O que muda entre os dois é só o título, a cor dele e a frase de abertura;
 * Pix, boleto e rodapé são idênticos de propósito, para o cliente reconhecer a
 * mensagem. Antes eram dois heredocs copiados, e a cópia foi onde o link do
 * boleto se perdeu.
 *
 * Estilo inline em `<div>` e `<table>`, sem CSS externo, porque o mesmo markup
 * precisa sobreviver ao Gmail e ao Outlook.
 */
function billingInvoiceEmailHtml(
    string $titulo,
    string $corTitulo,
    string $aberturaHtml,
    string $clientName,
    string $vencimentoFormatado,
    string $pixPayload,
    string $faturaUrl
): string {
    $tituloEsc = billingEsc($titulo);
    $corEsc = billingEsc($corTitulo);
    $nome = billingEsc($clientName);
    $vencimento = billingEsc($vencimentoFormatado);
    $pix = billingEsc($pixPayload);

    // O QR vem de um serviço externo porque nem Gmail nem Outlook renderizam o
    // `encodedImage` (base64) que o Asaas devolve: os dois descartam
    // `src="data:image/png;base64,..."`.
    $qrUrl = billingEsc('https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($pixPayload));

    // Bloco do Pix só quando existe payload — um QR de string vazia é um
    // quadrado que não paga nada.
    $pixBloco = '';
    if ($pixPayload !== '') {
        $pixBloco = <<<HTML
      <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:24px;text-align:center;margin-bottom:24px;">
        <h3 style="margin:0 0 16px 0;color:#10B981;font-size:18px;">Pague via Pix</h3>
        <p style="margin:0 0 16px 0;color:#6B7280;font-size:14px;">Escaneie o QR Code abaixo com o aplicativo do seu banco:</p>

        <img src="{$qrUrl}" alt="QR Code Pix" width="200" height="200" style="width:200px;height:200px;margin:0 auto;display:block;" />

        <div style="margin-top:24px;">
          <p style="margin:0 0 8px 0;color:#6B7280;font-size:14px;">Ou copie o código abaixo (Pix Copia e Cola):</p>
          <div style="background:#FFFFFF;border:1px dashed #D1D5DB;border-radius:4px;padding:12px;font-family:monospace;font-size:12px;color:#374151;word-break:break-all;text-align:left;">
            {$pix}
          </div>
        </div>
      </div>
HTML;
    }

    // Sem link de fatura, o bloco do boleto inteiro sai fora — melhor não
    // mostrar botão nenhum do que um que não leva a lugar algum. Era exatamente
    // isso que acontecia antes, em todo e-mail.
    $boletoBloco = '';
    if ($faturaUrl !== '' && filter_var($faturaUrl, FILTER_VALIDATE_URL) !== false) {
        $link = billingEsc($faturaUrl);
        $boletoBloco = <<<HTML
      <div style="background:#FFFFFF;border:1px solid #E5E7EB;border-radius:8px;padding:24px;text-align:center;margin-bottom:8px;">
        <h3 style="margin:0 0 12px 0;color:#374151;font-size:16px;">Prefere pagar via Boleto?</h3>
        <p style="margin:0 0 20px 0;color:#6B7280;font-size:14px;">Acesse a fatura completa para gerar o seu boleto bancário:</p>
        <a href="{$link}" style="display:inline-block;background:#2D5934;color:#FFFFFF;text-decoration:none;font-weight:600;font-size:14px;border-radius:50px;padding:12px 32px;">Visualizar Boleto</a>
      </div>
HTML;
    }

    return <<<HTML
<div style="background:#F4F6F8;padding:40px 20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
  <div style="max-width:600px;margin:0 auto;background:#FFFFFF;border-radius:4px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05);">

    <div style="background:#2D5934;padding:32px;color:#FFFFFF;">
      <h2 style="margin:0;font-size:18px;font-weight:700;text-transform:uppercase;">ECOLEVA SOLUCOES AMBIENTAIS LTDA.</h2>
      <div style="font-size:14px;margin-top:8px;opacity:0.9;">57.772.812/0001-72</div>
    </div>

    <div style="padding:40px 32px;">
      <h1 style="margin:0 0 24px 0;font-size:22px;color:{$corEsc};text-align:center;border-bottom:1px solid #EEEEEE;padding-bottom:24px;">{$tituloEsc}</h1>

      <p style="font-size:15px;color:#333333;margin-bottom:16px;">Olá, <strong>{$nome}</strong></p>

      <p style="font-size:15px;color:#333333;line-height:1.6;margin-bottom:24px;">{$aberturaHtml}</p>

      <p style="font-size:13px;color:#B91C1C;background:#FEF2F2;border-left:4px solid #EF4444;padding:12px;border-radius:0 4px 4px 0;margin-bottom:32px;line-height:1.5;">
        <strong>⚠️ Atenção:</strong> O pagamento após o dia {$vencimento} acarretará na cobrança de juros e multa.
      </p>

{$pixBloco}
{$boletoBloco}
    </div>

    <div style="background:#F9FAFB;padding:24px;text-align:center;border-top:1px solid #EEEEEE;">
      <p style="margin:0 0 4px 0;font-size:12px;font-weight:700;color:#4B5563;">ECOLEVA SOLUCOES AMBIENTAIS LTDA.</p>
      <p style="margin:0 0 4px 0;font-size:12px;color:#6B7280;">57.772.812/0001-72</p>
      <p style="margin:0 0 4px 0;font-size:12px;color:#6B7280;">diretoria@econformidade.com.br</p>
      <p style="margin:0 0 4px 0;font-size:12px;color:#6B7280;">(21) 99152-9383</p>
      <p style="margin:0;font-size:12px;color:#2D5934;">RUA DR FRANCISCO DE SOUZA, 18, SALA 302, CENTRO<br>CEP: 28800000<br>Rio Bonito - RJ</p>
    </div>

  </div>
</div>
HTML;
}

/** Versão em texto puro — o que aparece em cliente que não renderiza HTML. */
function billingInvoiceEmailText(
    string $abertura,
    string $clientName,
    string $valorFormatado,
    string $vencimentoFormatado,
    string $pixPayload,
    string $faturaUrl
): string {
    $linhas = [
        sprintf('Olá, %s.', $clientName),
        '',
        sprintf('%s Valor: R$ %s. Vencimento: %s.', $abertura, $valorFormatado, $vencimentoFormatado),
    ];

    if ($pixPayload !== '') {
        $linhas[] = '';
        $linhas[] = 'Pix Copia e Cola: ' . $pixPayload;
    }

    if ($faturaUrl !== '') {
        $linhas[] = '';
        $linhas[] = 'Boleto: ' . $faturaUrl;
    }

    $linhas[] = '';
    $linhas[] = 'ECOLEVA SOLUCOES AMBIENTAIS LTDA. — 57.772.812/0001-72';

    return implode("\n", $linhas);
}

/**
 * O e-mail completo da cobrança recém-emitida.
 *
 * @return array{subject:string,html:string,text:string}
 */
function billingNewInvoiceEmail(
    string $clientName,
    $value,
    string $dueDateIso,
    string $pixPayload,
    string $faturaUrl
): array {
    $valor = billingMoney($value);
    $vencimento = billingDate($dueDateIso);

    return [
        'subject' => 'Sua Fatura Mensal - Ecoleva',
        'html' => billingInvoiceEmailHtml(
            'Sua Fatura Mensal',
            '#333333',
            sprintf(
                'A ECOLEVA SOLUCOES AMBIENTAIS LTDA. gerou uma cobrança para você, no valor de <strong>R$ %s</strong> com vencimento em <strong>%s</strong>.',
                billingEsc($valor),
                billingEsc($vencimento)
            ),
            $clientName,
            $vencimento,
            $pixPayload,
            $faturaUrl
        ),
        'text' => billingInvoiceEmailText(
            'A ECOLEVA gerou uma cobrança para você.',
            $clientName,
            $valor,
            $vencimento,
            $pixPayload,
            $faturaUrl
        ),
    ];
}

/**
 * O e-mail de lembrete de uma cobrança que continua pendente.
 *
 * @return array{subject:string,html:string,text:string}
 */
function billingReminderEmail(
    string $clientName,
    $value,
    string $dueDateIso,
    string $pixPayload,
    string $faturaUrl
): array {
    $valor = billingMoney($value);
    $vencimento = billingDate($dueDateIso);

    return [
        'subject' => 'Lembrete de Fatura - Ecoleva',
        'html' => billingInvoiceEmailHtml(
            'Lembrete de Vencimento',
            '#D32F2F',
            sprintf(
                'Este é um lembrete de que sua fatura no valor de <strong>R$ %s</strong> com vencimento em <strong>%s</strong> está pendente.',
                billingEsc($valor),
                billingEsc($vencimento)
            ),
            $clientName,
            $vencimento,
            $pixPayload,
            $faturaUrl
        ),
        'text' => billingInvoiceEmailText(
            'Sua fatura continua pendente.',
            $clientName,
            $valor,
            $vencimento,
            $pixPayload,
            $faturaUrl
        ),
    ];
}
