<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';

// O GET é público e devolve o mesmo para todo mundo, inclusive para o painel:
// não precisa de sessão. Abrir uma para cada visitante da página ESG gravava um
// arquivo no servidor e devolvia Set-Cookie à toa. Só a escrita (POST) abre.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    startSecureSession();
}
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
    $decoded = json_decode($raw, true);
    $body = is_array($decoded) ? $decoded : [];

    // Só texto. trim() num array, objeto, número ou booleano lança TypeError, e
    // isso acontecia aqui fora do try: o painel recebia a página de erro do PHP
    // em vez do JSON de mensagem. Campo ausente ou null cai no "obrigatório".
    $fields = [];
    foreach (['key', 'value', 'label'] as $field) {
        $input = $body[$field] ?? '';
        if (!is_string($input)) {
            http_response_code(400);
            echo json_encode(['error' => 'Chave, valor e rótulo devem ser texto.']);
            exit;
        }
        $fields[$field] = trim($input);
    }
    ['key' => $key, 'value' => $value, 'label' => $label] = $fields;

    // Comparação com '' e não "!$value": a string "0" é falsa no PHP, e zero é um
    // número perfeitamente válido para um indicador.
    if ($key === '' || $value === '' || $label === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Chave, valor e rótulo são obrigatórios.']);
        exit;
    }

    // Tamanhos das colunas (utf8mb4, contam caracteres): indicator_key e value
    // são VARCHAR(50), label é VARCHAR(100). Estourar derrubava o UPDATE e o
    // painel via um 500 genérico, sem dizer qual campo passou.
    $limits = [
        [$key, 50, 'A chave pode ter no máximo 50 caracteres.'],
        [$value, 50, 'O valor pode ter no máximo 50 caracteres.'],
        [$label, 100, 'O rótulo pode ter no máximo 100 caracteres.'],
    ];
    foreach ($limits as [$text, $max, $message]) {
        if (mb_strlen($text) > $max) {
            http_response_code(400);
            echo json_encode(['error' => $message]);
            exit;
        }
    }

    try {
        $db->beginTransaction();

        // FOR UPDATE trava a linha no MySQL para o histórico guardar o valor
        // antigo certo; o SQLite descartável dos testes não tem essa sintaxe.
        $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $db->prepare('SELECT value FROM site_indicators WHERE indicator_key = ?' . $lock);
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
        error_log('indicadores.php: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro interno ao atualizar indicador.']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido.']);
