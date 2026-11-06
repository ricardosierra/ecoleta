<?php
declare(strict_types=1);

/**
 * Listagem de faturas — histórico financeiro de todos os clientes.
 *
 * Exige administrador, como a tela (`app/dashboard/faturas/page.tsx`) já fazia
 * no navegador. A resposta traz valor, vencimento, status e o Pix Copia e Cola
 * de cada cobrança: nada disso é assunto de uma conta `user`.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';
require_once __DIR__ . '/../billing_delivery.php';

/** O que a tela mostra quando a falha é nossa e não da integração. */
const INVOICES_GENERIC_ERROR = 'Não foi possível concluir a operação agora. Tente novamente em instantes.';

/** A proteção mensal detectou uma cobrança existente sem confirmação da operadora. */
final class InvoiceMonthAlreadyExistsException extends RuntimeException
{
}

/**
 * Registra uma falha interna sem o texto dela: a mensagem de um erro de banco
 * pode carregar dado pessoal. Classe, código e local bastam para achar a causa.
 */
function invoicesLogInternalError(Throwable $e): void
{
    error_log(sprintf(
        'Faturas: falha interna %s (código %s) em %s:%d',
        get_class($e),
        (string) $e->getCode(),
        basename($e->getFile()),
        $e->getLine()
    ));
}

/**
 * Estado da entrega por canal e fatura.
 * `uncertain` exige conferência no provedor antes de uma nova tentativa: a chamada
 * pode ter sido aceita mesmo que o PHP tenha sido interrompido antes de registrar o resultado.
 *
 * @return array<int, array{email:string, whatsapp:string}>
 */
function invoicesDeliveryStates(PDO $db): array
{
    $stmt = $db->query("
        SELECT a.action, a.description, a.target_login, w.status AS whatsapp_status, w.error_message AS whatsapp_error
        FROM activity_logs a
        LEFT JOIN whatsapp_messages w ON w.wa_message_id = a.target_login
        WHERE a.action IN ('billing_delivery', 'billing_attempt', 'billing_failed')
          AND a.description LIKE 'invoice:%'
        ORDER BY a.id
    ");

    $states = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!preg_match('/^invoice:(\\d+):(.+):(email|whatsapp)$/', (string) $row['description'], $match)) {
            continue;
        }

        $invoiceId = (int) $match[1];
        $channel = $match[3];
        $states[$invoiceId][$channel] ??= [
            'last_action' => null,
            'has_success' => false,
        ];
        $states[$invoiceId][$channel]['last_action'] = (string) $row['action'];

        if ($row['action'] === 'billing_delivery') {
            $failed = $channel === 'whatsapp' && strtolower((string) ($row['whatsapp_status'] ?? '')) === 'failed';
            $states[$invoiceId][$channel]['has_success'] = !$failed;
            $states[$invoiceId][$channel]['error'] = $failed ? trim((string) ($row['whatsapp_error'] ?? '')) : null;
        }
    }

    $result = [];
    foreach ($states as $invoiceId => $channels) {
        foreach (['email', 'whatsapp'] as $channel) {
            $state = $channels[$channel] ?? null;
            if ($state === null) {
                $result[$invoiceId][$channel] = 'never_sent';
            } elseif ($state['last_action'] === 'billing_attempt') {
                $result[$invoiceId][$channel] = 'uncertain';
            } elseif ($state['last_action'] === 'billing_failed'
                || ($channel === 'whatsapp' && empty($state['has_success']) && array_key_exists('error', $state))) {
                $result[$invoiceId][$channel] = 'failed';
                $erro = trim((string) ($state['error'] ?? ''));
                if ($channel === 'whatsapp') {
                    $result[$invoiceId]['whatsapp_error'] = $erro !== '' ? $erro : null;
                } else {
                    $result[$invoiceId]['email_error'] = $erro !== '' ? $erro : null;
                }
            } else {
                $result[$invoiceId][$channel] = $state['has_success'] ? 'sent' : 'never_sent';
            }
        }
    }

    return $result;
}

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();
$operator = apiRequireAdmin();

$db = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("
        SELECT i.*, c.name as client_name, c.email as client_email, c.due_day, c.monthly_value,
               c.whatsapp AS client_whatsapp,
               (
                   SELECT wc.id FROM whatsapp_conversations wc
                    WHERE wc.client_id = c.id
                    ORDER BY (wc.last_message_at IS NULL), wc.last_message_at DESC, wc.id DESC
                    LIMIT 1
               ) AS whatsapp_conversation_id
        FROM invoices i
        JOIN clients c ON i.client_id = c.id
        ORDER BY i.due_date DESC
    ");
    // `billing_cron` é a última execução do faturamento automático (ou null): a tela
    // avisa a operadora quando o agendamento do servidor nunca rodou ou parou.
    // `billing_missing` lista os clientes ativos sem fatura no mes cujo vencimento ja passou
    // (o ciclo nao emite data passada) e `today` e a data de Brasilia que a tela sugere
    // como novo vencimento: nada disso e calculado no navegador, que pode estar em outro fuso.
    $today = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $invoices = $stmt->fetchAll();
    $deliveryStates = invoicesDeliveryStates($db);
    $origins = billingInvoiceOrigins($db);
    foreach ($invoices as &$invoice) {
        $invoice['origin'] = $origins[(int) $invoice['id']] ?? billingInferInvoiceOrigin($invoice);
        $conversationId = $invoice['whatsapp_conversation_id'] ?? null;
        $invoice['whatsapp_conversation_id'] = $conversationId === null || $conversationId === ''
            ? null
            : (int) $conversationId;
        $whatsapp = trim((string) ($invoice['client_whatsapp'] ?? ''));
        $invoice['client_whatsapp'] = $whatsapp === '' ? null : $whatsapp;
        // Texto do "Meu WhatsApp" (botão direito na tela): só fatura em aberto, como os envios.
        // Vem pronto do servidor para o link abrir no clique, sem chamada antes do popup.
        $invoice['whatsapp_text'] = in_array($invoice['status'], ['PENDING', 'OVERDUE'], true)
            ? billingShareText(
                (string) $invoice['client_name'],
                $invoice['value'],
                (string) $invoice['due_date'],
                (string) ($invoice['pix_qrcode_text'] ?? ''),
                (string) ($invoice['invoice_url'] ?? ''),
                $invoice['status'] === 'OVERDUE'
            )
            : null;
        $invoice['delivery'] = $deliveryStates[(int) $invoice['id']] ?? [
            'email' => 'never_sent',
            'whatsapp' => 'never_sent',
        ];
    }
    unset($invoice);
    echo json_encode([
        'ok' => true,
        'invoices' => $invoices,
        'billing_cron' => billingLastRun($db),
        'billing_missing' => billingClientsWithoutInvoice($db, $today),
        'today' => $today->format('Y-m-d'),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A emissão fala com Asaas, SMTP e WhatsApp em sequência. O cron (CLI) não
    // tem teto; o PHP da hospedagem corta o request da tela e a cobrança ficava
    // gravada sem o e-mail ter saído.
    ignore_user_abort(true);
    set_time_limit(120);
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) apiJsonResponse(400, ['error' => 'Dados inválidos.']);
    $action = $body['action'] ?? 'create';
    $channelActions = null;
    if ($action === 'deliver') {
        $channelActions = $body['channel_actions'] ?? null;
        if (!is_array($channelActions) || $channelActions === []) {
            apiJsonResponse(400, ['error' => 'Escolha ao menos um canal para enviar.']);
        }
        foreach ($channelActions as $channel => $mode) {
            if (!in_array($channel, ['email', 'whatsapp'], true) || !in_array($mode, ['send', 'resend'], true)) {
                apiJsonResponse(400, ['error' => 'Ação de envio inválida.']);
            }
        }
    }
    try {
        if ($action === 'cancel') {
            require_once __DIR__ . '/../asaas_lib.php';
            $stmt = $db->prepare('SELECT * FROM invoices WHERE id = ?');
            $stmt->execute([(int) ($body['id'] ?? 0)]);
            $invoice = $stmt->fetch();
            if (!$invoice) apiJsonResponse(404, ['error' => 'Fatura não encontrada.']);
            if ($invoice['status'] === 'DELETED') {
                apiJsonResponse(200, ['ok' => true, 'message' => 'Fatura já se encontra cancelada.']);
            }
            if (in_array($invoice['status'], ['RECEIVED', 'CONFIRMED'], true)) {
                apiJsonResponse(400, ['error' => 'Não é possível cancelar uma fatura já paga.']);
            }

            if (!empty($invoice['asaas_payment_id'])) {
                try {
                    asaasDeletePayment((string) $invoice['asaas_payment_id']);
                } catch (Throwable $e) {
                    $msg = $e->getMessage();
                    if (stripos($msg, 'não encontrada') === false && stripos($msg, 'removida') === false) {
                        throw $e;
                    }
                }
            }

            $update = $db->prepare("UPDATE invoices SET status = 'DELETED' WHERE id = ?");
            $update->execute([$invoice['id']]);

            try {
                $log = $db->prepare("INSERT INTO activity_logs (action, description, performed_by_login, ip_address, user_agent) VALUES ('invoice_cancelled', ?, ?, ?, ?)");
                $log->execute([
                    'Fatura #' . $invoice['id'] . ' (Asaas: ' . $invoice['asaas_payment_id'] . ') cancelada',
                    $operator['login'] ?? 'admin',
                    apiClientIp(),
                    'EcoletaDashboard/1.0'
                ]);
            } catch (Throwable $e) {
                // Log não bloqueia a resposta
            }

            apiJsonResponse(200, ['ok' => true, 'message' => 'Fatura cancelada com sucesso.']);
        }

        if ($action === 'send' || $action === 'resend_email' || $action === 'resend' || $action === 'deliver') {
            $stmt = $db->prepare('SELECT * FROM invoices WHERE id = ?');
            $stmt->execute([(int) ($body['id'] ?? 0)]);
            $invoice = $stmt->fetch();
            if (!$invoice) apiJsonResponse(404, ['error' => 'Fatura não encontrada.']);
            $clientId = $invoice['client_id'];
            if ($action === 'resend') {
                $channels = $body['channels'] ?? null;
                $invalidChannels = !is_array($channels) || !array_is_list($channels) || $channels === [];
                if (!$invalidChannels) {
                    foreach ($channels as $channel) {
                        if (!is_string($channel) || !in_array($channel, ['email', 'whatsapp'], true)) {
                            $invalidChannels = true;
                            break;
                        }
                    }
                    if (!$invalidChannels && count(array_unique($channels)) !== count($channels)) $invalidChannels = true;
                }
                if ($invalidChannels) {
                    apiJsonResponse(400, ['error' => 'Escolha e-mail, WhatsApp ou os dois canais para reenviar.']);
                }
            }
        } elseif ($action === 'create') {
            $clientId = (int) ($body['client_id'] ?? 0);
        } else {
            apiJsonResponse(400, ['error' => 'Ação inválida.']);
        }
        $stmt = $db->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();
        if (!$client) apiJsonResponse(404, ['error' => 'Cliente não encontrado.']);
        if ($action === 'create') {
            $dueDate = (string) ($body['due_date'] ?? '');
            if ($dueDate < (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d')) {
                apiJsonResponse(400, ['error' => 'O vencimento deve ser hoje ou uma data futura.']);
            }
            $allowDuplicateMonth = ($body['allow_duplicate_month'] ?? false) === true;
            $invoice = billingLocked($db, 'month:' . $clientId . ':' . substr($dueDate, 0, 7), function () use ($db, $client, $clientId, $dueDate, $body, $allowDuplicateMonth): array {
                $existing = billingFindMonthInvoice($db, $clientId, $dueDate);
                if ($existing !== null && $existing['status'] !== 'DELETED') {
                    $requestedValue = round((float) ($body['value'] ?? $client['monthly_value']), 2);
                    if ($existing['due_date'] === $dueDate && abs((float) $existing['value'] - $requestedValue) < 0.001) {
                        if (!in_array($existing['status'], ['PENDING', 'OVERDUE'], true)) {
                            $situacao = ['RECEIVED' => 'paga', 'CONFIRMED' => 'confirmada', 'REFUNDED' => 'estornada', 'CHARGEBACK_REQUESTED' => 'contestada'][$existing['status']] ?? 'encerrada';
                            throw new InvoiceMonthAlreadyExistsException('Já existe uma fatura ' . $situacao . ' deste cliente para este vencimento. Escolha outra data.');
                        }
                        throw new InvoiceMonthAlreadyExistsException('Já existe uma fatura com este vencimento e valor. Use os botões de envio da fatura existente em vez de criar outra.');
                    }
                    if (!$allowDuplicateMonth) {
                        throw new InvoiceMonthAlreadyExistsException('Este cliente já possui uma fatura neste mês. Confirme a criação da fatura avulsa antes de continuar.');
                    }
                }
                return billingIssueInvoice($db, $client, (float) ($body['value'] ?? $client['monthly_value']), $dueDate, null, 'manual');
            });
            // Reaproveitada e já quitada (ou estornada): não há o que enviar. Dizer que
            // "as notificações foram processadas" para uma fatura paga engana quem gerou.
            if (!$invoice['created'] && !in_array($invoice['status'], ['PENDING', 'OVERDUE'], true)) {
                $situacao = ['RECEIVED' => 'paga', 'CONFIRMED' => 'confirmada', 'REFUNDED' => 'estornada', 'CHARGEBACK_REQUESTED' => 'contestada'][$invoice['status']] ?? 'encerrada';
                apiJsonResponse(409, ['error' => 'Já existe uma fatura ' . $situacao . ' deste cliente para este vencimento. Escolha outra data.']);
            }
        }
        if ($client['status'] !== 'active') apiJsonResponse(400, ['error' => 'Cliente inativo.']);
        // Cobrança reaproveitada (mesmo valor e vencimento) já consumiu o evento
        // `new` no ciclo automático. Sem um evento novo, "Gerar e enviar"
        // respondia sucesso e não mandava nada. "Tentar envios pendentes"
        // continua no evento `new`, para não repetir o que já saiu.
        // Reenvio do e-mail usa um evento novo e só esse canal: o template sai
        // outra vez sem repetir o WhatsApp, que a Meta cobra fora da janela.
        if ($action === 'deliver') {
            $delivery = ['email' => 'skipped', 'whatsapp' => 'skipped', 'errors' => []];
            $currentStates = invoicesDeliveryStates($db)[(int) $invoice['id']] ?? [
                'email' => 'never_sent',
                'whatsapp' => 'never_sent',
            ];
            $effectiveActions = [];
            foreach ($channelActions as $channel => $requestedMode) {
                $currentState = $currentStates[$channel] ?? 'never_sent';
                if ($currentState === 'uncertain') {
                    $delivery['errors'][$channel] = 'Resultado do envio anterior incerto. Confira o provedor antes de tentar novamente.';
                    continue;
                }
                $currentMode = $currentState === 'sent' ? 'resend' : 'send';
                if ($requestedMode !== $currentMode) {
                    $delivery['errors'][$channel] = 'O estado do envio mudou. Atualize a listagem antes de tentar novamente.';
                    continue;
                }
                $effectiveActions[$channel] = $currentMode;
            }

            foreach (['send', 'resend'] as $mode) {
                $channels = array_keys(array_filter($effectiveActions, static fn(string $channelMode): bool => $channelMode === $mode));
                if ($channels === []) continue;

                $event = $mode === 'send' ? 'new' : 'resend:' . gmdate('YmdHis');
                $part = billingDeliverInvoice($db, $invoice, $client, $event, null, null, $channels);
                foreach ($channels as $channel) {
                    $delivery[$channel] = $part[$channel] ?? 'skipped';
                    if (isset($part['errors'][$channel])) $delivery['errors'][$channel] = $part['errors'][$channel];
                }
            }
        } elseif ($action === 'resend_email') {
            $delivery = billingDeliverInvoice($db, $invoice, $client, 'resend:' . gmdate('YmdHis'), null, null, ['email']);
        } elseif ($action === 'resend') {
            $delivery = billingDeliverInvoice($db, $invoice, $client, 'resend:' . gmdate('YmdHis'), null, null, $channels);
        } else {
            $event = ($action === 'create' && empty($invoice['created']))
                ? 'manual:' . gmdate('YmdHis')
                : 'new';
            $delivery = billingDeliverInvoice($db, $invoice, $client, $event);
        }
        $delivery['whatsapp_to'] = normalizePhone($client['whatsapp'] ?? '');
        apiJsonResponse(200, ['ok' => true, 'invoice' => $invoice, 'delivery' => $delivery]);
    } catch (InvalidArgumentException $e) {
        apiJsonResponse(400, ['error' => $e->getMessage()]);
    } catch (PDOException $e) {
        // PDOException É uma RuntimeException: precisa vir antes dela. A mensagem do
        // driver traz nome de tabela e coluna, e numa chave duplicada o próprio
        // valor (e-mail, CPF). Vai para quem opera só o aviso genérico; o log
        // guarda a classe e o SQLSTATE, sem a mensagem.
        invoicesLogInternalError($e);
        apiJsonResponse(500, ['error' => INVOICES_GENERIC_ERROR]);
    } catch (InvoiceMonthAlreadyExistsException $e) {
        apiJsonResponse(409, ['error' => $e->getMessage()]);
    } catch (RuntimeException $e) {
        // Falha da integração (Asaas recusou, sem chave, fora do ar, cobrança em
        // processamento): essa mensagem é a explicação útil e segue para a tela.
        apiJsonResponse(502, ['error' => $e->getMessage()]);
    } catch (Throwable $e) {
        // Qualquer outra coisa é erro nosso (TypeError, ValueError...), e o texto
        // dele não diz nada a quem clicou.
        invoicesLogInternalError($e);
        apiJsonResponse(500, ['error' => INVOICES_GENERIC_ERROR]);
    }
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido.']);
