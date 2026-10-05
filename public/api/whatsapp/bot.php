<?php
declare(strict_types=1);

/**
 * Resposta automática com fatura e Pix.
 *
 * Cada resposta é uma mensagem de cobrança com código Pix, mandada para quem
 * escreveu para o número da empresa. Por isso o robô é contido de propósito:
 *
 *  - só reage a mensagem de TEXTO com intenção clara de fatura (`waBotWantsInvoice`).
 *    Emoji, "ok", "obrigado", foto de comprovante e o rótulo "[imagem]" que o
 *    webhook grava no lugar da mídia nunca disparam nada;
 *  - responde no máximo uma vez por conversa a cada 6 horas, reconhecendo as
 *    próprias respostas pelo que já fica gravado em `whatsapp_messages`;
 *  - acha o cliente pelo telefone EXATO (com e sem o nono dígito), nunca pelos
 *    últimos dígitos, que casavam DDDs diferentes.
 */

require_once __DIR__ . '/../asaas_lib.php';
require_once __DIR__ . '/../whatsapp_lib.php';
require_once __DIR__ . '/../whatsapp_store.php';

/** No máximo uma resposta automática por conversa neste intervalo, em segundos. */
const WA_BOT_REPLY_INTERVAL_SECONDS = 21600;

/**
 * Assinatura do texto do robô. É o que `waBotRecentlyReplied()` procura para
 * reconhecer uma resposta automática anterior: a mesma constante escreve e lê,
 * então as duas pontas não se separam.
 */
const WA_BOT_SIGNATURE = 'Mensagem automática da Ecoleva';

/** Corpo gravado para a imagem do QR Code (também reconhecido como resposta do robô). */
const WA_BOT_IMAGE_LABEL = '[imagem QR Code Pix]';

/**
 * Palavras que pedem a fatura, já em minúsculas e sem acento (ver
 * `waBotNormalizeText`). Palavra inteira: "pixel" não é "pix".
 */
const WA_BOT_INTENT_PATTERN = '/(?<![a-z0-9])(?:faturas?|boletos?|pix|qr(?: ?code)?|segunda via|2 ?a? ?via|pagar|pagamentos?|cobrancas?|vencimentos?)(?![a-z0-9])/';

/**
 * Quem diz que já pagou ou manda comprovante não quer a fatura de novo, mesmo
 * citando "pix" ou "pagamento".
 */
const WA_BOT_ALREADY_PAID_PATTERN = '/(?<![a-z0-9])(?:comprovantes?|paguei|pagamos|quitei|quitamos|quitad[oa]s?)(?![a-z0-9])/';

/** Pedido explícito da imagem do QR Code. */
const WA_BOT_QR_IMAGE_PATTERN = '/(?<![a-z0-9])qr(?: ?code)?(?![a-z0-9])/';

/** Minúsculas, sem acento e com espaços colapsados: a forma em que as palavras-chave são procuradas. */
function waBotNormalizeText(string $texto): string
{
    $texto = mb_strtolower(trim($texto), 'UTF-8');
    $texto = strtr($texto, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ª' => 'a', 'º' => 'o', '°' => '',
    ]);

    return preg_replace('/\s+/u', ' ', $texto) ?? $texto;
}

/**
 * A mensagem recebida é um pedido de fatura?
 *
 * Só TEXTO puro conta: legenda de foto ou de documento é mídia, e o corpo
 * "[imagem]" que o webhook grava no lugar dela jamais é pedido de nada.
 */
function waBotWantsInvoice(?string $type, ?string $body): bool
{
    if (strtolower(trim((string) $type)) !== 'text') {
        return false;
    }

    $body = trim((string) $body);
    if ($body === '' || preg_match('/^\[[^\]]*\]$/u', $body) === 1) {
        return false;
    }

    $texto = waBotNormalizeText($body);

    if (preg_match(WA_BOT_ALREADY_PAID_PATTERN, $texto) === 1) {
        return false;
    }

    return preg_match(WA_BOT_INTENT_PATTERN, $texto) === 1;
}

/** O cliente pediu a imagem do QR Code, e não só o código copia e cola? */
function waBotWantsQrImage(string $body): bool
{
    return preg_match(WA_BOT_QR_IMAGE_PATTERN, waBotNormalizeText($body)) === 1;
}

/**
 * O robô já respondeu nesta conversa dentro do intervalo?
 *
 * Resposta automática é mensagem NOSSA, sem usuário (`sent_by_user_id` nulo:
 * atendente sempre grava o próprio id) e com a assinatura do robô ou o rótulo da
 * imagem. A consulta é sobre o que já fica gravado, sem tabela nem coluna nova.
 *
 * Não é atômico: duas mensagens simultâneas podem passar juntas pela checagem
 * antes de a primeira resposta ser gravada. O custo é uma resposta repetida, e
 * o intervalo de 6 horas faz o resto.
 */
function waBotRecentlyReplied(PDO $db, int $conversationId, ?string $nowUtc = null): bool
{
    $desde = gmdate('Y-m-d H:i:s', strtotime(($nowUtc ?? waNow()) . ' UTC') - WA_BOT_REPLY_INTERVAL_SECONDS);

    $stmt = $db->prepare("
        SELECT 1
          FROM whatsapp_messages
         WHERE conversation_id = ?
           AND direction = 'outgoing'
           AND sent_by_user_id IS NULL
           AND message_at >= ?
           AND (body LIKE ? OR body = ?)
         LIMIT 1
    ");
    $stmt->execute([$conversationId, $desde, '%' . WA_BOT_SIGNATURE . '%', WA_BOT_IMAGE_LABEL]);

    return $stmt->fetchColumn() !== false;
}

/**
 * A fatura em aberto (pendente ou vencida) mais antiga de quem escreveu, com o
 * nome do cliente.
 *
 * O telefone é comparado NORMALIZADO e por igualdade, nas duas variantes do
 * nono dígito: o wa_id da Meta pode ter 12 dígitos e o cadastro 13, ou o
 * contrário. O cadastro guarda o telefone como o operador digitou (com máscara,
 * com DDI), então a comparação é feita em PHP, como em `waFindClientIdByPhone`.
 *
 * @return array<string,mixed>|null
 */
function waBotFindInvoice(PDO $db, string $from): ?array
{
    $variantes = waPhoneVariants($from);
    if ($variantes === []) {
        return null;
    }

    $clientes = $db->query("SELECT id, whatsapp FROM clients WHERE whatsapp IS NOT NULL AND whatsapp <> ''");
    $ids = [];
    foreach ($clientes ? $clientes->fetchAll() : [] as $linha) {
        if (in_array(normalizePhone((string) $linha['whatsapp']), $variantes, true)) {
            $ids[] = (int) $linha['id'];
        }
    }

    if ($ids === []) {
        return null;
    }

    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("
        SELECT i.*, c.name AS client_name
          FROM invoices i
          JOIN clients c ON c.id = i.client_id
         WHERE i.client_id IN ({$marcadores})
           AND i.status IN ('PENDING', 'OVERDUE')
         ORDER BY i.due_date ASC, i.id ASC
         LIMIT 1
    ");
    $stmt->execute($ids);
    $fatura = $stmt->fetch();

    return is_array($fatura) ? $fatura : null;
}

/**
 * Sobe o PNG do QR Code para a Meta e devolve o id da mídia.
 *
 * O arquivo temporário some SEMPRE, inclusive quando o upload falha: antes ele
 * ficava em /tmp com um QR de cobrança dentro.
 */
function waBotUploadQrImage(string $encodedImage, callable $upload): string
{
    $png = base64_decode((string) preg_replace('#^data:image/\w+;base64,#i', '', $encodedImage), true);
    if ($png === false || $png === '') {
        throw new RuntimeException('Imagem do QR Code Pix inválida.');
    }

    $caminho = tempnam(sys_get_temp_dir(), 'pix_');
    if ($caminho === false) {
        throw new RuntimeException('Não consegui criar o arquivo temporário do QR Code Pix.');
    }

    try {
        file_put_contents($caminho, $png);

        return $upload($caminho, 'image/png', 'qrcode-pix.png');
    } finally {
        @unlink($caminho);
    }
}

/**
 * Responde com a fatura e o Pix quando a mensagem recebida pede isso.
 *
 * @param string $type tipo da mensagem da Meta (`text`, `image`, `audio`...)
 * @param array{pix?:callable,send?:callable,upload?:callable} $deps costuras para a
 *        suíte de testes; em produção ficam vazias e valem Asaas e Meta de verdade
 */
function waAutoReplyWithPix(PDO $db, string $from, string $body, string $type, array $deps = []): void
{
    if (!waBotWantsInvoice($type, $body)) {
        return;
    }

    $send = $deps['send'] ?? null;
    if ($send === null) {
        // Robô desligado ou sem credenciais: nem consulta o Asaas à toa.
        if (!waIsConfigured()) {
            return;
        }
        $send = 'waRequest';
    }
    $fetchPix = $deps['pix'] ?? 'asaasGetPixQrCode';
    $upload = $deps['upload'] ?? 'waUploadMedia';

    try {
        $invoice = waBotFindInvoice($db, $from);
        if ($invoice === null) {
            return; // Não tem fatura em aberto
        }

        // O cliente da fatura só entra se a conversa ainda não tiver dono.
        $conversationId = waEnsureConversation($db, $from, ['client_id' => (int) $invoice['client_id']]);

        if ($conversationId !== null && waBotRecentlyReplied($db, $conversationId)) {
            return;
        }

        $pix = $fetchPix((string) $invoice['asaas_payment_id']);

        if (empty($pix['payload'])) {
            return;
        }

        $enviar = static function (array $payload, string $tipo, string $corpo) use ($db, $send, $conversationId): void {
            $resposta = $send($payload);
            if ($conversationId !== null) {
                waRecordMessage($db, $conversationId, [
                    'wa_message_id' => waExtractSentMessageId($resposta),
                    'direction' => 'outgoing',
                    'type' => $tipo,
                    'status' => 'accepted',
                    'body' => $corpo,
                    'raw_payload' => $resposta,
                ]);
            }
        };

        // QR Code impresso/imagem: apenas se o cliente pedir explicitamente
        $pediuQrCode = waBotWantsQrImage($body);

        if ($pediuQrCode && !empty($pix['encodedImage'])) {
            // A imagem é um bônus: se falhar, o texto com o Pix copia e cola
            // (que é o que paga a fatura) ainda precisa chegar.
            try {
                $mediaId = waBotUploadQrImage((string) $pix['encodedImage'], $upload);

                $enviar([
                    'messaging_product' => 'whatsapp',
                    'to' => $from,
                    'type' => 'image',
                    'image' => [
                        'id' => $mediaId,
                        'caption' => 'Aqui está a imagem do QR Code para o pagamento da sua fatura!',
                    ],
                ], 'image', WA_BOT_IMAGE_LABEL);
            } catch (Throwable $e) {
                error_log('Falha ao enviar a imagem do QR Code Pix: ' . $e->getMessage());
            }
        }

        // Mensagem de cobrança com dados da fatura e instrução do Pix Copia e Cola direto
        $valor = number_format((float) ($invoice['value'] ?? 0), 2, ',', '.');
        $vencimento = (string) $invoice['due_date'];
        $venc = date('d/m/Y', strtotime($vencimento));
        $hoje = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $vencida = $invoice['status'] === 'OVERDUE' || $vencimento < $hoje;
        $nome = trim((string) ($invoice['client_name'] ?? ''));

        $linhas = [
            $nome !== '' ? "Olá, {$nome}." : "Olá.",
            "",
            $vencida
                ? "Sua fatura da Ecoleva no valor de *R$ {$valor}* venceu em *{$venc}*."
                : "Sua fatura da Ecoleva no valor de *R$ {$valor}* vence em *{$venc}*.",
            "",
            "Pix Copia e Cola:",
            $pix['payload'],
        ];

        if (!$pediuQrCode) {
            $linhas[] = "";
            $linhas[] = "_(Caso prefira a imagem do QR Code para escanear, basta responder *QR Code*.)_";
        }

        $linhas[] = "";
        $linhas[] = "Caso precise, envie WhatsApp para (21) 99152-9383.";
        $linhas[] = "";
        $linhas[] = '_' . WA_BOT_SIGNATURE . '._';

        $avisoTexto = implode("\n", $linhas);

        $enviar([
            'messaging_product' => 'whatsapp',
            'to' => $from,
            'type' => 'text',
            'text' => [
                'body' => $avisoTexto
            ]
        ], 'text', $avisoTexto);

        // Mensagem com o código Pix puro para o cliente copiar com um toque no celular
        $enviar([
            'messaging_product' => 'whatsapp',
            'to' => $from,
            'type' => 'text',
            'text' => [
                'body' => $pix['payload']
            ]
        ], 'text', (string) $pix['payload']);

    } catch (Throwable $e) {
        error_log('Falha ao auto-responder cobrança / Pix: ' . $e->getMessage());
    }
}
