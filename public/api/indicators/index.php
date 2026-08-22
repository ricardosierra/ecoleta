<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

$db = getDbConnection();

// GET: Retorna os indicadores (aberto para renderização no site ou consulta no dashboard)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $category = trim($_GET['category'] ?? '');

    if ($category !== '') {
        $stmt = $db->prepare("
            SELECT id, key_name, label, value, numeric_value, category, order_index, updated_at
            FROM site_indicators
            WHERE category = ?
            ORDER BY order_index ASC, id ASC
        ");
        $stmt->execute([$category]);
    } else {
        $stmt = $db->query("
            SELECT id, key_name, label, value, numeric_value, category, order_index, updated_at
            FROM site_indicators
            ORDER BY category ASC, order_index ASC, id ASC
        ");
    }

    $indicators = $stmt->fetchAll();

    // Cria também um mapa associativo [key_name => value] para conveniência do frontend
    $map = [];
    foreach ($indicators as $ind) {
        $map[$ind['key_name']] = [
            'value' => $ind['value'],
            'numeric_value' => $ind['numeric_value'] !== null ? (int)$ind['numeric_value'] : null,
            'label' => $ind['label']
        ];
    }

    echo json_encode([
        'ok' => true,
        'indicators' => $indicators,
        'map' => $map
    ]);
    exit;
}

// POST: Salvar / Atualizar indicadores em lote (requer root ou master)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['root', 'master'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso negado. Apenas administradores podem alterar indicadores.']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];

    $items = $body['indicators'] ?? [];
    if (!is_array($items) || empty($items)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nenhum indicador fornecido para atualização.']);
        exit;
    }

    $updateStmt = $db->prepare("
        UPDATE site_indicators
        SET value = ?, numeric_value = ?, label = COALESCE(?, label)
        WHERE key_name = ?
    ");

    $updatedKeys = [];

    try {
        $db->beginTransaction();
        foreach ($items as $item) {
            $keyName = trim($item['key_name'] ?? '');
            $value = trim($item['value'] ?? '');
            $numVal = isset($item['numeric_value']) && $item['numeric_value'] !== '' ? (int)$item['numeric_value'] : null;
            $label = isset($item['label']) && trim($item['label']) !== '' ? trim($item['label']) : null;

            if ($keyName !== '' && $value !== '') {
                $updateStmt->execute([$value, $numVal, $label, $keyName]);
                $updatedKeys[] = $keyName;
            }
        }
        $db->commit();

        $operatorLogin = $_SESSION['login'] ?? 'admin';
        $operatorRole = $_SESSION['role'];
        $operatorId = (int)$_SESSION['user_id'];

        logActivity(
            $db,
            null,
            'update_indicators',
            "Indicadores atualizados (" . count($updatedKeys) . " métricas) por {$operatorLogin} ({$operatorRole})",
            $operatorId,
            $operatorLogin,
            implode(', ', array_slice($updatedKeys, 0, 5))
        );

        echo json_encode([
            'ok' => true,
            'updated_count' => count($updatedKeys),
            'message' => 'Indicadores atualizados com sucesso.'
        ]);
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao salvar indicadores no banco de dados.']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido.']);
