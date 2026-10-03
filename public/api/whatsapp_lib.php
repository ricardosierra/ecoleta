<?php
declare(strict_types=1);

/**
 * Cliente da WhatsApp Cloud API (Meta Graph) — o "WhatsApp do robô".
 *
 * É o caminho em que o servidor envia sozinho, sem abrir o aplicativo de
 * ninguém. O outro caminho, o "WhatsApp pessoal", não passa por aqui: é só um
 * link `wa.me` montado no navegador (`lib/os-share.ts`).
 *
 * Credenciais em env.php, geradas pelo deploy a partir do .env:
 *   WHATSAPP_PHONE_ID      — id do número remetente (não é o telefone)
 *   WHATSAPP_ACCESS_TOKEN  — token permanente do app da Meta
 *   WHATSAPP_OS_TEMPLATE   — opcional; ver waSendText() abaixo
 */

require_once __DIR__ . '/security.php';

/** Versão da Graph API. Fixa: a Meta muda o contrato entre versões. */
const WHATSAPP_API_VERSION = 'v21.0';

/**
 * Erros da Meta que significam "a janela de 24h fechou".
 *
 * Fora dessa janela a Cloud API só aceita template aprovado. Não é falha de
 * configuração nem de rede — é a regra da plataforma, e a tela precisa
 * distinguir isso para oferecer o WhatsApp pessoal como saída.
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes
 */
const WHATSAPP_ERROR_OUTSIDE_WINDOW = [131047, 131026, 470];

/**
 * Erro de credencial da Meta (código 190, OAuthException).
 *
 * Na prática significa uma coisa só: `WHATSAPP_ACCESS_TOKEN` venceu. O token que
 * o painel da Meta oferece na primeira tela é temporário (24h) — quem sustenta o
 * robô é um token de Usuário do Sistema, gerado no Business Manager, sem data de
 * validade. A mensagem crua da Meta ("Authentication Error") não diz nada disso
 * a quem apertou o botão, então trocamos por uma que diz o que fazer.
 */
const WHATSAPP_ERROR_BAD_TOKEN = 190;

final class WhatsAppApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $apiCode = 0,
        public readonly bool $outsideWindow = false
    ) {
        parent::__construct($message);
    }
}

/**
 * `true` quando o robô pode enviar: credenciais presentes e transporte ligado.
 *
 * `WHATSAPP_TRANSPORT=off` desliga o disparo sem apagar as credenciais do
 * servidor. É o que a suíte de testes usa — sem essa chave, uma máquina de
 * desenvolvimento com o env.php de produção mandaria mensagens de verdade,
 * para números de verdade, a cada `npm run test:php`.
 */
function waIsConfigured(): bool
{
    if (strcasecmp(apiSecret('WHATSAPP_TRANSPORT'), 'off') === 0) {
        return false;
    }

    return apiSecret('WHATSAPP_PHONE_ID') !== '' && apiSecret('WHATSAPP_ACCESS_TOKEN') !== '';
}

/**
 * POST autenticado no endpoint de mensagens do número configurado.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 * @throws WhatsAppApiException
 */
function waRequest(array $payload): array
{
    $phoneId = apiSecret('WHATSAPP_PHONE_ID');
    $token = apiSecret('WHATSAPP_ACCESS_TOKEN');

    if ($phoneId === '' || $token === '') {
        throw new WhatsAppApiException('WhatsApp do robô não está configurado no servidor.');
    }

    $url = sprintf(
        'https://graph.facebook.com/%s/%s/messages',
        WHATSAPP_API_VERSION,
        rawurlencode($phoneId)
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'User-Agent: EcoletaApp/1.0',
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new WhatsAppApiException('Erro na comunicação com o WhatsApp: ' . $curlError);
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    if ($status >= 400) {
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $code = (int) ($error['code'] ?? 0);
        $mensagem = (string) (
            $error['error_user_msg']
            ?? $error['message']
            ?? 'O WhatsApp recusou a mensagem.'
        );

        // O token vazaria no log se a mensagem da Meta ecoasse a requisição.
        error_log(sprintf('WhatsApp Cloud API respondeu %d (code %d): %s', $status, $code, $mensagem));

        if ($code === WHATSAPP_ERROR_BAD_TOKEN) {
            $mensagem = 'O token do WhatsApp expirou ou foi revogado. '
                . 'Gere um token permanente de Usuário do Sistema no Business Manager da Meta '
                . 'e atualize WHATSAPP_ACCESS_TOKEN no .env.';
        }

        throw new WhatsAppApiException(
            $mensagem,
            $code,
            in_array($code, WHATSAPP_ERROR_OUTSIDE_WINDOW, true)
        );
    }

    return $decoded;
}

/**
 * Manda a mensagem para um número já normalizado (só dígitos, com DDI).
 *
 * Quem decide o formato é a JANELA DE 24 HORAS, lida do nosso banco
 * (`whatsapp_conversations.service_window_expires_at`, alimentada pelo
 * webhook):
 *
 *  - janela ABERTA — o cliente escreveu para nós nas últimas 24h — manda texto
 *    livre. É o caminho que a Meta não cobra e que não depende de template
 *    aprovado. Este é o "deve tentar": chegou mensagem, a janela abriu, tenta.
 *
 *  - janela FECHADA — só template aprovado (`WHATSAPP_OS_TEMPLATE`), que a Meta
 *    entrega a qualquer momento e cobra. Os parâmetros do corpo vão na ordem em
 *    que o template os declara: {{1}} cliente, {{2}} número da OS, {{3}} link.
 *    Sem template configurado, nem tenta: a API recusaria de qualquer jeito, e
 *    o erro sobe marcado com `outsideWindow` para a tela oferecer o WhatsApp
 *    pessoal como saída.
 *
 * A janela do nosso banco é uma estimativa otimista — quem tem a palavra final
 * é a Meta. Por isso o texto livre ainda pode voltar com erro 131047, tratado
 * pelo mesmo `outsideWindow`.
 *
 * @return array<string,mixed> resposta crua da Graph API
 * @throws WhatsAppApiException
 */
function waSendOsMessage(
    string $to,
    string $clientName,
    string $osNumber,
    string $shareUrl,
    string $texto,
    bool $windowOpen
): array {
    $to = preg_replace('/\D+/', '', $to) ?? '';
    if ($to === '') {
        throw new WhatsAppApiException('Cliente sem número de WhatsApp cadastrado.');
    }

    if ($windowOpen) {
        return waRequest([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => true, 'body' => $texto],
        ]);
    }

    $template = apiSecret('WHATSAPP_OS_TEMPLATE');

    if ($template === '') {
        throw new WhatsAppApiException(
            'O cliente não escreve para este número há mais de 24 horas — fora da janela, a Meta só entrega template aprovado.',
            0,
            true
        );
    }

    $language = apiSecret('WHATSAPP_OS_TEMPLATE_LANG');

    return waRequest([
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'template',
        'template' => [
            'name' => $template,
            'language' => ['code' => $language !== '' ? $language : 'pt_BR'],
            'components' => [[
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $clientName],
                    ['type' => 'text', 'text' => $osNumber],
                    ['type' => 'text', 'text' => $shareUrl],
                ],
            ]],
        ],
    ]);
}

/**
 * O `wamid` da mensagem aceita, que os eventos `statuses` do webhook usam
 * depois para dizer se ela foi entregue e lida.
 *
 * @param array<string,mixed> $response
 */
function waExtractSentMessageId(array $response): ?string
{
    $id = $response['messages'][0]['id'] ?? null;

    return is_string($id) && $id !== '' ? $id : null;
}

/**
 * Separa um data URI (`data:audio/webm;codecs=opus;base64,AAAA...`) em tipo e
 * conteúdo. Devolve `null` quando o texto não é um data URI em base64.
 *
 * O tipo volta COM os parâmetros (`audio/webm;codecs=opus`): é o que o Chrome
 * grava no gravador de voz, e uma regex `data:([^;]+);base64,` não casava com
 * isso, de modo que um arquivo perfeito virava "base64 corrompido". O parse é
 * por posição, sem regex sobre o conteúdo, que chega a vários megabytes.
 *
 * @return array{mime:string,data:string}|null
 */
function waParseDataUri(string $value): ?array
{
    if (!str_starts_with($value, 'data:')) {
        return null;
    }

    $virgula = strpos($value, ',');
    // O cabeçalho é curto. Uma vírgula longe demais quer dizer que isto é só um
    // base64 que por acaso começa com "data:".
    if ($virgula === false || $virgula > 255) {
        return null;
    }

    $partes = array_map('trim', explode(';', substr($value, 5, $virgula - 5)));
    if (strcasecmp((string) end($partes), 'base64') !== 0) {
        return null;
    }
    array_pop($partes);

    return [
        'mime' => implode(';', $partes),
        'data' => substr($value, $virgula + 1),
    ];
}

/**
 * Formatos de áudio que a Meta aceita no envio, por tipo base.
 *
 * `audio/ogg` só vale com codec opus, e o navegador (ou o arquivo) nem sempre
 * diz qual é; o upload manda `codecs=opus` e, se for vorbis, é a Meta quem recusa
 * com a mensagem dela. WebM, que é o que o Chrome grava, não entra.
 */
const WA_AUDIO_MIMES = ['audio/aac', 'audio/amr', 'audio/mpeg', 'audio/mp4', 'audio/ogg'];

/** Nomes alternativos que navegadores e `finfo` usam para os mesmos formatos. */
const WA_MIME_ALIASES = [
    'audio/mp3' => 'audio/mpeg',
    'audio/x-mpeg' => 'audio/mpeg',
    'audio/x-m4a' => 'audio/mp4',
    'audio/m4a' => 'audio/mp4',
    'audio/x-aac' => 'audio/aac',
    'application/ogg' => 'audio/ogg',
];

/** Tipo base, em minúsculas, sem parâmetros e sem apelidos: `Audio/MP3; x=y` vira `audio/mpeg`. */
function waBaseMime(string $mime): string
{
    $base = strtolower(trim(explode(';', $mime)[0]));

    return WA_MIME_ALIASES[$base] ?? $base;
}

/**
 * O áudio nesse formato não segue? Devolve a mensagem que explica o que a Meta
 * aceita, ou `null` quando o formato serve.
 */
function waAudioFormatError(string $mime): ?string
{
    $base = waBaseMime($mime);

    if (in_array($base, WA_AUDIO_MIMES, true)) {
        return null;
    }

    $recebido = $base !== '' ? $base : 'desconhecido';

    return "O WhatsApp não aceita áudio no formato {$recebido}. "
        . 'Os formatos aceitos são ogg com codec opus, mp3, aac, amr e mp4. '
        . 'Converta o arquivo antes de enviar.';
}

/**
 * O tipo que vai no upload: sem parâmetros, e `audio/ogg` sempre com opus (o
 * único ogg que a Meta aceita).
 */
function waMediaUploadMime(string $mime): string
{
    $base = waBaseMime($mime);

    return $base === 'audio/ogg' ? 'audio/ogg; codecs=opus' : $base;
}

/**
 * Opções do cURL do upload de mídia.
 *
 * Separadas da chamada para a suíte poder conferir o que não dá para testar sem
 * rede: o upload TEM prazo. Sem `CURLOPT_TIMEOUT`, uma Meta que aceita a conexão
 * e não responde deixava a requisição do painel (e o webhook, no caso do robô)
 * pendurada até o servidor derrubar o processo.
 *
 * @return array<int,mixed>
 */
function waUploadMediaCurlOptions(string $filePath, string $mimeType, string $token, ?string $filename = null): array
{
    return [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($filePath, $mimeType, $filename ?? basename($filePath)),
            'messaging_product' => 'whatsapp',
            'type' => $mimeType,
        ],
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        // Mídia chega a 16 MB: mais folga que o envio de texto, mas com limite.
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
}

/**
 * Faz upload de mídia para a API do WhatsApp.
 * Retorna o ID da mídia para ser usado no envio.
 */
function waUploadMedia(string $filePath, string $mimeType, ?string $filename = null): string
{
    $phoneId = apiSecret('WHATSAPP_PHONE_ID');
    $token = apiSecret('WHATSAPP_ACCESS_TOKEN');

    if ($phoneId === '' || $token === '') {
        throw new RuntimeException('WhatsApp não configurado (Phone ID ou Token).');
    }

    $version = WHATSAPP_API_VERSION;
    $ch = curl_init("https://graph.facebook.com/{$version}/" . rawurlencode($phoneId) . '/media');
    curl_setopt_array($ch, waUploadMediaCurlOptions($filePath, $mimeType, $token, $filename));

    $response = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Erro na comunicação com o WhatsApp ao subir a mídia: ' . $curlError);
    }

    $decoded = json_decode((string) $response, true);
    $decoded = is_array($decoded) ? $decoded : [];

    if ($statusCode !== 200) {
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $detalhe = trim((string) ($error['error_user_msg'] ?? $error['message'] ?? ''));

        // O token vazaria no log se a mensagem da Meta ecoasse a requisição.
        error_log(sprintf('WhatsApp Cloud API respondeu %d ao subir mídia: %s', $statusCode, $detalhe));

        throw new RuntimeException('Falha ao subir mídia: ' . ($detalhe !== '' ? $detalhe : mb_substr((string) $response, 0, 300)));
    }

    return (string) ($decoded['id'] ?? '');
}

// ── Lista de templates (a tela "Retomar com template") ──────────────────────

/**
 * O que o próprio código sabe sobre os templates que ele envia sozinho.
 *
 * `waSendOsMessage()` manda cliente, número da OS e link, nessa ordem, e a
 * cobrança manda cliente, valor, vencimento e link. É por esse conhecimento, e
 * não por adivinhação sobre o texto, que a tela pré-preenche o nome do cliente
 * e rotula cada variável. Template que a Meta devolve e o código não conhece
 * fica sem rótulo e sem pré-preenchimento: a pessoa preenche tudo.
 *
 * @return array<string, array{labels:list<string>,kinds:list<string>,language:string}>
 */
function waKnownTemplates(): array
{
    $conhecidos = [];

    $os = apiSecret('WHATSAPP_OS_TEMPLATE');
    if ($os !== '') {
        $conhecidos[$os] = [
            'labels' => ['Nome do Cliente', 'Número da OS', 'Link da OS'],
            'kinds' => ['client_name', 'os_number', 'os_link'],
            'language' => apiSecret('WHATSAPP_OS_TEMPLATE_LANG') ?: 'pt_BR',
        ];
    }

    $cobranca = apiSecret('WHATSAPP_BILLING_TEMPLATE');
    if ($cobranca !== '') {
        $conhecidos[$cobranca] = [
            'labels' => ['Nome do Cliente', 'Valor (R$)', 'Vencimento', 'Link da Fatura'],
            'kinds' => ['client_name', 'amount', 'due_date', 'invoice_link'],
            'language' => apiSecret('WHATSAPP_BILLING_TEMPLATE_LANG') ?: 'pt_BR',
        ];
    }

    return $conhecidos;
}

/**
 * Templates configurados no servidor, mostrados quando a Meta não pôde ser
 * consultada.
 *
 * O status é `UNVERIFIED`, nunca `APPROVED`: sem a resposta da Meta não há como
 * saber se o template existe ou foi aprovado, e a tela não pode afirmar isso. O
 * texto também não é inventado: sem ele, a prévia fica em branco. Template sem
 * nome configurado não aparece (antes o painel inventava `ecoleva_*`).
 *
 * @return list<array<string,mixed>>
 */
function waConfiguredTemplates(): array
{
    $lista = [];

    foreach (waKnownTemplates() as $nome => $conhecido) {
        $lista[] = [
            'name' => $nome,
            'language' => $conhecido['language'],
            'category' => 'UTILITY',
            'status' => 'UNVERIFIED',
            'body_text' => '',
            'params_count' => count($conhecido['labels']),
            'param_labels' => $conhecido['labels'],
            'param_kinds' => $conhecido['kinds'],
        ];
    }

    return $lista;
}

/**
 * Lê a resposta crua da Meta à lista de templates.
 *
 * Devolve TODOS os templates com o status REAL (aprovado, pendente, rejeitado,
 * pausado...): esconder os não aprovados fazia a lista parecer vazia e cair num
 * substituto que se dizia aprovado. Quando a consulta falha, devolve o erro em
 * português (o texto original da Meta fica em `error_detail`).
 *
 * @param string|false $body corpo da resposta, ou `false` se a conexão falhou
 * @return array{templates:list<array<string,mixed>>,error:?string,error_detail:?string}
 */
function waParseTemplatesResponse(int $httpStatus, $body, string $curlError = ''): array
{
    $vazio = ['templates' => [], 'error' => null, 'error_detail' => null];

    if ($body === false) {
        return [
            'error' => 'Não foi possível falar com a Meta para consultar os templates. Confira a conexão do servidor e tente de novo.',
            'error_detail' => $curlError !== '' ? $curlError : null,
        ] + $vazio;
    }

    $decodificado = json_decode((string) $body, true);
    $decodificado = is_array($decodificado) ? $decodificado : [];

    if ($httpStatus !== 200) {
        $erro = is_array($decodificado['error'] ?? null) ? $decodificado['error'] : [];
        $codigo = (int) ($erro['code'] ?? 0);
        $detalhe = trim((string) ($erro['error_user_msg'] ?? $erro['message'] ?? ''));

        if ($codigo === WHATSAPP_ERROR_BAD_TOKEN) {
            $mensagem = 'O token do WhatsApp expirou ou foi revogado. '
                . 'Gere um token permanente de Usuário do Sistema no Business Manager da Meta '
                . 'e atualize WHATSAPP_ACCESS_TOKEN no .env.';
        } elseif ($codigo === 100) {
            $mensagem = 'A Meta não aceitou a consulta de templates. '
                . 'Confira WHATSAPP_BUSINESS_ACCOUNT_ID e se o token tem permissão sobre essa conta.';
        } else {
            $mensagem = sprintf(
                'A Meta recusou a consulta de templates (HTTP %d%s).',
                $httpStatus,
                $codigo > 0 ? ', código ' . $codigo : ''
            );
        }

        return ['error' => $mensagem, 'error_detail' => $detalhe !== '' ? $detalhe : null] + $vazio;
    }

    if (!is_array($decodificado['data'] ?? null)) {
        return ['error' => 'A Meta respondeu à consulta de templates em um formato inesperado.', 'error_detail' => null] + $vazio;
    }

    $conhecidos = waKnownTemplates();
    $aprovados = [];
    $outros = [];

    foreach ($decodificado['data'] as $tpl) {
        if (!is_array($tpl) || trim((string) ($tpl['name'] ?? '')) === '') {
            continue;
        }

        $corpo = '';
        $parametros = 0;
        foreach ($tpl['components'] ?? [] as $componente) {
            if (is_array($componente) && ($componente['type'] ?? '') === 'BODY') {
                $corpo = (string) ($componente['text'] ?? '');
                // Conta variáveis {{1}}, {{2}}...
                if (preg_match_all('/\{\{(\d+)\}\}/', $corpo, $m)) {
                    $parametros = count(array_unique($m[1]));
                }
                break;
            }
        }

        $status = strtoupper(trim((string) ($tpl['status'] ?? '')));
        $nome = (string) $tpl['name'];

        $item = [
            'name' => $nome,
            'language' => (string) ($tpl['language'] ?? 'pt_BR'),
            'category' => (string) ($tpl['category'] ?? 'UTILITY'),
            'status' => $status !== '' ? $status : 'UNKNOWN',
            'body_text' => $corpo,
            'params_count' => $parametros,
        ];

        // Rótulo só quando o número de variáveis bate com o que o código envia.
        if (isset($conhecidos[$nome]) && count($conhecidos[$nome]['labels']) === $parametros) {
            $item['param_labels'] = $conhecidos[$nome]['labels'];
            $item['param_kinds'] = $conhecidos[$nome]['kinds'];
        }

        if ($item['status'] === 'APPROVED') {
            $aprovados[] = $item;
        } else {
            $outros[] = $item;
        }
    }

    // Os aprovados primeiro: são os que a tela deixa enviar.
    return ['templates' => array_merge($aprovados, $outros)] + $vazio;
}

/**
 * Consulta os templates da conta na Meta.
 *
 * @return array{templates:list<array<string,mixed>>,error:?string,error_detail:?string}
 */
function waFetchTemplates(): array
{
    $token = apiSecret('WHATSAPP_ACCESS_TOKEN');
    $wabaId = apiSecret('WHATSAPP_BUSINESS_ACCOUNT_ID');

    if ($token === '' || $wabaId === '') {
        return [
            'templates' => [],
            'error' => 'Não foi possível consultar a Meta: WHATSAPP_ACCESS_TOKEN ou WHATSAPP_BUSINESS_ACCOUNT_ID não estão configurados neste servidor.',
            'error_detail' => null,
        ];
    }

    $url = sprintf(
        'https://graph.facebook.com/%s/%s/message_templates?fields=name,status,language,category,components&limit=100',
        WHATSAPP_API_VERSION,
        rawurlencode($wabaId)
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return waParseTemplatesResponse($status, $resposta, $curlError);
}
