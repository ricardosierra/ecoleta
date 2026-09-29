<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Validação do trigger de banco de dados para detecção e auditoria de alterações
 * de hash de senha, e exposição desses dados no endpoint users/logs.php.
 */
final class PasswordHistoryTriggerTest extends TestCase
{
    private TestDatabase $db;

    private int $adminId;

    private int $userId;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        $this->adminId = $this->db->seedUser('admin', 'senha-admin-123', 'root');
        $this->userId = $this->db->seedUser('joao', 'senha-user-123', 'user', 'joao@exemplo.com');
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    public function testTriggerRegistraMudancaDeHashNoBancoAutomaticamente(): void
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$this->userId]);
        $oldHash = (string) $stmt->fetch()['password_hash'];

        $newHash = password_hash('nova-senha-secreta-456', PASSWORD_DEFAULT);

        // Atualização direta a nível de banco
        $update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $update->execute([$newHash, $this->userId]);

        $historyStmt = $pdo->prepare('SELECT * FROM password_hash_history WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $historyStmt->execute([$this->userId]);
        $row = $historyStmt->fetch();

        self::assertIsArray($row, 'O trigger precisa registrar automaticamente a linha em password_hash_history');
        self::assertSame($this->userId, (int) $row['user_id']);
        self::assertSame($oldHash, $row['old_hash']);
        self::assertSame($newHash, $row['new_hash']);
        self::assertSame('db_trigger', $row['change_type']);
    }

    public function testTriggerNaoRegistraSeHashNaoMudar(): void
    {
        $pdo = $this->db->pdo();

        $countBefore = (int) $pdo->query('SELECT COUNT(*) FROM password_hash_history')->fetchColumn();

        // Atualização que não altera o hash (apenas email)
        $update = $pdo->prepare('UPDATE users SET email = ? WHERE id = ?');
        $update->execute(['novo_email@exemplo.com', $this->userId]);

        $countAfter = (int) $pdo->query('SELECT COUNT(*) FROM password_hash_history')->fetchColumn();

        self::assertSame($countBefore, $countAfter, 'Não deve criar registro quando o hash não for alterado');
    }

    public function testTriggerRegistraCriacaoEExclusaoEHistoricoSobreviveAoUsuario(): void
    {
        $pdo = $this->db->pdo();

        $hash = password_hash('senha-nova-999', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (login, password_hash, role, force_password_change, password_locked) VALUES ('maria', ?, 'user', 1, 0)")->execute([$hash]);
        $mariaId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT change_type, old_hash, new_hash FROM password_hash_history WHERE user_id = ? ORDER BY id');
        $stmt->execute([$mariaId]);
        $rows = $stmt->fetchAll();
        self::assertCount(1, $rows, 'a criação do usuário registra o primeiro hash');
        self::assertNull($rows[0]['old_hash']);
        self::assertSame($hash, $rows[0]['new_hash']);

        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$mariaId]);

        $stmt->execute([$mariaId]);
        $rows = $stmt->fetchAll();
        self::assertCount(2, $rows, 'apagar o usuário não apaga a trilha dele');
        self::assertSame('user_deleted', $rows[1]['change_type']);
        self::assertSame($hash, $rows[1]['old_hash']);
    }

    public function testRootConfereSenhaAntigaContraOHistorico(): void
    {
        $pdo = $this->db->pdo();
        $novoHash = password_hash('senha-nova-456', PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$novoHash, $this->userId]);

        $chamada = fn (string $senha): EndpointResponse => Endpoint::call('users/password_history_check.php', [
            'dsn' => $this->db->dsn(),
            'method' => 'POST',
            'session' => ['user_id' => $this->adminId, 'role' => 'root', 'login' => 'admin'],
            'body' => ['user_id' => $this->userId, 'password' => $senha],
        ]);

        $antiga = $chamada('senha-user-123')->json();
        self::assertTrue($antiga['ok']);
        self::assertFalse($antiga['matches_current']);
        self::assertCount(1, $antiga['matches'], 'a senha antiga bate com o hash que a trigger guardou, uma vez só');
        self::assertSame('db_trigger', $antiga['matches'][0]['change_type']);

        $atual = $chamada('senha-nova-456')->json();
        self::assertTrue($atual['matches_current']);

        $nenhuma = $chamada('nunca-foi-senha')->json();
        self::assertSame([], $nenhuma['matches']);

        $negado = Endpoint::call('users/password_history_check.php', [
            'dsn' => $this->db->dsn(),
            'method' => 'POST',
            'session' => ['user_id' => $this->userId, 'role' => 'user', 'login' => 'joao'],
            'body' => ['user_id' => $this->userId, 'password' => 'senha-user-123'],
        ]);
        self::assertSame(403, $negado->status);
    }

    public function testEndpointUsersLogsRetornaHistoricoDeAuditoria(): void
    {
        $pdo = $this->db->pdo();
        $newHash = password_hash('outra-senha-789', PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $this->userId]);

        $resposta = Endpoint::call('users/logs.php', [
            'dsn' => $this->db->dsn(),
            'session' => [
                'user_id' => $this->adminId,
                'role' => 'root',
                'login' => 'admin',
            ],
            'query' => ['user_id' => (string) $this->userId],
        ]);

        self::assertSame(200, $resposta->status);
        $body = $resposta->json();

        self::assertTrue($body['ok']);
        self::assertArrayHasKey('password_history', $body);
        self::assertNotEmpty($body['password_history']);
        self::assertSame('db_trigger', $body['password_history'][0]['change_type']);
        self::assertSame($newHash, $body['password_history'][0]['new_hash']);
    }
}

