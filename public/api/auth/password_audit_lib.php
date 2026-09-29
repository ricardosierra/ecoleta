<?php
declare(strict_types=1);

require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../security_alerts.php';

/**
 * Registra formalmente a alteração/criação do hash de senha de um usuário no banco
 * e envia um alerta de segurança imediato para o e-mail administrativo (sierra.csi@gmail.com).
 *
 * @param PDO $db Conexão com o banco
 * @param int $userId ID do usuário afetado
 * @param string|null $oldHash Hash anterior que foi substituído/invalidado (null para novo usuário)
 * @param string $newHash Novo hash gravado
 * @param string $changeType Tipo da alteração ('create_user', 'change_password', 'reset_password', 'direct_sql')
 * @param int|null $operatorId ID do usuário que executou a ação
 * @param string|null $operatorLogin Login do operador que executou a ação
 * @param string|null $ip IP da requisição
 * @param string|null $userAgent User-Agent da requisição
 * @return int ID do registro de histórico criado
 */
function recordPasswordHashChange(
    PDO $db,
    int $userId,
    ?string $oldHash,
    string $newHash,
    string $changeType,
    ?int $operatorId = null,
    ?string $operatorLogin = null,
    ?string $ip = null,
    ?string $userAgent = null
): int {
    $ip ??= apiClientIp();
    $userAgent ??= substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido'), 0, 255);

    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare('
        INSERT INTO password_hash_history 
            (user_id, old_hash, new_hash, changed_by_user_id, changed_by_login, change_type, ip_address, user_agent, created_at)
        VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $userId,
        $oldHash,
        $newHash,
        $operatorId,
        $operatorLogin,
        $changeType,
        $ip,
        $userAgent,
        $now,
    ]);
    $historyId = (int) $db->lastInsertId();

    // Busca detalhes do usuário para compor o alerta
    $userStmt = $db->prepare('SELECT login, email FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: ['login' => 'ID #' . $userId, 'email' => ''];

    $login = (string) ($user['login'] ?? 'desconhecido');
    $email = (string) ($user['email'] ?? '');

    $tipoLabels = [
        'create_user'     => 'Novo Usuário Cadastrado (Primeiro Hash)',
        'change_password' => 'Troca de Senha Realizada',
        'reset_password'  => 'Redefinição de Senha (Nova Senha Temporária)',
        'manual_reset'    => 'Redefinição Manual (Script de Manutenção)',
        'user_deleted'    => 'Usuário Excluído (Último Hash Preservado)',
        'direct_sql'      => 'Alteração Direta / Inesperada de Hash',
    ];
    $tipoLegivel = $tipoLabels[$changeType] ?? $changeType;

    $oldMask = $oldHash !== null && $oldHash !== ''
        ? substr($oldHash, 0, 8) . '...' . substr($oldHash, -6) . ' (INVALIDADO)'
        : 'Nenhum (primeira credencial gerada)';
    $newMask = substr($newHash, 0, 8) . '...' . substr($newHash, -6);

    $details = [
        'Usuário Afetado'       => $login,
        'E-mail'                => $email !== '' ? $email : 'Não informado',
        'Tipo de Modificação'   => $tipoLegivel,
        'Executor da Alteração' => $operatorLogin ? "{$operatorLogin}" : 'Próprio Usuário / Sistema',
        'Hash Anterior'         => $oldMask,
        'Novo Hash Ativo'       => $newMask,
        'IP de Origem'          => $ip,
        'Status da Chave'       => 'O hash de senha anterior foi INVALIDADO com sucesso no banco de dados',
    ];

    apiSendSecurityAlert(
        "Hash de Senha Alterado: {$login}",
        "Alteração e Invalidação de Hash de Senha — Usuário '{$login}'",
        $details
    );

    return $historyId;
}

/**
 * Atribui autoria à troca de hash que o trigger do banco acabou de registrar.
 *
 * O trigger `trg_users_password_change` grava toda troca com change_type
 * 'db_trigger' e sem operador. Quem troca senha pela aplicação chama esta
 * função logo depois do UPDATE: ela encontra essa linha (mesmo usuário, mesmo
 * hash novo) e preenche tipo, operador, IP e user-agent. Se não houver linha
 * do trigger (INSERT de usuário novo, banco sem o trigger), insere uma.
 *
 * Linha que continua 'db_trigger' depois disso é uma troca feita por fora da
 * aplicação — e é exatamente isso que a auditoria quer enxergar.
 */
function attributePasswordHashChange(
    PDO $db,
    int $userId,
    string $newHash,
    string $changeType,
    ?int $operatorId = null,
    ?string $operatorLogin = null
): void {
    $ip = apiClientIp();
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido'), 0, 255);

    $find = $db->prepare('
        SELECT id FROM password_hash_history
        WHERE user_id = ? AND new_hash = ? AND change_type = \'db_trigger\'
        ORDER BY id DESC LIMIT 1
    ');
    $find->execute([$userId, $newHash]);
    $rowId = $find->fetchColumn();

    if ($rowId !== false) {
        $upd = $db->prepare('
            UPDATE password_hash_history
            SET change_type = ?, changed_by_user_id = ?, changed_by_login = ?, ip_address = ?, user_agent = ?
            WHERE id = ?
        ');
        $upd->execute([$changeType, $operatorId, $operatorLogin, $ip, $userAgent, (int) $rowId]);

        return;
    }

    $ins = $db->prepare('
        INSERT INTO password_hash_history
            (user_id, old_hash, new_hash, changed_by_user_id, changed_by_login, change_type, ip_address, user_agent, created_at)
        VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)
    ');
    $ins->execute([$userId, $newHash, $operatorId, $operatorLogin, $changeType, $ip, $userAgent, date('Y-m-d H:i:s')]);
}

/**
 * Consulta a linha do tempo de alterações de hash de senhas.
 *
 * @return list<array<string, mixed>>
 */
function getPasswordHashHistory(PDO $db, ?int $userId = null, int $limit = 100): array
{
    $limit = max(1, min($limit, 500));
    $sql = '
        SELECT ph.id, ph.user_id, u.login, u.email, u.role,
               ph.old_hash, ph.new_hash, ph.changed_by_user_id, ph.changed_by_login,
               ph.change_type, ph.ip_address, ph.user_agent, ph.created_at
        FROM password_hash_history ph
        LEFT JOIN users u ON u.id = ph.user_id
    ';

    $params = [];
    if ($userId !== null && $userId > 0) {
        $sql .= ' WHERE ph.user_id = ? ';
        $params[] = $userId;
    }

    $sql .= ' ORDER BY ph.id DESC LIMIT ' . (int) $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        $old = (string) ($row['old_hash'] ?? '');
        $new = (string) ($row['new_hash'] ?? '');

        return [
            'id'                 => (int) $row['id'],
            'user_id'            => (int) $row['user_id'],
            'login'              => (string) ($row['login'] ?? 'desconhecido'),
            'email'              => $row['email'] ?? null,
            'role'               => $row['role'] ?? null,
            'old_hash_preview'   => $old !== '' ? substr($old, 0, 8) . '...' . substr($old, -6) : null,
            'new_hash_preview'   => $new !== '' ? substr($new, 0, 8) . '...' . substr($new, -6) : null,
            'change_type'        => (string) ($row['change_type'] ?? 'direct_sql'),
            'changed_by_user_id' => $row['changed_by_user_id'] ? (int) $row['changed_by_user_id'] : null,
            'changed_by_login'   => $row['changed_by_login'] ?? null,
            'ip_address'         => (string) ($row['ip_address'] ?? 'unknown'),
            'user_agent'         => $row['user_agent'] ?? null,
            'created_at'         => (string) ($row['created_at'] ?? ''),
        ];
    }, $rows);
}
