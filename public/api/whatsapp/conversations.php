<?php
declare(strict_types=1);

/**
 * Gestão de conversas do painel de WhatsApp.
 *
 * GET  - Lista de conversas com suporte a filtros (status, unread, q) e sincronização de clientes.
 * POST - Iniciar nova conversa (action=start) ou alternar status (action=toggle_status).
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';
require_once __DIR__ . '/../whatsapp_store.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();

$db = getDbConnection();
waRequirePanelAccess($db);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        apiJsonResponse(400, ['error' => 'Payload JSON inválido.']);
    }

    $action = (string) ($body['action'] ?? 'start');

    if ($action === 'toggle_status') {
        $conversationId = (int) ($body['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            apiJsonResponse(400, ['error' => 'Conversa inválida.']);
        }

        $stmt = $db->prepare('SELECT id, status FROM whatsapp_conversations WHERE id = ? LIMIT 1');
        $stmt->execute([$conversationId]);
        $row = $stmt->fetch();
        if (!$row) {
            apiJsonResponse(404, ['error' => 'Conversa não encontrada.']);
        }

        $nextStatus = ($row['status'] === 'closed') ? 'open' : 'closed';
        if (isset($body['status']) && in_array($body['status'], ['open', 'closed'], true)) {
            $nextStatus = $body['status'];
        }

        $up = $db->prepare('UPDATE whatsapp_conversations SET status = ?, updated_at = ? WHERE id = ?');
        $up->execute([$nextStatus, waNow(), $conversationId]);

        apiJsonResponse(200, ['ok' => true, 'status' => $nextStatus]);
    }

    if ($action === 'start') {
        $phone = trim((string) ($body['phone'] ?? ''));
        $clientId = isset($body['client_id']) && is_numeric($body['client_id']) ? (int) $body['client_id'] : null;
        $name = trim((string) ($body['name'] ?? ''));

        if ($clientId !== null && $clientId > 0) {
            $stmt = $db->prepare('SELECT id, name, whatsapp FROM clients WHERE id = ? LIMIT 1');
            $stmt->execute([$clientId]);
            $client = $stmt->fetch();
            if ($client) {
                if ($phone === '') {
                    $phone = (string) ($client['whatsapp'] ?? '');
                }
                if ($name === '') {
                    $name = (string) ($client['name'] ?? '');
                }
            }
        }

        $phone = normalizePhone($phone);
        if ($phone === '' || strlen($phone) < 8) {
            apiJsonResponse(400, ['error' => 'Telefone de WhatsApp inválido.']);
        }

        $conversationId = waEnsureConversation($db, $phone, [
            'client_id' => $clientId,
            'profile_name' => $name !== '' ? $name : null,
        ]);

        if ($conversationId === null) {
            apiJsonResponse(500, ['error' => 'Não foi possível iniciar a conversa.']);
        }

        // Reabre caso estivesse encerrada
        $db->prepare("UPDATE whatsapp_conversations SET status = 'open', updated_at = ? WHERE id = ?")
            ->execute([waNow(), $conversationId]);

        $stmt = $db->prepare('
            SELECT c.*, cl.name AS client_name
              FROM whatsapp_conversations c
              LEFT JOIN clients cl ON cl.id = c.client_id
             WHERE c.id = ?
             LIMIT 1
        ');
        $stmt->execute([$conversationId]);
        $row = $stmt->fetch();

        $agora = waNow();
        $conversa = [
            'id' => (int) $row['id'],
            'phone' => (string) $row['phone'],
            'name' => (string) ($row['client_name'] ?? $row['profile_name'] ?? ''),
            'profile_name' => $row['profile_name'],
            'client_id' => $row['client_id'] === null ? null : (int) $row['client_id'],
            'client_name' => $row['client_name'],
            'status' => (string) $row['status'],
            'unread_count' => (int) $row['unread_count'],
            'last_message_at' => waToIso($row['last_message_at']),
            'last_message_preview' => $row['last_message_preview'],
            'last_message_direction' => $row['last_message_direction'],
            'window' => waWindowState($row['service_window_expires_at'], $agora),
        ];

        apiJsonResponse(200, ['ok' => true, 'conversation' => $conversa]);
    }

    apiJsonResponse(400, ['error' => 'Ação não suportada.']);
}

if ($method !== 'GET') {
    apiJsonResponse(405, ['error' => 'Método não permitido.'], ['Allow: GET, POST']);
}

// ── 1. Unificação de conversas duplicadas (12 e 13 dígitos para o mesmo celular) ──
// Mantém a de menor id; waMergeConversations() leva o estado da outra junto
// (janela, não lidas, status, perfil, cliente) antes de apagá-la.
try {
    $dupQuery = $db->query("
        SELECT c1.id AS id1, c2.id AS id2
          FROM whatsapp_conversations c1
          JOIN whatsapp_conversations c2
            ON c1.id < c2.id
           AND c1.phone LIKE '55%' AND c2.phone LIKE '55%'
           AND (
               (LENGTH(c1.phone) = 13 AND LENGTH(c2.phone) = 12 AND SUBSTR(c1.phone, 1, 4) = SUBSTR(c2.phone, 1, 4) AND SUBSTR(c1.phone, 6) = SUBSTR(c2.phone, 5))
               OR
               (LENGTH(c1.phone) = 12 AND LENGTH(c2.phone) = 13 AND SUBSTR(c1.phone, 1, 4) = SUBSTR(c2.phone, 1, 4) AND SUBSTR(c1.phone, 5) = SUBSTR(c2.phone, 6))
           )
    ");
    if ($dupQuery) {
        foreach ($dupQuery->fetchAll() as $dup) {
            waMergeConversations($db, (int) $dup['id1'], (int) $dup['id2']);
        }
    }
} catch (\Throwable $e) {
    error_log('Falha na deduplicação de conversas WhatsApp: ' . $e->getMessage());
}

// ── 2. Backfill de OSs enviadas por WhatsApp para o histórico de mensagens ─────
try {
    $osBackfill = $db->query("
        SELECT s.id, s.client_id, s.whatsapp_sent_at, s.whatsapp_sent_to, c.name AS client_name
          FROM service_orders s
          LEFT JOIN clients c ON c.id = s.client_id
         WHERE s.whatsapp_sent_at IS NOT NULL 
           AND s.whatsapp_sent_to IS NOT NULL AND s.whatsapp_sent_to != ''
           AND NOT EXISTS (
               SELECT 1 FROM whatsapp_messages wm WHERE wm.service_order_id = s.id
           )
         ORDER BY s.id ASC
         LIMIT 50
    ");
    if ($osBackfill) {
        foreach ($osBackfill->fetchAll() as $os) {
            $dest = (string) $os['whatsapp_sent_to'];
            $convId = waEnsureConversation($db, $dest, [
                'client_id' => $os['client_id'] !== null ? (int) $os['client_id'] : null,
                'profile_name' => (string) ($os['client_name'] ?? ''),
            ]);
            if ($convId !== null) {
                $msgAt = (string) $os['whatsapp_sent_at'];
                waRecordMessage($db, $convId, [
                    'direction' => 'outgoing',
                    'type' => 'template',
                    'status' => 'delivered',
                    'body' => sprintf('Ordem de Serviço Nº %05d enviada por WhatsApp.', (int) $os['id']),
                    'message_at' => $msgAt,
                    'service_order_id' => (int) $os['id'],
                ]);
            }
        }
    }
} catch (\Throwable $e) {
    error_log('Falha no backfill de OS para WhatsApp: ' . $e->getMessage());
}

// ── 3. Sincronização automática de clientes com WhatsApp cadastrado ───────────
// Só cria conversa para quem não tem nenhuma NAQUELE NÚMERO. Dois clientes podem
// cadastrar o mesmo telefone: o segundo não ganha conversa própria (o telefone é
// único) nem rouba a do primeiro, senão a conversa trocava de dono a cada GET.
try {
    $syncStmt = $db->query("
        SELECT c.id, c.name, c.whatsapp
          FROM clients c
         WHERE c.whatsapp IS NOT NULL AND c.whatsapp != ''
           AND NOT EXISTS (
               SELECT 1 FROM whatsapp_conversations wc WHERE wc.client_id = c.id
           )
         ORDER BY c.id ASC
    ");
    if ($syncStmt) {
        foreach ($syncStmt->fetchAll() as $cl) {
            $existente = waFindConversationByPhone($db, (string) $cl['whatsapp']);
            if ($existente !== null && $existente['client_id'] !== null) {
                continue;
            }

            waEnsureConversation($db, (string) $cl['whatsapp'], [
                'client_id' => (int) $cl['id'],
                'profile_name' => (string) $cl['name'],
            ]);
        }
    }
} catch (\Throwable $e) {
    error_log('Falha na sincronização de clientes WhatsApp: ' . $e->getMessage());
}

$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$unreadOnly = (string) ($_GET['unread'] ?? '') === '1';
$search = trim((string) ($_GET['q'] ?? ''));

$where = [];
$params = [];

if (in_array($statusFilter, ['open', 'closed'], true)) {
    $where[] = 'c.status = ?';
    $params[] = $statusFilter;
}

if ($unreadOnly) {
    $where[] = 'c.unread_count > 0';
}

if ($search !== '') {
    $termo = '%' . $search . '%';
    // Só procura no telefone quando o termo É um telefone (dígitos e a pontuação
    // de máscara). "Posto 3" é nome com número: tratar o 3 como telefone casava
    // com toda conversa cujo número tivesse um 3.
    $pareceTelefone = preg_match('/^[\d\s().+\-]+$/', $search) === 1;
    $digitos = $pareceTelefone ? (preg_replace('/\D/', '', $search) ?? '') : '';
    if ($digitos !== '') {
        $where[] = '(cl.name LIKE ? OR c.profile_name LIKE ? OR c.phone LIKE ? OR c.last_message_preview LIKE ?)';
        $params[] = $termo;
        $params[] = $termo;
        $params[] = '%' . $digitos . '%';
        $params[] = $termo;
    } else {
        $where[] = '(cl.name LIKE ? OR c.profile_name LIKE ? OR c.last_message_preview LIKE ?)';
        $params[] = $termo;
        $params[] = $termo;
        $params[] = $termo;
    }
}

$whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

// A lista é cortada nas conversas mais recentes. A resposta diz quantas existem
// de verdade, e a tela avisa — sem isso, a conversa 301 em diante simplesmente
// não existia para quem olhava. A busca (`q`) roda aqui, no banco inteiro.
const WA_CONVERSATION_LIST_LIMIT = 300;
const WA_CLIENT_LIST_LIMIT = 200;

$countStmt = $db->prepare("
    SELECT COUNT(*)
      FROM whatsapp_conversations c
      LEFT JOIN clients cl ON cl.id = c.client_id
      {$whereClause}
");
$countStmt->execute($params);
$totalConversas = (int) $countStmt->fetchColumn();

$sql = "
    SELECT c.id, c.phone, c.wa_id, c.profile_name, c.client_id, c.status, c.unread_count,
           c.last_inbound_at, c.last_message_at, c.last_message_preview, c.last_message_direction,
           c.service_window_expires_at, c.created_at,
           cl.name AS client_name
      FROM whatsapp_conversations c
      LEFT JOIN clients cl ON cl.id = c.client_id
      {$whereClause}
     ORDER BY (c.last_message_at IS NULL), c.last_message_at DESC, c.id DESC
     LIMIT " . WA_CONVERSATION_LIST_LIMIT . "
";

$stmt = $db->prepare($sql);
$stmt->execute($params);

$agora = waNow();
$conversas = [];

foreach ($stmt->fetchAll() as $linha) {
    $conversas[] = [
        'id' => (int) $linha['id'],
        'phone' => (string) $linha['phone'],
        'name' => (string) ($linha['client_name'] ?? $linha['profile_name'] ?? ''),
        'profile_name' => $linha['profile_name'],
        'client_id' => $linha['client_id'] === null ? null : (int) $linha['client_id'],
        'client_name' => $linha['client_name'],
        'status' => (string) $linha['status'],
        'unread_count' => (int) $linha['unread_count'],
        'last_message_at' => waToIso($linha['last_message_at']),
        'last_message_preview' => $linha['last_message_preview'],
        'last_message_direction' => $linha['last_message_direction'],
        'window' => waWindowState($linha['service_window_expires_at'], $agora),
    ];
}

$clientesCadastrados = [];
$totalClientes = 0;
try {
    $totalClientes = (int) $db->query("SELECT COUNT(*) FROM clients WHERE whatsapp IS NOT NULL AND whatsapp != ''")->fetchColumn();
    $clStmt = $db->query("SELECT id, name, whatsapp FROM clients WHERE whatsapp IS NOT NULL AND whatsapp != '' ORDER BY name ASC LIMIT " . WA_CLIENT_LIST_LIMIT);
    if ($clStmt) {
        foreach ($clStmt->fetchAll() as $cl) {
            $clientesCadastrados[] = [
                'id' => (int) $cl['id'],
                'name' => (string) $cl['name'],
                'phone' => (string) $cl['whatsapp'],
            ];
        }
    }
} catch (\Throwable) {
}

apiJsonResponse(200, [
    'ok' => true,
    'conversations' => $conversas,
    'total' => $totalConversas,
    'truncated' => $totalConversas > count($conversas),
    'clients' => $clientesCadastrados,
    'clients_total' => $totalClientes,
]);
