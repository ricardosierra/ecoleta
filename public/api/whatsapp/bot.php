<?php
declare(strict_types=1);

require_once __DIR__ . '/../asaas_lib.php';
require_once __DIR__ . '/../whatsapp_lib.php';

function waAutoReplyWithPix(PDO $db, string $from, string $body): void
{
    // Achar cliente pelo whatsapp
    $cleanPhone = preg_replace('/[^0-9]/', '', $from);
    
    // A query tenta bater os últimos 10 ou 11 dígitos caso o DDI não esteja gravado no banco,
    // mas o ideal é normalizar. O banco costuma ter 11 dígitos ou DDI+DDD+Numero.
    // Vamos usar LIKE para garantir que pega, e focar em faturas pendentes.
    $stmt = $db->prepare("
        SELECT i.* 
        FROM invoices i 
        JOIN clients c ON i.client_id = c.id 
        WHERE c.whatsapp LIKE ? AND i.status = 'PENDING'
        ORDER BY i.due_date ASC 
        LIMIT 1
    ");
    $stmt->execute(['%' . substr($cleanPhone, -10)]); // Ex: 2199999999
    $invoice = $stmt->fetch();

    if (!$invoice) {
        return; // Não tem fatura pendente
    }

    try {
        $pix = asaasGetPixQrCode($invoice['asaas_payment_id']);
        
        if (empty($pix['payload'])) {
            return;
        }

        $conversationId = waEnsureConversation($db, $from, ['client_id' => $invoice['client_id'] ?? null]);

        // QR Code impresso/imagem: apenas se o cliente pedir explicitamente
        $pediuQrCode = (bool) preg_match('/(qr\s*code|qrcode|qr\b|imagem|foto|impresso)/i', $body);

        if ($pediuQrCode && !empty($pix['encodedImage'])) {
            $tmpDir = sys_get_temp_dir();
            $imagePath = $tmpDir . '/pix_' . $invoice['asaas_payment_id'] . '.png';
            $base64 = preg_replace('#^data:image/\w+;base64,#i', '', $pix['encodedImage']);
            file_put_contents($imagePath, base64_decode($base64));

            $mediaId = waUploadMedia($imagePath, 'image/png');
            unlink($imagePath);

            $respImg = waRequest([
                'messaging_product' => 'whatsapp',
                'to' => $from,
                'type' => 'image',
                'image' => [
                    'id' => $mediaId,
                    'caption' => 'Aqui está a imagem do QR Code para o pagamento da sua fatura!'
                ]
            ]);
            if ($conversationId !== null) {
                waRecordMessage($db, $conversationId, [
                    'wa_message_id' => waExtractSentMessageId($respImg),
                    'direction' => 'outgoing',
                    'type' => 'image',
                    'status' => 'accepted',
                    'body' => '[imagem QR Code Pix]',
                    'raw_payload' => $respImg,
                ]);
            }
        }

        // Mensagem de cobrança com dados da fatura e instrução do Pix Copia e Cola direto
        $valor = number_format((float) ($invoice['value'] ?? 0), 2, ',', '.');
        $venc = date('d/m/Y', strtotime((string) $invoice['due_date']));
        $nome = trim((string) ($invoice['client_name'] ?? ''));

        $linhas = [
            $nome !== '' ? "Olá, {$nome}." : "Olá.",
            "",
            "Sua fatura da Ecoleva no valor de *R$ {$valor}* vence em *{$venc}*.",
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

        $avisoTexto = implode("\n", $linhas);

        $respAviso = waRequest([
            'messaging_product' => 'whatsapp',
            'to' => $from,
            'type' => 'text',
            'text' => [
                'body' => $avisoTexto
            ]
        ]);
        if ($conversationId !== null) {
            waRecordMessage($db, $conversationId, [
                'wa_message_id' => waExtractSentMessageId($respAviso),
                'direction' => 'outgoing',
                'type' => 'text',
                'status' => 'accepted',
                'body' => $avisoTexto,
                'raw_payload' => $respAviso,
            ]);
        }

        // Mensagem com o código Pix puro para o cliente copiar com um toque no celular
        $respPix = waRequest([
            'messaging_product' => 'whatsapp',
            'to' => $from,
            'type' => 'text',
            'text' => [
                'body' => $pix['payload']
            ]
        ]);
        if ($conversationId !== null) {
            waRecordMessage($db, $conversationId, [
                'wa_message_id' => waExtractSentMessageId($respPix),
                'direction' => 'outgoing',
                'type' => 'text',
                'status' => 'accepted',
                'body' => $pix['payload'],
                'raw_payload' => $respPix,
            ]);
        }

    } catch (Throwable $e) {
        error_log('Falha ao auto-responder cobrança / Pix: ' . $e->getMessage());
    }
}
