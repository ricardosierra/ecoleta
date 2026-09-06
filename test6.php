<?php
require_once __DIR__ . '/public/api/env.php';
require_once __DIR__ . '/public/api/asaas_lib.php';

try {
    $customerId = asaasCreateCustomer('Teste Boleto', 'sierra.csi@gmail.com', '12345678909', null);
    $data = [
        'customer' => $customerId,
        'billingType' => 'BOLETO',
        'value' => 5.00,
        'dueDate' => date('Y-m-d', strtotime('+5 days')),
        'description' => 'Fatura Mensal - Teste Boleto'
    ];
    $response = asaasRequest('/payments', 'POST', $data);
    echo "Fatura criada. URL: " . $response['invoiceUrl'] . "\n";
    
    $qr = asaasGetPixQrCode($response['id']);
    echo "Pix gerado com sucesso! Payload: " . substr($qr['payload'], 0, 20) . "...\n";
} catch (Throwable $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
