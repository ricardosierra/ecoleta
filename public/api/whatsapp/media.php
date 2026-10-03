<?php
declare(strict_types=1);

/**
 * Envio de mídia (imagens, documentos e áudios) para a Meta WhatsApp Cloud API.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';
require_once __DIR__ . '/../whatsapp_store.php';
require_once __DIR__ . '/../whatsapp_lib.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();

$db = getDbConnection();
$actor = waRequirePanelAccess($db);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    apiJsonResponse(405, ['error' => 'Método não permitido.'], ['Allow: POST']);
}

// Suporta tanto multipart/form-data quanto JSON com base64
$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
$isJson = str_contains(strtolower($contentType), 'application/json');

$conversationId = 0;
$mediaType = 'image';
$caption = null;
$tmpFilePath = null;
$mimeType = '';
$originalFilename = 'arquivo';

if ($isJson) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        apiJsonResponse(400, ['error' => 'Payload JSON inválido.']);
    }

    $conversationId = (int) ($body['conversation_id'] ?? 0);
    $mediaType = (string) ($body['type'] ?? 'image');
    $caption = isset($body['caption']) && trim((string) $body['caption']) !== '' ? trim((string) $body['caption']) : null;
    $mimeType = (string) ($body['mime_type'] ?? '');
    $originalFilename = (string) ($body['filename'] ?? 'arquivo');
    $base64 = (string) ($body['media_base64'] ?? '');

    if ($base64 === '') {
        apiJsonResponse(400, ['error' => 'Conteúdo de mídia não informado.']);
    }

    // Se vier com data URI prefix (ex: data:audio/webm;codecs=opus;base64,...),
    // separa o tipo (com parâmetros) do conteúdo.
    $dataUri = waParseDataUri($base64);
    if ($dataUri !== null) {
        $mimeType = $mimeType !== '' ? $mimeType : $dataUri['mime'];
        $base64 = $dataUri['data'];
    }

    $binary = base64_decode($base64, true);
    if ($binary === false || strlen($binary) === 0) {
        apiJsonResponse(400, ['error' => 'Arquivo base64 corrompido ou inválido.']);
    }

    $tmpFilePath = tempnam(sys_get_temp_dir(), 'wa_media_');
    file_put_contents($tmpFilePath, $binary);
} else {
    $conversationId = (int) ($_POST['conversation_id'] ?? 0);
    $mediaType = (string) ($_POST['type'] ?? 'image');
    $caption = isset($_POST['caption']) && trim((string) $_POST['caption']) !== '' ? trim((string) $_POST['caption']) : null;

    if (!isset($_FILES['file']) || !is_array($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        apiJsonResponse(400, ['error' => 'Nenhum arquivo enviado ou erro no upload.']);
    }

    $tmpFilePath = (string) $_FILES['file']['tmp_name'];
    $mimeType = (string) ($_FILES['file']['type'] ?? '');
    $originalFilename = (string) ($_FILES['file']['name'] ?? 'arquivo');
}

if ($conversationId <= 0) {
    if ($isJson && $tmpFilePath && file_exists($tmpFilePath)) @unlink($tmpFilePath);
    apiJsonResponse(400, ['error' => 'Conversa inválida.']);
}

if (!in_array($mediaType, ['image', 'audio', 'document', 'video'], true)) {
    if ($isJson && $tmpFilePath && file_exists($tmpFilePath)) @unlink($tmpFilePath);
    apiJsonResponse(400, ['error' => 'Tipo de mídia não suportado.']);
}

// Sem tipo informado, o conteúdo diz qual é. Precisa vir antes da validação do
// áudio, que depende dele.
if ($mimeType === '') {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = (string) finfo_file($finfo, $tmpFilePath);
    finfo_close($finfo);
}

// A Meta recusa áudio em webm, que é o que o Chrome grava, e a recusa dela chega
// como erro genérico de upload. Dizemos aqui, antes de qualquer envio, o que ela
// aceita de verdade.
if ($mediaType === 'audio') {
    $erroAudio = waAudioFormatError($mimeType);
    if ($erroAudio !== null) {
        if ($isJson && $tmpFilePath && file_exists($tmpFilePath)) @unlink($tmpFilePath);
        apiJsonResponse(400, ['error' => $erroAudio, 'code' => 'unsupported_audio_format']);
    }
}

// O que sobe para a Meta é o tipo limpo: sem parâmetros, e ogg sempre com opus.
$mimeType = waMediaUploadMime($mimeType);

$stmt = $db->prepare('SELECT * FROM whatsapp_conversations WHERE id = ? LIMIT 1');
$stmt->execute([$conversationId]);
$conversa = $stmt->fetch();

if (!$conversa) {
    if ($isJson && $tmpFilePath && file_exists($tmpFilePath)) @unlink($tmpFilePath);
    apiJsonResponse(404, ['error' => 'Conversa não encontrada.']);
}

if (!waWindowIsOpen($conversa['service_window_expires_at'])) {
    if ($isJson && $tmpFilePath && file_exists($tmpFilePath)) @unlink($tmpFilePath);
    apiJsonResponse(400, [
        'error' => 'A janela de 24 horas para resposta livre está fechada. Mídia só pode ser enviada com a janela aberta.',
        'code' => 'window_closed',
    ]);
}

if (!waIsConfigured()) {
    if ($isJson && $tmpFilePath && file_exists($tmpFilePath)) @unlink($tmpFilePath);
    apiJsonResponse(503, [
        'error' => 'O WhatsApp do robô não está configurado neste servidor.',
        'code' => 'whatsapp_not_configured',
    ]);
}

try {
    $mediaId = waUploadMedia($tmpFilePath, $mimeType);
} catch (\Throwable $e) {
    if ($isJson && $tmpFilePath && file_exists($tmpFilePath)) @unlink($tmpFilePath);
    apiJsonResponse(502, ['error' => 'Falha ao subir arquivo para o WhatsApp: ' . $e->getMessage()]);
}

if ($isJson && $tmpFilePath && file_exists($tmpFilePath)) {
    @unlink($tmpFilePath);
}

if ($mediaId === '') {
    apiJsonResponse(502, ['error' => 'O WhatsApp não retornou o ID da mídia enviada.']);
}

$destinatario = preg_replace('/\D+/', '', (string) $conversa['phone']) ?? '';
$mediaObj = ['id' => $mediaId];
if ($mediaType === 'image' || $mediaType === 'video' || $mediaType === 'document') {
    if ($caption !== null) {
        $mediaObj['caption'] = $caption;
    }
}
if ($mediaType === 'document') {
    $mediaObj['filename'] = $originalFilename;
}

$payload = [
    'messaging_product' => 'whatsapp',
    'to' => $destinatario,
    'type' => $mediaType,
    $mediaType => $mediaObj,
];

try {
    $response = waRequest($payload);
} catch (\Throwable $e) {
    apiJsonResponse(502, ['error' => 'Falha ao entregar mídia: ' . $e->getMessage()]);
}

$waMessageId = waExtractSentMessageId($response);
$nowUtc = waNow();

$bodyPreview = $caption ?? match ($mediaType) {
    'audio' => '[áudio]',
    'image' => '[imagem]',
    'document' => '[documento: ' . $originalFilename . ']',
    'video' => '[vídeo]',
    default => '[mídia]'
};

$msgId = waRecordMessage($db, $conversationId, [
    'wa_message_id' => $waMessageId,
    'direction' => 'outgoing',
    'type' => $mediaType,
    'status' => 'accepted',
    'body' => $bodyPreview,
    'raw_payload' => $response,
    'message_at' => $nowUtc,
    'sent_by_user_id' => $actor['id'] ?? null,
]);

apiJsonResponse(200, [
    'ok' => true,
    'message' => [
        'id' => $msgId,
        'direction' => 'outgoing',
        'type' => $mediaType,
        'status' => 'accepted',
        'body' => $bodyPreview,
        'error_message' => null,
        'message_at' => waToIso($nowUtc),
        'service_order_id' => null,
    ],
]);
