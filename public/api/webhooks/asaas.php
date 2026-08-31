<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

// Asaas sends a POST with a JSON body
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!$body || !isset($body['event'])) {
    http_response_code(400);
    die('Invalid payload');
}

$db = getDbConnection();

// Check the event type
if ($body['event'] === 'PAYMENT_RECEIVED' || $body['event'] === 'PAYMENT_CONFIRMED') {
    $paymentId = $body['payment']['id'] ?? '';
    if ($paymentId) {
        $stmt = $db->prepare("UPDATE invoices SET status = 'RECEIVED' WHERE asaas_payment_id = ?");
        $stmt->execute([$paymentId]);
    }
} elseif ($body['event'] === 'PAYMENT_OVERDUE') {
    $paymentId = $body['payment']['id'] ?? '';
    if ($paymentId) {
        $stmt = $db->prepare("UPDATE invoices SET status = 'OVERDUE' WHERE asaas_payment_id = ?");
        $stmt->execute([$paymentId]);
    }
}

echo json_encode(['ok' => true]);
