<?php
require_once __DIR__ . '/public/api/env.php';
require_once __DIR__ . '/public/api/asaas_lib.php';
require_once __DIR__ . '/public/api/os/os_lib.php';

echo "Tentando gerar Pix Asaas com CPF genérico...\n";
try {
    $customerId = asaasCreateCustomer('Teste Ecoleva', 'sierra.csi@gmail.com', '12345678909', null);
    $payment = asaasCreatePayment($customerId, 5.00, date('Y-m-d', strtotime('+1 day')));
    $qr = asaasGetPixQrCode($payment['id']);
    
    $htmlQr = "<h3>Fatura de Teste Asaas (2)</h3><p>Escaneie o QR Code abaixo para pagar:</p><br/><img src='{$qr['encodedImage']}' width='300'/><br/><p>Copia e Cola: <strong>{$qr['payload']}</strong></p>";
    $textQr = "Pix Copia e Cola: " . $qr['payload'];
    $mailQrOk = osSendMail('sierra.csi@gmail.com', 'Seu QR Code Asaas - Ecoleva', $htmlQr, $textQr);
    
    echo $mailQrOk ? " -> E-mail com QR Code PIX enviado com sucesso!\n" : " -> Falha ao enviar o e-mail com QR Code.\n";
} catch (Throwable $e) {
    echo " -> Erro no Asaas: " . $e->getMessage() . "\n";
}
