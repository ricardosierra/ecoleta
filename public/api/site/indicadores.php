<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();

$db = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("SELECT indicator_key, value, label, symbol_type, symbol_value, updated_at FROM site_indicators");
    $indicators = [];
    while ($row = $stmt->fetch()) {
        $indicators[] = [
            'key' => $row['indicator_key'],
            'value' => $row['value'],
            'label' => $row['label'],
            'symbol_type' => $row['symbol_type'],
            'symbol_value' => $row['symbol_value'],
        ];
    }
    echo json_encode(['ok' => true, 'indicators' => $indicators]);
    exit;
}

// Para qualquer método que não seja GET, exige admin.
$operator = apiRequireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    
    $key = trim($body['key'] ?? '');
    $value = trim($body['value'] ?? '');
    $label = trim($body['label'] ?? '');
    
    if (!$key || !$value || !$label) {
        http_response_code(400);
        echo json_encode(['error' => 'Chave, valor e rótulo são obrigatórios.']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("SELECT value FROM site_indicators WHERE indicator_key = ? FOR UPDATE");
        $stmt->execute([$key]);
        $old = $stmt->fetch();
        
        if (!$old) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Indicador não encontrado.']);
            exit;
        }
        
        $oldValue = $old['value'];
        
        $updateStmt = $db->prepare("UPDATE site_indicators SET value = ?, label = ? WHERE indicator_key = ?");
        $updateStmt->execute([$value, $label, $key]);
        
        if ($oldValue !== $value) {
            $histStmt = $db->prepare("INSERT INTO site_indicator_history (indicator_key, old_value, new_value, changed_by_id, changed_by_login, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
            $histStmt->execute([
                $key,
                $oldValue,
                $value,
                $operator['id'],
                $operator['login'],
                apiClientIp()
            ]);
        }
        
        $db->commit();
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Erro interno ao atualizar indicador.']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido.']);
