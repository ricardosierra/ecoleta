<?php
declare(strict_types=1);

require_once __DIR__ . '/billing_lib.php';
require_once __DIR__ . '/asaas_lib.php';
require_once __DIR__ . '/os/os_lib.php';
require_once __DIR__ . '/whatsapp_lib.php';
require_once __DIR__ . '/whatsapp_store.php';

/** Serialize emission and delivery across HTTP/cron workers. */
function billingLocked(PDO $db, string $key, callable $work): mixed
{
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $name = 'ecoleva:' . substr(hash('sha256', $key), 0, 48);
    if ($mysql) {
        $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$name]);
        if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('Cobrança em processamento. Tente novamente.');
    }
    try {
        return $work();
    } finally {
        if ($mysql) {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$name]);
        }
    }
}

/** Recover an external payment after an interrupted request before creating another. */
function billingIssueInvoice(PDO $db, array $client, float $value, string $dueDate, ?callable $request = null): array
{
    if (trim((string) ($client['document'] ?? '')) === '') throw new InvalidArgumentException('Preencha o CPF/CNPJ do cliente antes de gerar a fatura.');
    if (empty($client['asaas_customer_id'])) throw new InvalidArgumentException('Cliente não sincronizado com o Asaas.');
    if (($client['status'] ?? '') !== 'active') throw new InvalidArgumentException('Ative o cliente antes de gerar a fatura.');
    if (!is_finite($value) || $value < 5) throw new InvalidArgumentException('A cobrança deve ser de pelo menos R$ 5,00.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
    if (!$date || $date->format('Y-m-d') !== $dueDate) throw new InvalidArgumentException('Data de vencimento inválida.');
    $request ??= 'asaasRequest';
    return billingLocked($db, 'issue:' . $client['id'] . ':' . $dueDate, function () use ($db, $client, $value, $dueDate, $request): array {
        $lookup = $db->prepare('SELECT * FROM invoices WHERE client_id = ? AND due_date = ? LIMIT 1');
        $lookup->execute([$client['id'], $dueDate]);
        if ($existing = $lookup->fetch()) return $existing + ['created' => false];
        $reference = 'ecoleva:client:' . $client['id'] . ':due:' . $dueDate;
        $found = $request('/payments?externalReference=' . rawurlencode($reference), 'GET', []);
        $payment = $found['data'][0] ?? null;
        if (!$payment) {
            $payment = $request('/payments', 'POST', [
                'customer' => $client['asaas_customer_id'], 'billingType' => 'BOLETO',
                'value' => round($value, 2), 'dueDate' => $dueDate,
                'description' => 'Fatura Mensal - Ecoleva', 'externalReference' => $reference,
            ]);
        }
        if (empty($payment['id']) || empty($payment['invoiceUrl'])) throw new RuntimeException('O Asaas não retornou os dados completos da cobrança.');
        // Save the payment BEFORE asking for Pix: Pix may be unavailable for the account.
        // pix_qrcode_url is VARCHAR(255), so never store encodedImage (base64) in it.
        $stmt = $db->prepare('INSERT INTO invoices (client_id, asaas_payment_id, value, due_date, invoice_url, status) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$client['id'], $payment['id'], $payment['value'] ?? $value, $payment['dueDate'] ?? $dueDate, $payment['invoiceUrl'], $payment['status'] ?? 'PENDING']);
        $id = (int) $db->lastInsertId();
        try {
            $pix = $request('/payments/' . rawurlencode($payment['id']) . '/pixQrCode', 'GET', []);
            $update = $db->prepare('UPDATE invoices SET pix_qrcode_text = ? WHERE id = ?');
            $update->execute([$pix['payload'] ?? null, $id]);
        } catch (Throwable $e) {
            error_log('Pix indisponível para a fatura #' . $id . '; boleto preservado.');
        }
        $lookup->execute([$client['id'], $dueDate]);
        return $lookup->fetch() + ['created' => true];
    });
}

/** One notification per invoice, event and channel; failures remain retryable. */
function billingDeliverInvoice(PDO $db, array $invoice, array $client, string $event = 'new', ?callable $mail = null, ?callable $whatsapp = null): array
{
    if (!in_array($invoice['status'], ['PENDING', 'OVERDUE'], true)) return ['email' => 'skipped', 'whatsapp' => 'skipped', 'errors' => []];
    $mail ??= 'osSendMail';
    $whatsapp ??= 'waRequest';
    $email = str_starts_with($event, 'reminder:')
        ? billingReminderEmail($client['name'], $invoice['value'], $invoice['due_date'], (string) ($invoice['pix_qrcode_text'] ?? ''), (string) $invoice['invoice_url'])
        : billingNewInvoiceEmail($client['name'], $invoice['value'], $invoice['due_date'], (string) ($invoice['pix_qrcode_text'] ?? ''), (string) $invoice['invoice_url']);
    $result = ['email' => 'skipped', 'whatsapp' => 'skipped', 'errors' => []];
    foreach (['email', 'whatsapp'] as $channel) {
        $destination = $channel === 'email' ? trim((string) ($client['email'] ?? '')) : normalizePhone($client['whatsapp'] ?? '');
        if ($destination === '') continue;
        $key = 'invoice:' . $invoice['id'] . ':' . $event . ':' . $channel;
        try {
            $result[$channel] = billingLocked($db, $key, function () use ($db, $invoice, $client, $email, $channel, $destination, $key, $mail, $whatsapp): string {
                $check = $db->prepare("SELECT id, action, target_login FROM activity_logs WHERE action IN ('billing_delivery', 'billing_attempt', 'billing_failed') AND description = ? ORDER BY id DESC LIMIT 1");
                $check->execute([$key]);
                $previous = $check->fetch();
                if ($previous && $previous['action'] === 'billing_attempt') throw new RuntimeException('Envio anterior com resultado incerto. Confira o provedor antes de tentar novamente.');
                if ($previous && $previous['action'] === 'billing_delivery') {
                    $failed = false;
                    if ($channel === 'whatsapp' && $previous['target_login']) {
                        $message = $db->prepare('SELECT status FROM whatsapp_messages WHERE wa_message_id = ?');
                        $message->execute([$previous['target_login']]);
                        $failed = $message->fetchColumn() === 'failed';
                    }
                    if (!$failed) return 'already_sent';
                }
                // Reserve durably before contacting a provider. An interrupted send remains
                // uncertain instead of automatically delivering the same message twice.
                $reserve = $db->prepare("INSERT INTO activity_logs (action, description, performed_by_login, ip_address, user_agent) VALUES ('billing_attempt', ?, 'billing', ?, 'EcoletaBilling/1.0')");
                $reserve->execute([$key, apiClientIp()]);
                $attemptId = (int) $db->lastInsertId();
                $accepted = false;
                $messageId = null;
                try {
                if ($channel === 'email') {
                    if (!$mail($destination, $email['subject'], $email['html'], $email['text'])) throw new RuntimeException('Servidor de e-mail recusou o envio.');
                    $accepted = true;
                } else {
                    if (!waIsConfigured()) throw new RuntimeException('WhatsApp não configurado.');
                    $conversationId = waEnsureConversation($db, $destination, ['client_id' => $client['id']]);
                    $conversation = waFindConversationByPhone($db, $destination);
                    $open = waWindowIsOpen($conversation['service_window_expires_at'] ?? null);
                    $text = $email['text'];
                    $payload = billingWhatsAppPayload($destination, $client['name'], $invoice, $text, $open);
                    $response = $whatsapp($payload);
                    $messageId = waExtractSentMessageId($response);
                    if (!$messageId) throw new RuntimeException('WhatsApp não confirmou o recebimento da solicitação.');
                    $accepted = true;
                    if ($conversationId !== null) waRecordMessage($db, $conversationId, [
                        'wa_message_id' => $messageId, 'direction' => 'outgoing',
                        'type' => $open ? 'text' : 'template', 'status' => 'accepted',
                        'body' => $text, 'raw_payload' => $response,
                    ]);
                }
                $complete = $db->prepare("UPDATE activity_logs SET action = 'billing_delivery', target_login = ? WHERE id = ?");
                $complete->execute([$messageId, $attemptId]);
                return $channel === 'whatsapp' ? 'accepted' : 'sent';
                } catch (Throwable $e) {
                    if (!$accepted) {
                        $failed = $db->prepare("UPDATE activity_logs SET action = 'billing_failed' WHERE id = ?");
                        $failed->execute([$attemptId]);
                    }
                    throw $e;
                }
            });
        } catch (Throwable $e) {
            $result[$channel] = 'failed';
            $result['errors'][$channel] = $e->getMessage();
            error_log('Falha no envio de ' . $channel . ' da fatura #' . $invoice['id']);
        }
    }
    return $result;
}

function billingWhatsAppPayload(string $to, string $name, array $invoice, string $text, bool $windowOpen): array
{
    $payload = ['messaging_product' => 'whatsapp', 'to' => $to];
    if ($windowOpen) return $payload + ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $text]];
    $template = apiSecret('WHATSAPP_BILLING_TEMPLATE');
    if ($template === '') throw new RuntimeException('Template de cobrança do WhatsApp não configurado.');
    return $payload + ['type' => 'template', 'template' => [
        'name' => $template, 'language' => ['code' => apiSecret('WHATSAPP_BILLING_TEMPLATE_LANG') ?: 'pt_BR'],
        'components' => [['type' => 'body', 'parameters' => array_map(
            static fn(string $value): array => ['type' => 'text', 'text' => $value],
            [$name, billingMoney($invoice['value']), billingDate($invoice['due_date']), (string) $invoice['invoice_url']]
        )]],
    ]];
}
