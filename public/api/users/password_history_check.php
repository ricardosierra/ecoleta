<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';

/**
 * Confere uma senha candidata contra todos os hashes já usados por um usuário.
 *
 * Só root. Serve para responder "a senha X valeu em algum momento?" sem
 * expor hash nenhum: a resposta diz quais registros do histórico batem e
 * quando cada um vigorou. A senha candidata não é gravada nem logada.
 */

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();

$actor = apiRequireAdmin();
if ($actor['role'] !== 'root') {
    apiJsonResponse(403, ['error' => API_ACCESS_DENIED]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiJsonResponse(405, ['error' => 'Método não permitido.']);
}

$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
$userId = (int) ($input['user_id'] ?? 0);
$candidate = (string) ($input['password'] ?? '');

if ($userId <= 0 || $candidate === '') {
    apiJsonResponse(400, ['error' => 'Informe user_id e password.']);
}

$db = getDbConnection();

$stmt = $db->prepare('
    SELECT id, old_hash, new_hash, change_type, changed_by_login, created_at
    FROM password_hash_history
    WHERE user_id = ?
    ORDER BY id ASC
');
$stmt->execute([$userId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$current = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$current->execute([$userId]);
$currentHash = $current->fetchColumn();

$matches = [];
$seen = [];
foreach ($rows as $row) {
    foreach (['old_hash', 'new_hash'] as $col) {
        $hash = (string) ($row[$col] ?? '');
        if ($hash === '' || isset($seen[$hash])) {
            continue;
        }
        $seen[$hash] = true;
        if (!password_verify($candidate, $hash)) {
            continue;
        }
        $matches[] = [
            'history_id'  => (int) $row['id'],
            'column'      => $col,
            'change_type' => (string) $row['change_type'],
            'changed_by'  => $row['changed_by_login'],
            'recorded_at' => (string) $row['created_at'],
        ];
    }
}

$matchesCurrent = is_string($currentHash) && $currentHash !== '' && password_verify($candidate, $currentHash);

logActivity(
    $db,
    $userId,
    'password_history_check',
    'Senha candidata conferida contra o histórico por ' . $actor['login'] . ' (' . count($matches) . ' hash(es) coincidentes)',
    (int) $actor['id'],
    (string) $actor['login']
);

apiJsonResponse(200, [
    'ok' => true,
    'user_id' => $userId,
    'matches_current' => $matchesCurrent,
    'matches' => $matches,
    'hashes_checked' => count($seen),
]);
