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
