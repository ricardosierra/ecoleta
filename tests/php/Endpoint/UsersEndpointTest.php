<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * public/api/users/index.php — criação e listagem de usuários pela gestão.
 *
 * Cobre o contrato que mudou em 2026-09: e-mail deixou de ser obrigatório na
 * criação (é pedido no primeiro acesso) e a listagem passou a trazer o último
 * login de cada conta.
 */
final class UsersEndpointTest extends TestCase
{
    private TestDatabase $db;

    private int $rootId;

    private int $grupoId;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        $this->grupoId = $this->db->seedGroup('Coleta');
        $this->rootId = $this->db->seedUser('admin', 'senha-root-123', 'root');
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    private function comoRoot(): array
    {
        return ['user_id' => $this->rootId, 'role' => 'root', 'login' => 'admin'];
    }

    private function criar(array $body): EndpointResponse
    {
        return Endpoint::call('users/index.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->comoRoot(),
            'body' => $body,
        ]);
    }

    public function testCriaUsuarioSemEmail(): void
    {
        $res = $this->criar(['login' => 'sememail', 'role' => 'master']);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertTrue((bool) ($res->json()['ok'] ?? false));

        $rows = $this->db->rows('users', 'id DESC');
        self::assertSame('sememail', $rows[0]['login']);
        self::assertNull($rows[0]['email'], 'e-mail vazio deve gravar NULL, não string vazia');
        self::assertSame(1, (int) $rows[0]['force_password_change']);
    }

    public function testCriaUsuarioComEmailInvalidoEhRecusado(): void
    {
        $res = $this->criar(['login' => 'torto', 'email' => 'nao-e-email', 'role' => 'master']);

        self::assertNull($res->fatal);
        self::assertSame(400, $res->status, $res->body);
    }

    public function testLoginSegueObrigatorio(): void
    {
        $res = $this->criar(['email' => 'so@email.com', 'role' => 'master']);

        self::assertNull($res->fatal);
        self::assertSame(400, $res->status, $res->body);
    }

    public function testListagemTrazUltimoLogin(): void
    {
        $userId = $this->db->seedUser('joao', 'senha-user-123', 'user', null, $this->grupoId);
        $this->db->pdo()->prepare(
            "INSERT INTO access_logs (user_id, ip_address, logged_at) VALUES (?, '203.0.113.1', '2026-08-30 10:00:00')"
        )->execute([$userId]);

        $res = Endpoint::call('users/index.php', [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'session' => $this->comoRoot(),
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);

        $users = $res->json()['users'];
        $byId = [];
        foreach ($users as $u) {
            $byId[(int) $u['id']] = $u;
        }

        self::assertArrayHasKey('last_login', $byId[$userId]);
        self::assertSame('2026-08-30 10:00:00', $byId[$userId]['last_login']);
        self::assertNull($byId[$this->rootId]['last_login'], 'quem nunca logou vem com last_login nulo');
    }
}
