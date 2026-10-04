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
    if (!is_finite($value) || $value < BILLING_MIN_VALUE) throw new InvalidArgumentException('A cobrança deve ser de pelo menos R$ 5,00.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
    if (!$date || $date->format('Y-m-d') !== $dueDate) throw new InvalidArgumentException('Data de vencimento inválida.');
    $request ??= 'asaasRequest';
    return billingLocked($db, 'issue:' . $client['id'] . ':' . $dueDate, function () use ($db, $client, $value, $dueDate, $request): array {
        // Fatura cancelada não conta: quem cancelou e gera de novo para o mesmo vencimento
        // espera uma cobrança nova. Antes, o cancelamento era devolvido como "fatura já
        // existente", nada era criado nem enviado, e a tela dizia que deu certo.
        $lookup = $db->prepare("SELECT * FROM invoices WHERE client_id = ? AND due_date = ? AND status <> 'DELETED' ORDER BY id DESC LIMIT 1");
        $lookup->execute([$client['id'], $dueDate]);
        if ($existing = $lookup->fetch()) return $existing + ['created' => false];
        $reference = 'ecoleva:client:' . $client['id'] . ':due:' . $dueDate;
        $found = $request('/payments?externalReference=' . rawurlencode($reference), 'GET', []);
        // A cobrança cancelada pode voltar pela mesma referência; reaproveitá-la
        // ressuscitaria uma fatura morta (e bateria na chave única de asaas_payment_id).
        $known = $db->prepare('SELECT * FROM invoices WHERE asaas_payment_id = ? LIMIT 1');
        $payment = null;
        foreach ($found['data'] ?? [] as $candidate) {
            if (!empty($candidate['deleted']) || ($candidate['status'] ?? '') === 'DELETED') continue;
            $known->execute([(string) ($candidate['id'] ?? '')]);
            $local = $known->fetch();
            if ($local && $local['status'] === 'DELETED') continue;
            // Cobranca que o Asaas devolve pela referencia e que JA esta gravada aqui, com
            // outro vencimento (a operadora prorrogou a fatura no painel do Asaas, e o webhook
            // atualizou due_date): e a mesma fatura. Inserir de novo bateria na chave unica de
            // asaas_payment_id e o cron daria 502 todo dia, com texto de SQL no retorno.
            if ($local) return $local + ['created' => false];
            $payment = $candidate;
            break;
        }
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
                    // A Meta ja aceitou: gravar a mensagem no historico do painel e so
                    // bookkeeping. Se falhar, nao pode deixar a tentativa em "incerta".
                    try {
                        if ($conversationId !== null) waRecordMessage($db, $conversationId, [
                            'wa_message_id' => $messageId, 'direction' => 'outgoing',
                            'type' => $open ? 'text' : 'template', 'status' => 'accepted',
                            'body' => $text, 'raw_payload' => $response,
                        ]);
                    } catch (Throwable $e) {
                        error_log('Mensagem de fatura aceita pela Meta, mas nao gravada no historico: ' . $e->getMessage());
                    }
                    if ($open && !empty($invoice['pix_qrcode_text'])) {
                        try {
                            $pixPayloadMsg = ['messaging_product' => 'whatsapp', 'to' => $destination, 'type' => 'text', 'text' => ['body' => (string) $invoice['pix_qrcode_text']]];
                            $respPix = $whatsapp($pixPayloadMsg);
                            if ($conversationId !== null && is_array($respPix)) {
                                waRecordMessage($db, $conversationId, [
                                    'wa_message_id' => waExtractSentMessageId($respPix),
                                    'direction' => 'outgoing',
                                    'type' => 'text',
                                    'status' => 'accepted',
                                    'body' => (string) $invoice['pix_qrcode_text'],
                                    'raw_payload' => $respPix,
                                ]);
                            }
                        } catch (Throwable $e) {
                            error_log('Falha ao enviar mensagem avulsa com código Pix: ' . $e->getMessage());
                        }
                    }
                }
                // O envio JA saiu. Marcar a tentativa como entregue e o que impede o reenvio
                // e o erro "resultado incerto" dos dias seguintes; por isso, se gravar o id
                // da mensagem falhar (a coluna target_login era VARCHAR(50) e o id do
                // WhatsApp tem mais que isso, o que em MySQL estrito derrubava este UPDATE
                // depois de a mensagem sair), grava so a marca de entregue.
                try {
                    $complete = $db->prepare("UPDATE activity_logs SET action = 'billing_delivery', target_login = ? WHERE id = ?");
                    $complete->execute([$messageId, $attemptId]);
                } catch (Throwable $e) {
                    error_log('Nao consegui gravar o id da mensagem de fatura no log de entrega: ' . $e->getMessage());
                    $fallback = $db->prepare("UPDATE activity_logs SET action = 'billing_delivery' WHERE id = ?");
                    $fallback->execute([$attemptId]);
                }
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
    // Os dois canais sem destinatário: a cobrança existe no Asaas, mas ninguém recebe
    // aviso. Sem este erro a tela dizia "notificações processadas" e o cron "rodou com
    // sucesso" para uma fatura que nunca chegou a quem deveria pagá-la.
    if ($result['email'] === 'skipped' && $result['whatsapp'] === 'skipped') {
        $result['errors']['destino'] = 'Cliente sem e-mail e sem WhatsApp cadastrados: ninguém foi avisado desta fatura.';
    }
    return $result;
}

/**
 * A fatura do cliente no mês de `$dueDate`: a do próprio vencimento, se houver,
 * senão qualquer outra daquele mês. Conta fatura cancelada também.
 *
 * É o que impede o ciclo automático de cobrar duas vezes o mesmo mês quando a
 * operadora já gerou uma fatura avulsa com outra data, e de refazer uma fatura
 * que ela cancelou de propósito.
 */
function billingFindMonthInvoice(PDO $db, int $clientId, string $dueDate): ?array
{
    $first = (new DateTimeImmutable(substr($dueDate, 0, 7) . '-01'));
    $stmt = $db->prepare("SELECT * FROM invoices WHERE client_id = ? AND due_date BETWEEN ? AND ? ORDER BY (status = 'DELETED') ASC, (due_date = ?) DESC, due_date ASC, id DESC LIMIT 1");
    $stmt->execute([$clientId, $first->format('Y-m-d'), $first->format('Y-m-t'), $dueDate]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * O ciclo diário do faturamento automático.
 *
 * Roda todo dia e é idempotente: cada cliente ativo com valor mensal tem os
 * vencimentos de `billingDueDatesToIssue()` garantidos, e o que já foi emitido ou
 * enviado é reconhecido e pulado. Por isso um dia perdido, uma falha do Asaas ou um
 * cliente cadastrado no meio do mês se resolvem na execução seguinte, sem que ninguém
 * precise lembrar de nada.
 *
 * Os lembretes (dias 3 e 7) pulam a fatura emitida há menos de `BILLING_REMINDER_MIN_AGE`
 * e a que acabou de ser entregue neste mesmo ciclo: uma fatura nova num dia de lembrete
 * sairia duas vezes, em seguida, e isso vale também entre execuções, quando o cron roda
 * duas vezes no mesmo dia (um disparo manual de teste depois do deploy, mais o agendado).
 *
 * @return array{clients:int, generated:int, reminders:int, errors:list<array<string,mixed>>}
 */
function billingRunCycle(PDO $db, DateTimeImmutable $today, ?callable $request = null, ?callable $mail = null, ?callable $whatsapp = null): array
{
    $generated = 0;
    $reminders = 0;
    $errors = [];
    $deliveredNow = [];

    $clients = $db->query("SELECT * FROM clients WHERE monthly_value > 0 AND status = 'active' ORDER BY id")->fetchAll();
    foreach ($clients as $client) {
        foreach (billingDueDatesToIssue($today, (int) $client['due_day']) as $dueDate) {
            try {
                // A trava e por cliente e MES, nao por vencimento: duas execucoes ao mesmo
                // tempo (cron do servidor mais disparo manual) ou uma troca de due_day no meio
                // do ciclo chegam com datas-alvo diferentes para o mesmo mes, e a trava por
                // vencimento deixava as duas emitirem (R$ 850 em duplicidade, reproduzido em
                // MariaDB). A busca do mes fica DENTRO da trava para enxergar a fatura da outra.
                $invoice = billingLocked($db, 'month:' . $client['id'] . ':' . substr($dueDate, 0, 7), function () use ($db, $client, $dueDate, $request): array {
                    $existing = billingFindMonthInvoice($db, (int) $client['id'], $dueDate);
                    if ($existing !== null) return $existing + ['created' => false];
                    return billingIssueInvoice($db, $client, (float) $client['monthly_value'], $dueDate, $request);
                });
                if ($invoice['created']) $generated++;
                $delivery = billingDeliverInvoice($db, $invoice, $client, 'new', $mail, $whatsapp);
                if ($delivery['email'] === 'sent' || $delivery['whatsapp'] === 'accepted') $deliveredNow[(int) $invoice['id']] = true;
                if ($delivery['errors']) $errors[] = ['client_id' => $client['id'], 'due_date' => $dueDate, 'delivery' => $delivery];
            } catch (InvalidArgumentException $e) {
                // Cadastro incompleto (sem CPF/CNPJ, sem Asaas, valor abaixo do mínimo): o
                // próximo vencimento falharia igual, então registra uma vez e passa ao cliente seguinte.
                $errors[] = ['client_id' => $client['id'], 'due_date' => $dueDate, 'error' => $e->getMessage()];
                break;
            } catch (Throwable $e) {
                $errors[] = ['client_id' => $client['id'], 'due_date' => $dueDate, 'error' => $e->getMessage()];
            }
        }
    }

    if (billingShouldRemind($today)) {
        $stmt = $db->prepare("SELECT i.*, c.name, c.email, c.whatsapp FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.status IN ('PENDING', 'OVERDUE') AND c.status = 'active' AND i.due_date BETWEEN ? AND ?");
        $stmt->execute([$today->format('Y-m-01'), $today->format('Y-m-t')]);
        foreach ($stmt->fetchAll() as $invoice) {
            // created_at é gravado pelo MySQL no fuso da sessão, que pode diferir do do PHP em
            // algumas horas: a margem de BILLING_REMINDER_MIN_AGE absorve isso. Data ilegível
            // vira idade enorme, e o lembrete sai como sempre saiu.
            $age = $today->getTimestamp() - (int) strtotime((string) ($invoice['created_at'] ?? ''));
            if ($age < BILLING_REMINDER_MIN_AGE) continue;
            // A idade olha a criacao, nao a entrega: com o SMTP fora do ar por dias, a fatura
            // e velha quando o "Sua Fatura Mensal" finalmente sai, e o lembrete iria atras
            // dele no mesmo ciclo.
            if (isset($deliveredNow[(int) $invoice['id']])) continue;
            $client = ['id' => $invoice['client_id'], 'name' => $invoice['name'], 'email' => $invoice['email'], 'whatsapp' => $invoice['whatsapp']];
            $delivery = billingDeliverInvoice($db, $invoice, $client, 'reminder:' . $today->format('Y-m-d'), $mail, $whatsapp);
            if ($delivery['email'] === 'sent' || $delivery['whatsapp'] === 'accepted') $reminders++;
            if ($delivery['errors']) $errors[] = ['invoice_id' => $invoice['id'], 'delivery' => $delivery];
        }
    }

    return ['clients' => count($clients), 'generated' => $generated, 'reminders' => $reminders, 'errors' => $errors];
}

/**
 * Clientes ativos com valor mensal que ficaram SEM fatura neste mês e que o ciclo não
 * consegue mais emitir sozinho, porque o vencimento do mês já passou (o Asaas recusa data
 * anterior a hoje). É o caso do cliente cadastrado, ou do disparo atrasado, depois do dia
 * de vencimento: sem esta lista a falha era silenciosa e a tela dizia "Em dia".
 *
 * Fatura cancelada conta como "tem fatura": cancelar foi decisão de quem opera.
 *
 * @return list<array{client_id:int, name:string, due_day:int, value:float, due_date:string}>
 */
function billingClientsWithoutInvoice(PDO $db, DateTimeImmutable $today): array
{
    $missing = [];
    $clients = $db->query("SELECT id, name, due_day, monthly_value FROM clients WHERE monthly_value > 0 AND status = 'active' ORDER BY name, id")->fetchAll();
    foreach ($clients as $client) {
        $dueDate = billingDueDateInMonth($today, (int) $client['due_day']);
        if ($dueDate >= $today->format('Y-m-d')) continue;
        if (billingFindMonthInvoice($db, (int) $client['id'], $dueDate) !== null) continue;
        $missing[] = [
            'client_id' => (int) $client['id'],
            'name' => (string) $client['name'],
            'due_day' => (int) $client['due_day'],
            'value' => (float) $client['monthly_value'],
            'due_date' => $dueDate,
        ];
    }
    return $missing;
}

/**
 * Deixa registrado que o ciclo rodou, e com que resultado.
 *
 * É o único sinal de que o agendamento do servidor existe: a tela de Faturas lê
 * este registro e avisa quando ele some. O horário é gravado pelo PHP, em UTC, e não
 * por `CURRENT_TIMESTAMP`, pelo mesmo motivo das tabelas do WhatsApp: o fuso da sessão
 * do MySQL não é necessariamente o do PHP.
 *
 * Falha em gravar não derruba o cron: as faturas já foram emitidas.
 *
 * @param array{generated:int, reminders:int, errors:list<mixed>} $summary
 */
function billingRecordRun(PDO $db, array $summary): void
{
    try {
        $description = json_encode([
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'generated' => $summary['generated'],
            'reminders' => $summary['reminders'],
            'errors' => count($summary['errors']),
        ]);
        $stmt = $db->prepare("INSERT INTO activity_logs (action, description, performed_by_login, ip_address, user_agent) VALUES ('billing_cron_run', ?, 'billing', ?, 'EcoletaBilling/1.0')");
        $stmt->execute([$description, apiClientIp()]);
    } catch (Throwable $e) {
        error_log('Não consegui registrar a execução do faturamento: ' . $e->getMessage());
    }
}

/**
 * A última execução registrada do ciclo, ou null se nunca houve (ou se a leitura falhar).
 *
 * @return array{at:string, generated:int, reminders:int, errors:int}|null
 */
function billingLastRun(PDO $db): ?array
{
    try {
        $stmt = $db->query("SELECT description FROM activity_logs WHERE action = 'billing_cron_run' ORDER BY id DESC LIMIT 1");
        $data = json_decode((string) $stmt->fetchColumn(), true);
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($data) || !isset($data['at']) || !is_string($data['at'])) return null;

    return [
        'at' => $data['at'],
        'generated' => (int) ($data['generated'] ?? 0),
        'reminders' => (int) ($data['reminders'] ?? 0),
        'errors' => (int) ($data['errors'] ?? 0),
    ];
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
