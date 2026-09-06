<?php
// Fix path to include env.php from public/api since we run from root
require_once __DIR__ . '/public/api/env.php';
require_once __DIR__ . '/public/api/asaas_lib.php';
require_once __DIR__ . '/public/api/whatsapp_lib.php';
require_once __DIR__ . '/public/api/os/os_lib.php';

echo "1. Enviando e-mail de teste para sierra.csi@gmail.com...\n";
$mailOk = osSendMail('sierra.csi@gmail.com', 'Teste de Email - Ecoleva', '<p>Olá, este é um e-mail de teste confirmando sua configuração.</p>', 'Olá, este é um e-mail de teste.');
echo $mailOk ? " -> Email enviado com sucesso!\n" : " -> Falha ao enviar email.\n";

echo "\n2. Criando fatura de teste e gerando QR Code no Asaas...\n";
try {
    // Generate a customer to test
    $customerId = asaasCreateCustomer('Teste Ecoleva', 'sierra.csi@gmail.com', null, null);
    $payment = asaasCreatePayment($customerId, 5.00, date('Y-m-d', strtotime('+1 day')));
    $qr = asaasGetPixQrCode($payment['id']);
    
    echo " -> Fatura criada: {$payment['id']}\n";
    
    // Send email with QR code
    $htmlQr = "<h3>Fatura de Teste Asaas</h3><p>Escaneie o QR Code abaixo para pagar:</p><br/><img src='{$qr['encodedImage']}' width='300'/><br/><p>Copia e Cola: <strong>{$qr['payload']}</strong></p>";
    $textQr = "Pix Copia e Cola: " . $qr['payload'];
    $mailQrOk = osSendMail('sierra.csi@gmail.com', 'Seu QR Code Asaas - Ecoleva', $htmlQr, $textQr);
    
    echo $mailQrOk ? " -> E-mail com QR Code enviado com sucesso!\n" : " -> Falha ao enviar o e-mail com QR Code.\n";
} catch (Throwable $e) {
    echo " -> Erro no Asaas: " . $e->getMessage() . "\n";
}

echo "\n3. Enviando WhatsApp para 21999193898...\n";
try {
    // We send a direct text message first. If it fails due to 24h window (outsideWindow = true), we catch it.
    $wa = waRequest([
        'messaging_product' => 'whatsapp',
        'to' => '5521999193898',
        'type' => 'text',
        'text' => ['preview_url' => false, 'body' => "🤖 Olá! Mensagem de teste da automação Ecoleva.\nSeu WhatsApp foi configurado com sucesso!"]
    ]);
    echo " -> WhatsApp enviado com sucesso! ID: " . ($wa['messages'][0]['id'] ?? 'N/A') . "\n";
} catch (WhatsAppApiException $e) {
    if ($e->outsideWindow) {
        echo " -> O WhatsApp recusou a mensagem livre (Fora da janela de 24h). O cliente precisa enviar uma mensagem primeiro, ou precisamos usar o Template aprovado.\n";
    } else {
        echo " -> Falha no WhatsApp: " . $e->getMessage() . "\n";
    }
} catch (Throwable $e) {
    echo " -> Erro inesperado no WhatsApp: " . $e->getMessage() . "\n";
}
