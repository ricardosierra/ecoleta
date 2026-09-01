<?php
declare(strict_types=1);

// This script should be protected or only callable via a secret token if exposed.
// In this example, we'll check for a simple GET parameter secret to prevent abuse.
require_once __DIR__ . '/../env.php';

$cronSecret = defined('CRON_SECRET') ? CRON_SECRET : (getenv('CRON_SECRET') ?: 'ecoleva_cron_secret');
if (($_GET['secret'] ?? '') !== $cronSecret) {
    http_response_code(403);
    die('Acesso negado.');
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../asaas_lib.php';

$db = getDbConnection();

$today = new DateTime();
$day = (int)$today->format('d');
$isLastDayOfMonth = $day === (int)$today->format('t'); // Handle February

// Day 30 (or last day of month) - Generate new invoices
if ($day === 30 || $isLastDayOfMonth) {
    // Only active clients with a positive monthly value get billed.
    $clients = $db->query("SELECT * FROM clients WHERE monthly_value > 0 AND status = 'active' AND asaas_customer_id IS NOT NULL")->fetchAll();

    // Due date is next month, on each client's configured due day.
    $nextMonth = clone $today;
    $nextMonth->modify('first day of next month');
    $nextMonthYear = (int)$nextMonth->format('Y');
    $nextMonthNum = (int)$nextMonth->format('m');
    $lastDayOfNextMonth = (int)$nextMonth->format('t');

    foreach ($clients as $client) {
        try {
            $dueDay = min(max((int)($client['due_day'] ?? 10), 1), $lastDayOfNextMonth);
            $dueDateStr = sprintf('%04d-%02d-%02d', $nextMonthYear, $nextMonthNum, $dueDay);

            $payment = asaasCreatePayment($client['asaas_customer_id'], (float)$client['monthly_value'], $dueDateStr);
            $qrCode = asaasGetPixQrCode($payment['id']);
            
            $stmt = $db->prepare("INSERT INTO invoices (client_id, asaas_payment_id, value, due_date, invoice_url, pix_qrcode_text, pix_qrcode_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $client['id'],
                $payment['id'],
                $client['monthly_value'],
                $dueDateStr,
                $payment['invoiceUrl'],
                $qrCode['payload'],
                $qrCode['encodedImage']
            ]);
            
            // Here we would send the email via Resend with the QR code.
            // Asaas will also send its own notifications.
        } catch (\Throwable $e) {
            error_log('Cron Billing Erro no cliente ' . $client['id'] . ': ' . $e->getMessage());
        }
    }
}

// Day 03 or 07 - Resend notifications for PENDING invoices
if ($day === 3 || $day === 7) {
    // Due dates vary per client now, so remind about anything still pending this month.
    $monthStart = $today->format('Y-m-01');
    $monthEnd = $today->format('Y-m-t');

    $stmt = $db->prepare("SELECT i.*, c.email, c.name FROM invoices i JOIN clients c ON i.client_id = c.id WHERE i.status = 'PENDING' AND i.due_date BETWEEN ? AND ?");
    $stmt->execute([$monthStart, $monthEnd]);
    $pendingInvoices = $stmt->fetchAll();
    
    foreach ($pendingInvoices as $inv) {
        // Here we would send a reminder email via Resend
        // Asaas also handles SMS/WhatsApp reminders if configured.
    }
}

echo "Cron rodou com sucesso.";
