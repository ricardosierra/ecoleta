<?php
declare(strict_types=1);

/**
 * Listagem e criação de Ordens de Serviço.
 *
 * Exige administrador nos dois métodos. A tela (`app/dashboard/os/page.tsx`)
 * sempre foi só de admin, mas o backend aceitava qualquer sessão — e a partir
 * da migration 014 a resposta carrega o `share_token` de cada OS, que é o
 * segredo do link público. Deixar isso legível para um papel `user`, que no
 * dashboard só enxerga o próprio painel, entregaria de graça todas as OS já
 * emitidas.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';
require_once __DIR__ . '/../whatsapp_store.php';
require_once __DIR__ . '/os_lib.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();
$operator = apiRequireAdmin();

$db = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query('
        SELECT o.id, o.client_id, o.weight, o.collection_date, o.bags_count, o.containers_count,
               o.responsible, o.signature_text, o.share_token, o.sent_at, o.sent_to,
               o.whatsapp_sent_at, o.whatsapp_sent_to, o.created_at,
               c.name AS client_name, c.email AS client_email, c.whatsapp AS client_whatsapp
          FROM service_orders o
          JOIN clients c ON c.id = o.client_id
         ORDER BY o.id DESC
    ');

    $linhas = $stmt->fetchAll();

    // Uma consulta só para a janela de todos os clientes da lista. Perguntar por
    // OS faria uma consulta por linha da tabela.
    $janelas = waWindowsByPhone($db, array_column($linhas, 'client_whatsapp'));

    $baseUrl = osBaseUrl();
    $ordens = [];
    foreach ($linhas as $row) {
        $telefone = normalizePhone((string) ($row['client_whatsapp'] ?? ''));
        $ordens[] = osPresent($row, $baseUrl, $janelas[$telefone] ?? null);
    }

    apiJsonResponse(200, ['ok' => true, 'service_orders' => $ordens]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }

    $clientId = (int) ($body['client_id'] ?? 0);
    $weight = trim((string) ($body['weight'] ?? ''));
    $collectionDate = trim((string) ($body['collection_date'] ?? ''));
    $bagsCount = isset($body['bags_count']) && $body['bags_count'] !== '' ? (int) $body['bags_count'] : null;
    $containersCount = isset($body['containers_count']) && $body['containers_count'] !== '' ? (int) $body['containers_count'] : null;
    $responsible = trim((string) ($body['responsible'] ?? ''));

    if (!$clientId) {
        apiJsonResponse(400, ['error' => 'Cliente é obrigatório.']);
    }

    $clientCheck = $db->prepare('SELECT id FROM clients WHERE id = ?');
    $clientCheck->execute([$clientId]);
    if (!$clientCheck->fetch()) apiJsonResponse(404, ['error' => 'Cliente não encontrado.']);
    if ($collectionDate !== '') {
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $collectionDate);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $collectionDate) {
            apiJsonResponse(400, ['error' => 'Data de coleta inválida.']);
        }
    }
    foreach (['bags_count', 'containers_count'] as $field) {
        if (isset($body[$field]) && $body[$field] !== '' &&
            (filter_var($body[$field], FILTER_VALIDATE_INT) === false || (int) $body[$field] < 0)) {
            apiJsonResponse(400, ['error' => 'As quantidades devem ser números inteiros não negativos.']);
        }
    }

    $colDate = $collectionDate !== '' ? $collectionDate : null;

    try {
        $stmt = $db->prepare('
            INSERT INTO service_orders
                (client_id, weight, collection_date, bags_count, containers_count, responsible, share_token)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $clientId,
            $weight,
            $colDate,
            $bagsCount,
            $containersCount,
            $responsible,
            osShareTokenNew(),
        ]);

        $id = (int) $db->lastInsertId();
    } catch (PDOException $e) {
        error_log('Falha ao criar OS: ' . $e->getMessage());
        apiJsonResponse(500, ['error' => 'Erro ao criar OS.']);
    }

    // Relê a linha gravada em vez de devolver o que o corpo mandou: a tela
    // desenha o documento a partir desta resposta, e o que vale é o que ficou
    // no banco (nome do cliente, signature_text, token do link).
    $criada = osFindById($db, $id);
    $janela = null;
    if ($criada !== null) {
        $conversa = waFindConversationByPhone($db, (string) ($criada['client_whatsapp'] ?? ''));
        $janela = $conversa === null
            ? null
            : waWindowState($conversa['service_window_expires_at'] ?? null);
    }

    apiJsonResponse(200, [
        'ok' => true,
        'id' => $id,
        'service_order' => $criada === null ? null : osPresent($criada, osBaseUrl(), $janela),
    ]);
}

apiJsonResponse(405, ['error' => 'Método não permitido.'], ['Allow: GET, POST']);
