<?php
declare(strict_types=1);

/**
 * Webhook do WhatsApp Cloud API.
 *
 * Duas responsabilidades:
 *
 *  GET  — a verificação que a Meta faz ao cadastrar a URL. Devolve o
 *         `hub.challenge` cru quando o `hub.verify_token` confere.
 *
 *  POST — os eventos. `messages` são mensagens recebidas: cada uma grava a
 *         conversa, a mensagem e empurra a janela de 24 horas. `statuses` são
 *         confirmações do que NÓS enviamos (sent → delivered → read → failed).
 *
 * Este arquivo é público por obrigação — a Meta chama sem sessão e sem token de
 * CSRF. Quem autentica é a assinatura `X-Hub-Signature-256`, HMAC-SHA256 do
 * corpo cru com o App Secret. Sem `WHATSAPP_APP_SECRET` configurado o endpoint
 * recusa tudo: aceitar corpo não assinado deixaria qualquer um na internet
 * inventando mensagens de clientes e abrindo a janela de 24h por conta própria.
 *
 * Responde 200 para todo evento que consegue ler, mesmo quando não há nada a
 * fazer com ele. Erro devolvido à Meta vira reentrega, e reentrega de um evento
 * que já gravamos só seria absorvida pelo índice único de `wa_message_id`.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../whatsapp_store.php';

/** Resposta curta e encerramento. A Meta não lê o corpo, só o status. */
function waWebhookRespond(int $status, string $body = ''): void
{
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }

    http_response_code($status);
    echo $body;
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// ── Verificação da URL ──────────────────────────────────────────────────────
if ($method === 'GET') {
    $esperado = apiSecret('WHATSAPP_WEBHOOK_VERIFY_TOKEN');
    $enviado = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');

    if ($esperado === '') {
        error_log('Webhook do WhatsApp: WHATSAPP_WEBHOOK_VERIFY_TOKEN não configurado.');
        waWebhookRespond(503, 'unconfigured');
    }

    if ($enviado === '' || !hash_equals($esperado, $enviado)) {
        error_log('Webhook do WhatsApp: token de verificação não confere.');
        waWebhookRespond(403, 'forbidden');
    }

    waWebhookRespond(200, $challenge);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    waWebhookRespond(405, 'method not allowed');
}

// ── Autenticidade do corpo ──────────────────────────────────────────────────
$raw = (string) file_get_contents('php://input');
$appSecret = apiSecret('WHATSAPP_APP_SECRET');

if ($appSecret === '') {
    error_log('Webhook do WhatsApp: WHATSAPP_APP_SECRET não configurado — evento recusado.');
    waWebhookRespond(503, 'unconfigured');
}

$assinatura = trim(apiRequestHeader('X-Hub-Signature-256'));
$esperada = 'sha256=' . hash_hmac('sha256', $raw, $appSecret);

if ($assinatura === '' || !hash_equals($esperada, $assinatura)) {
    error_log('Webhook do WhatsApp: assinatura inválida vinda de ' . apiClientIp());
    waWebhookRespond(403, 'invalid signature');
}

$evento = json_decode($raw, true);
if (!is_array($evento) || !is_array($evento['entry'] ?? null)) {
    waWebhookRespond(200, 'ignored');
}

/** Texto exibível de uma mensagem — mídia vira rótulo, como no painel da Banlek. */
function waExtractBody(array $mensagem): ?string
{
    $tipo = (string) ($mensagem['type'] ?? '');

    if (isset($mensagem['text']['body'])) {
        return (string) $mensagem['text']['body'];
    }

    // Legenda de mídia é o que a pessoa realmente escreveu; vale mais que o rótulo.
    foreach (['image', 'video', 'document', 'audio'] as $midia) {
        $legenda = trim((string) ($mensagem[$midia]['caption'] ?? ''));
        if ($legenda !== '') {
            return $legenda;
        }
    }

    if (isset($mensagem['button']['text'])) {
        return (string) $mensagem['button']['text'];
    }

    if (isset($mensagem['interactive']['button_reply']['title'])) {
        return (string) $mensagem['interactive']['button_reply']['title'];
    }

    if (isset($mensagem['interactive']['list_reply']['title'])) {
        return (string) $mensagem['interactive']['list_reply']['title'];
    }

    if (isset($mensagem['reaction']['emoji'])) {
        return (string) $mensagem['reaction']['emoji'];
    }

    return match ($tipo) {
        'image' => '[imagem]',
        'video' => '[vídeo]',
        'audio' => '[áudio]',
        'document' => '[documento]',
        'sticker' => '[figurinha]',
        'location' => '[localização]',
        'contacts' => '[contato]',
        'unsupported' => '[mensagem não suportada]',
        default => null,
    };
}

/** Nome de perfil e wa_id do contato que mandou a mensagem. */
function waExtractContact(array $valor, string $from): array
{
    foreach ($valor['contacts'] ?? [] as $contato) {
        if (!is_array($contato)) {
            continue;
        }
        if (($contato['wa_id'] ?? null) === $from || count($valor['contacts']) === 1) {
            return [
                'wa_id' => isset($contato['wa_id']) ? (string) $contato['wa_id'] : $from,
                'profile_name' => isset($contato['profile']['name'])
                    ? (string) $contato['profile']['name']
                    : null,
            ];
        }
    }

    return ['wa_id' => $from, 'profile_name' => null];
}

$db = getDbConnection();
$recebidas = 0;
$statusAtualizados = 0;

foreach ($evento['entry'] as $entrada) {
    foreach ((is_array($entrada) ? $entrada['changes'] ?? [] : []) as $mudanca) {
        if (!is_array($mudanca) || ($mudanca['field'] ?? '') !== 'messages') {
            continue;
        }

        $valor = is_array($mudanca['value'] ?? null) ? $mudanca['value'] : [];

        // ── Mensagens recebidas: é isto que abre a janela de 24 horas ──────
        foreach ($valor['messages'] ?? [] as $mensagem) {
            if (!is_array($mensagem)) {
                continue;
            }

            $from = trim((string) ($mensagem['from'] ?? ''));
            if ($from === '') {
                continue;
            }

            try {
                $contato = waExtractContact($valor, $from);
                $conversaId = waEnsureConversation($db, $from, $contato);

                if ($conversaId === null) {
                    continue;
                }

                $gravada = waRecordMessage($db, $conversaId, [
                    'wa_message_id' => isset($mensagem['id']) ? (string) $mensagem['id'] : null,
                    'direction' => 'incoming',
                    'type' => isset($mensagem['type']) ? (string) $mensagem['type'] : null,
                    'body' => waExtractBody($mensagem),
                    'raw_payload' => $mensagem,
                    'message_at' => waTimestampToUtc($mensagem['timestamp'] ?? null) ?? waNow(),
                ]);

                if ($gravada !== null) {
                    $recebidas++;
                }
            } catch (\Throwable $e) {
                // Uma mensagem problemática não pode derrubar as outras do lote.
                error_log('Webhook do WhatsApp: falha ao gravar mensagem recebida: ' . $e->getMessage());
            }
        }

        // ── Confirmações do que enviamos ───────────────────────────────────
        foreach ($valor['statuses'] ?? [] as $status) {
            if (!is_array($status)) {
                continue;
            }

            $waMessageId = trim((string) ($status['id'] ?? ''));
            $situacao = strtolower(trim((string) ($status['status'] ?? '')));

            if ($waMessageId === '' || $situacao === '') {
                continue;
            }

            $erro = null;
            if (isset($status['errors'][0])) {
                $primeiro = $status['errors'][0];
                $erro = trim((string) ($primeiro['title'] ?? $primeiro['message'] ?? ''));
                $erro = $erro === '' ? null : $erro;
            }

            try {
                if (waUpdateMessageStatus($db, $waMessageId, $situacao, $erro)) {
                    $statusAtualizados++;
                }
            } catch (\Throwable $e) {
                error_log('Webhook do WhatsApp: falha ao aplicar status: ' . $e->getMessage());
            }
        }
    }
}

waWebhookRespond(200, sprintf('ok %d/%d', $recebidas, $statusAtualizados));
