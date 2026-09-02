<?php
declare(strict_types=1);

function getAsaasApiKey(): string
{
    $envPath = __DIR__ . '/env.php';
    if (file_exists($envPath)) {
        require $envPath;
    }
    
    // Fallback to env or getenv
    return defined('ASAAS_API_KEY') ? ASAAS_API_KEY : (getenv('ASAAS_API_KEY') ?: '');
}

function asaasRequest(string $endpoint, string $method = 'GET', array $data = []): array
{
    $apiKey = getAsaasApiKey();
    if (!$apiKey) {
        throw new RuntimeException('ASAAS_API_KEY não configurada.');
    }

    $url = 'https://api.asaas.com/v3' . $endpoint; // Use sandbox if needed, but the user requested production setup via Asaas.com
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'access_token: ' . $apiKey,
        'Content-Type: application/json',
        'User-Agent: EcoletaApp/1.0'
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false) {
        throw new RuntimeException('Erro na comunicação com o Asaas.');
    }
    
    $decoded = json_decode($response, true);
    
    if ($httpCode >= 400) {
        $errorMsg = 'Erro na API Asaas.';
        if (isset($decoded['errors']) && is_array($decoded['errors']) && count($decoded['errors']) > 0) {
            $errorMsg = $decoded['errors'][0]['description'];
        }
        throw new RuntimeException($errorMsg);
    }
    
    return $decoded;
}

function asaasCreateCustomer(string $name, string $email, ?string $document, ?string $phone): string
{
    $data = [
        'name' => $name,
        'email' => $email,
        'cpfCnpj' => $document,
        'mobilePhone' => $phone,
        'notificationDisabled' => false // Ensure Asaas sends its native notifications
    ];
    
    $data = array_filter($data); // Remove nulls
    
    $response = asaasRequest('/customers', 'POST', $data);
    return $response['id'];
}

function asaasUpdateCustomer(string $customerId, array $data): array
{
    return asaasRequest('/customers/' . $customerId, 'POST', $data);
}

function asaasCreatePayment(string $customerId, float $value, string $dueDate): array
{
    $data = [
        'customer' => $customerId,
        'billingType' => 'PIX',
        'value' => $value,
        'dueDate' => $dueDate,
        'description' => 'Fatura Mensal - Ecoleva'
    ];
    
    $response = asaasRequest('/payments', 'POST', $data);
    
    return [
        'id' => $response['id'],
        'invoiceUrl' => $response['invoiceUrl']
    ];
}

function asaasGetPixQrCode(string $paymentId): array
{
    $response = asaasRequest('/payments/' . $paymentId . '/pixQrCode');
    return [
        'payload' => $response['payload'],
        'encodedImage' => $response['encodedImage']
    ];
}
