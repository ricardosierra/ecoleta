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
        
        if (empty($pix['encodedImage'])) {
            return;
        }

        // Salvar a imagem temporariamente
        $tmpDir = sys_get_temp_dir();
        $imagePath = $tmpDir . '/pix_' . $invoice['asaas_payment_id'] . '.png';
        
        $base64 = preg_replace('#^data:image/\w+;base64,#i', '', $pix['encodedImage']);
        file_put_contents($imagePath, base64_decode($base64));

        // Upload no Meta
        $mediaId = waUploadMedia($imagePath, 'image/png');
        
        // Remove tmp
        unlink($imagePath);

        // Enviar a Imagem
        waRequest([
            'messaging_product' => 'whatsapp',
            'to' => $from,
            'type' => 'image',
            'image' => [
                'id' => $mediaId,
                'caption' => 'Aqui está o QR Code para o pagamento da sua fatura pendente!'
            ]
        ]);

        // Enviar o Pix Copia e Cola
        waRequest([
            'messaging_product' => 'whatsapp',
            'to' => $from,
            'type' => 'text',
            'text' => [
                'body' => "Pix Copia e Cola:\n\n" . $pix['payload']
            ]
        ]);

    } catch (Throwable $e) {
        error_log('Falha ao auto-responder QR Code: ' . $e->getMessage());
    }
}
