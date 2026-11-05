<?php
declare(strict_types=1);

/**
 * Resposta automática do WhatsApp da Ecoleva.
 *
 * A decisão é local, no estilo do atendimento da Banlek: regras primeiro,
 * modelo só no que sobrou. Fatura e Pix continuam determinísticos. Ajuda,
 * saudação, cortesia, mídia e “sobre a empresa” também recebem resposta:
 * o cliente não fica sem retorno. Texto que nenhuma regra cobre só vai a um
 * modelo se `WHATSAPP_REPLY_API_KEY` estiver preenchida; sem chave, o
 * painel humano recebe a mensagem e o robô manda o menu.
 *
 *  - a trava de 6 horas só impede reenviar o Pix (código e QR). Dúvida,
 *    saudação, ajuda e o resto respondem sempre;
 *  - mensagem de outro robô fica sem resposta, para não virar loop;
 *  - a fatura casa o telefone EXATO (com e sem o nono dígito).
 */

require_once __DIR__ . '/../asaas_lib.php';
require_once __DIR__ . '/../whatsapp_lib.php';
require_once __DIR__ . '/../whatsapp_store.php';
require_once __DIR__ . '/WaReplyQuality.php';

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

/** Pedido explícito da imagem do QR Code. A intenção de fatura mora em `WaReplyQuality`. */
const WA_BOT_QR_IMAGE_PATTERN = '/(?<![a-z0-9])qr(?: ?code)?(?![a-z0-9])/';

/** Minúsculas, sem acento e com espaços colapsados: a forma em que as palavras-chave são procuradas. */
function waBotNormalizeText(string $texto): string
{
    return (new WaReplyQuality())->normalize($texto);
}

/**
 * A mensagem recebida é um pedido de fatura?
 *
 * Só TEXTO puro conta: legenda de foto ou de documento é mídia, e o corpo
 * "[imagem]" que o webhook grava no lugar dela jamais é pedido de nada.
 */
function waBotWantsInvoice(?string $type, ?string $body): bool
{
    return (new WaReplyQuality())->decide($type, $body)['lane'] === 'invoice';
}

/**
 * O que fazer com a mensagem recebida.
 *
 * Fatura ganha de ajuda e de “sobre”: “preciso da fatura” não vira texto
 * institucional. Cortesia e mídia viram texto de apoio, sem Pix.
 *
 * @return 'invoice'|'help'|'about'|'greeting'|''
 */
function waBotClassify(?string $type, ?string $body): string
{
    $decisao = (new WaReplyQuality())->decide($type, $body);
    if ($decisao['lane'] === 'invoice') {
        return 'invoice';
    }
    if ($decisao['lane'] === 'script') {
        return $decisao['script'];
    }
    if ($decisao['lane'] === 'model') {
        return 'model';
    }

    return '';
}

/** Texto fixo de ajuda, saudação, apresentação ou despedida. Sempre com a assinatura do robô. */
function waBotScriptedReply(string $intent): string
{
    return (new WaReplyQuality())->script($intent);
}

/** O cliente pediu a imagem do QR Code, e não só o código copia e cola? */
function waBotWantsQrImage(string $body): bool
{
    return preg_match(WA_BOT_QR_IMAGE_PATTERN, waBotNormalizeText($body)) === 1;
}

/**
 * O robô já respondeu nesta conversa dentro do intervalo?
 *
 * Só conta reenvio de Pix: mensagem NOSSA, sem usuário (`sent_by_user_id` nulo)
 * e com o aviso "Pix Copia e Cola" ou o rótulo da imagem do QR. Saudação, menu
 * e resposta do modelo não entram nessa conta.
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
    $stmt->execute([$conversationId, $desde, '%Pix Copia e Cola%', WA_BOT_IMAGE_LABEL]);

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
    $intent = waBotClassify($type, $body);
    if ($intent === '') {
        return;
    }

    if ($intent === 'model') {
        waBotReplyWithModel($db, $from, $body, $type, $deps);

        return;
    }

    if ($intent !== 'invoice') {
        waBotSendScripted($db, $from, $intent, $deps);

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
            waBotSendScripted($db, $from, 'no_invoice', $deps);

            return;
        }

        // O cliente da fatura só entra se a conversa ainda não tiver dono.
        $conversationId = waEnsureConversation($db, $from, ['client_id' => (int) $invoice['client_id']]);

        if ($conversationId !== null && waBotRecentlyReplied($db, $conversationId)) {
            waBotSendScripted($db, $from, 'pix_already', $deps);

            return;
        }

        $pix = $fetchPix((string) $invoice['asaas_payment_id']);

        if (empty($pix['payload'])) {
            waBotSendScripted($db, $from, 'pix_unavailable', $deps);

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
            "",
            "Avise se recebeu esse aviso.",
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
        waBotSendScripted($db, $from, 'pix_unavailable', $deps);
    }
}

/**
 * Manda o texto de ajuda, saudação ou apresentação. Sem a trava do Pix.
 *
 * @param array{send?:callable} $deps
 */
function waBotSendScripted(PDO $db, string $from, string $intent, array $deps): void
{
    $send = $deps['send'] ?? null;
    if ($send === null) {
        if (!waIsConfigured()) {
            return;
        }
        $send = 'waRequest';
    }

    try {
        $conversationId = waEnsureConversation($db, $from);

        $texto = waBotScriptedReply($intent);
        $resposta = $send([
            'messaging_product' => 'whatsapp',
            'to' => $from,
            'type' => 'text',
            'text' => ['body' => $texto],
        ]);

        if ($conversationId !== null) {
            waRecordMessage($db, $conversationId, [
                'wa_message_id' => waExtractSentMessageId($resposta),
                'direction' => 'outgoing',
                'type' => 'text',
                'status' => 'accepted',
                'body' => $texto,
                'raw_payload' => $resposta,
            ]);
        }
    } catch (Throwable $e) {
        error_log('Falha ao auto-responder ajuda da Ecoleva: ' . $e->getMessage());
    }
}

/**
 * Pergunta que nenhuma regra cobriu.
 *
 * Sem `complete` injetado e sem `WHATSAPP_REPLY_API_KEY`, não responde.
 * O roteiro vem de `WaReplyQuality`: empresa e faturas desta cliente, sem código Pix.
 *
 * @param array{send?:callable,complete?:callable} $deps
 */
function waBotReplyWithModel(PDO $db, string $from, string $body, string $type, array $deps): void
{
    if (strtolower(trim($type)) !== 'text') {
        return;
    }

    $body = trim($body);
    if ($body === '' || preg_match('/^\[[^\]]*\]$/u', $body) === 1) {
        return;
    }

    if (mb_strlen($body) > 500) {
        return;
    }

    $complete = $deps['complete'] ?? null;
    if ($complete === null) {
        if (apiSecret('WHATSAPP_REPLY_API_KEY') === '') {
            waBotSendScripted($db, $from, 'help', $deps);

            return;
        }
        $complete = 'waBotCompleteRemote';
    }

    $send = $deps['send'] ?? null;
    if ($send === null) {
        if (!waIsConfigured()) {
            return;
        }
        $send = 'waRequest';
    }

    try {
        $conversationId = waEnsureConversation($db, $from);
        $qualidade = new WaReplyQuality();
        $faturas = waBotClientInvoices($db, $from);
        $nome = $faturas === [] ? '' : trim((string) ($faturas[0]['client_name'] ?? ''));
        $roteiro = $qualidade->prompt($nome, $faturas, waBotClientExtra($db, $from));
        $gerado = trim((string) $complete($body, $roteiro));
        if ($gerado === '' || mb_strlen($gerado) > 1200) {
            waBotSendScripted($db, $from, 'help', $deps);

            return;
        }
        if (!str_contains($gerado, WA_BOT_SIGNATURE)) {
            $gerado .= "\n\n_" . WA_BOT_SIGNATURE . '._';
        }

        $resposta = $send([
            'messaging_product' => 'whatsapp',
            'to' => $from,
            'type' => 'text',
            'text' => ['body' => $gerado],
        ]);

        if ($conversationId !== null) {
            waRecordMessage($db, $conversationId, [
                'wa_message_id' => waExtractSentMessageId($resposta),
                'direction' => 'outgoing',
                'type' => 'text',
                'status' => 'accepted',
                'body' => $gerado,
                'raw_payload' => $resposta,
            ]);
        }
    } catch (Throwable $e) {
        error_log('Falha ao auto-responder com modelo: ' . $e->getMessage());
    }
}

/**
 * Chamada OpenAI-compatível. Provedor e modelo vêm do env; o padrão é um modelo pequeno.
 *
 * Falha fechada: timeout curto, sem repassar o corpo de erro ao cliente.
 */
/**
 * Faturas desta cliente para o prompt: abertas primeiro, depois pagas recentes.
 * Sem Pix. Quem não está no cadastro volta lista vazia.
 *
 * @return list<array<string,mixed>>
 */
function waBotClientInvoices(PDO $db, string $from): array
{
    $variantes = waPhoneVariants($from);
    if ($variantes === []) {
        return [];
    }

    $clientes = $db->query("SELECT id, name, whatsapp FROM clients WHERE whatsapp IS NOT NULL AND whatsapp <> ''");
    $ids = [];
    $nomes = [];
    foreach ($clientes ? $clientes->fetchAll() : [] as $linha) {
        if (in_array(normalizePhone((string) $linha['whatsapp']), $variantes, true)) {
            $ids[] = (int) $linha['id'];
            $nomes[(int) $linha['id']] = (string) $linha['name'];
        }
    }
    if ($ids === []) {
        return [];
    }

    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("
        SELECT client_id, value, due_date, status
          FROM invoices
         WHERE client_id IN ({$marcadores})
           AND status IN ('PENDING', 'OVERDUE', 'RECEIVED', 'CONFIRMED')
         ORDER BY CASE WHEN status IN ('PENDING', 'OVERDUE') THEN 0 ELSE 1 END,
                  due_date DESC, id DESC
         LIMIT 12
    ");
    $stmt->execute($ids);
    $linhas = [];
    foreach ($stmt->fetchAll() as $fatura) {
        $fatura['client_name'] = $nomes[(int) $fatura['client_id']] ?? '';
        $linhas[] = $fatura;
    }

    return $linhas;
}

/**
 * Telefones desta cliente no banco e um pedaço curto do que já foi falado.
 * Não lista telefone de outra cliente.
 */
function waBotClientExtra(PDO $db, string $from): string
{
    $variantes = waPhoneVariants($from);
    $blocos = ['Telefone desta conversa: ' . normalizePhone($from) . '.'];
    if ($variantes === []) {
        return implode("\n", $blocos);
    }

    $numeros = [];
    $clientes = $db->query("SELECT id, name, whatsapp, email FROM clients WHERE whatsapp IS NOT NULL AND whatsapp <> ''");
    $ids = [];
    foreach ($clientes ? $clientes->fetchAll() : [] as $linha) {
        $guardado = normalizePhone((string) $linha['whatsapp']);
        if (!in_array($guardado, $variantes, true)) {
            continue;
        }
        $ids[] = (int) $linha['id'];
        if ($guardado !== '') {
            $numeros['cadastro ' . (string) $linha['name'] . ' ' . $guardado] = true;
        }
        $email = trim((string) ($linha['email'] ?? ''));
        if ($email !== '') {
            $blocos[] = 'E-mail no cadastro: ' . $email . '.';
        }
    }

    if ($ids !== []) {
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $conversas = $db->prepare("SELECT DISTINCT phone FROM whatsapp_conversations WHERE client_id IN ({$marcadores})");
        $conversas->execute($ids);
        foreach ($conversas->fetchAll() as $linha) {
            $fone = normalizePhone((string) $linha['phone']);
            if ($fone !== '') {
                $numeros['conversa ' . $fone] = true;
            }
        }
        $os = $db->prepare("SELECT DISTINCT whatsapp_sent_to FROM service_orders WHERE client_id IN ({$marcadores}) AND whatsapp_sent_to IS NOT NULL AND whatsapp_sent_to <> ''");
        $os->execute($ids);
        foreach ($os->fetchAll() as $linha) {
            $fone = normalizePhone((string) $linha['whatsapp_sent_to']);
            if ($fone !== '') {
                $numeros['ordem de serviço ' . $fone] = true;
            }
        }
    }

    if ($numeros === []) {
        $blocos[] = 'Nenhum outro telefone desta cliente no cadastro.';
    } else {
        $blocos[] = 'Números desta cliente no banco:';
        foreach (array_keys($numeros) as $origem) {
            $blocos[] = '- ' . $origem;
        }
    }

    $conversa = $db->prepare('SELECT id FROM whatsapp_conversations WHERE phone IN (' . implode(',', array_fill(0, count($variantes), '?')) . ') ORDER BY id DESC LIMIT 1');
    $conversa->execute($variantes);
    $id = $conversa->fetchColumn();
    if ($id !== false) {
        $msgs = $db->prepare("SELECT direction, body FROM whatsapp_messages WHERE conversation_id = ? AND type = 'text' ORDER BY id DESC LIMIT 6");
        $msgs->execute([(int) $id]);
        $falas = array_reverse($msgs->fetchAll());
        $linhas = [];
        foreach ($falas as $fala) {
            $corpo = trim((string) $fala['body']);
            if ($corpo === '' || str_contains($corpo, WA_BOT_SIGNATURE)) {
                continue;
            }
            $linhas[] = ($fala['direction'] === 'incoming' ? 'Cliente' : 'Ecoleva') . ': ' . mb_substr($corpo, 0, 160);
        }
        if ($linhas !== []) {
            $blocos[] = "Últimas falas desta conversa:\n" . implode("\n", $linhas);
        }
    }

    return implode("\n", $blocos);
}

function waBotCompleteRemote(string $pergunta, string $roteiro = ''): string
{
    $chave = apiSecret('WHATSAPP_REPLY_API_KEY');
    if ($chave === '') {
        return '';
    }

    $url = apiSecret('WHATSAPP_REPLY_API_URL');
    if ($url === '') {
        $url = 'https://api.anthropic.com/v1/messages';
    }
    $modelo = apiSecret('WHATSAPP_REPLY_MODEL');
    if ($modelo === '') {
        $modelo = 'claude-haiku-4-5';
    }

    if ($roteiro === '') {
        $roteiro = (new WaReplyQuality())->prompt('', []);
    }

    // Haiku 4.5 recusa `temperature` (400 "Extra inputs are not permitted") e o
    // robô engolia o corpo vazio sem responder no WhatsApp.
    $payload = json_encode([
        'model' => $modelo,
        'max_tokens' => 300,
        'system' => $roteiro,
        'messages' => [
            ['role' => 'user', 'content' => $pergunta],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    if ($ch === false || $payload === false) {
        return '';
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'content-type: application/json',
            'x-api-key: ' . $chave,
            'anthropic-version: 2026-10-21',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
    ]);
    $cru = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($cru) || $cru === '') {
        error_log('WhatsApp Haiku: resposta vazia (HTTP ' . $http . ').');

        return '';
    }

    $json = json_decode($cru, true);
    if ($http !== 200) {
        $erro = is_array($json) ? (string) ($json['error']['message'] ?? '') : '';
        error_log('WhatsApp Haiku: HTTP ' . $http . ($erro !== '' ? ' — ' . $erro : ''));

        return '';
    }
    $texto = '';
    foreach ($json['content'] ?? [] as $bloco) {
        if (is_array($bloco) && ($bloco['type'] ?? '') === 'text' && is_string($bloco['text'] ?? null)) {
            $texto .= $bloco['text'];
        }
    }

    return trim($texto);
}
