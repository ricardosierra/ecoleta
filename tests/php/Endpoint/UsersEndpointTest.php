<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
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
    // --- formato do login ----------------------------------------------------

    /** @return array<string, array{0:string}> */
    public static function loginsInvalidos(): array
    {
        return [
            'curto demais' => ['ab'],
            'longo demais' => [str_repeat('a', 51)],
            'com espaço no meio' => ['joao silva'],
            'parece e-mail' => ['joao@empresa.com'],
            'com barra' => ['jo/ao'],
            'com acento' => ['joão'],
            'com quebra de linha' => ["joao\r\nBcc: alguem@evil.example"],
            'com html' => ['<b>joao</b>'],
            'com aspas' => ["jo'ao"],
        ];
    }

    #[DataProvider('loginsInvalidos')]
    public function testCriacaoRecusaLoginForaDoFormato(string $login): void
    {
        $res = $this->criar(['login' => $login, 'role' => 'master']);

        self::assertNull($res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertSame(1, $this->db->count('users'), 'a conta foi criada com um login recusado');
    }

    /** @return array<string, array{0:string}> */
    public static function loginsValidos(): array
    {
        return [
            'com ponto' => ['maria.silva'],
            'com hífen e número' => ['joao-2'],
            'com sublinhado' => ['ana_paula'],
            'caixa mista' => ['AnaPaula'],
            'tamanho mínimo' => ['abc'],
            'tamanho máximo' => [str_repeat('a', 50)],
        ];
    }

    #[DataProvider('loginsValidos')]
    public function testCriacaoAceitaLoginDentroDoFormato(string $login): void
    {
        $res = $this->criar(['login' => $login, 'role' => 'master']);

        self::assertSame(200, $res->status, $res->body);
    }

    /**
     * O login aceita e-mail (`WHERE login = ? OR email = ?`). Um login igual ao
     * e-mail de OUTRA conta deixa a busca ambígua: quem digitar aquele texto cai
     * em uma das duas contas, sem regra. E-mail sem '@' existe de verdade em
     * bases antigas, já que users/edit.php nunca validou o formato.
     */
    public function testCriacaoRecusaLoginIgualAoEmailDeOutraConta(): void
    {
        $outro = $this->db->seedUser('outra', 'senha-outra-123', 'master');
        $this->db->pdo()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute(['ana', $outro]);

        foreach (['ana', 'ANA'] as $tentativa) {
            $res = $this->criar(['login' => $tentativa, 'role' => 'master']);

            self::assertSame(400, $res->status, "{$tentativa}: {$res->body}");
        }

        self::assertSame(2, $this->db->count('users'));
    }

    /** E-mail já usado por outra conta: a criação nunca checou, e o banco não tem índice único. */
    public function testCriacaoRecusaEmailJaUsado(): void
    {
        $this->db->seedUser('outra', 'senha-outra-123', 'master', 'repetido@empresa.com');

        $res = $this->criar(['login' => 'novatona', 'email' => 'Repetido@Empresa.com', 'role' => 'master']);

        self::assertSame(400, $res->status, $res->body);
        self::assertSame(2, $this->db->count('users'));
    }

    // --- edição: o mesmo formato, só para login que mudou --------------------

    private function editar(array $body): EndpointResponse
    {
        return Endpoint::call('users/edit.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->comoRoot(),
            'body' => $body,
        ]);
    }

    public function testEdicaoRecusaMudarOLoginParaForaDoFormato(): void
    {
        $id = $this->db->seedUser('joao', 'senha-user-123', 'user', 'joao@empresa.com', $this->grupoId);

        foreach (['joao silva', 'joao@empresa.com', 'jo'] as $novo) {
            $res = $this->editar(['user_id' => $id, 'login' => $novo, 'email' => 'joao@empresa.com', 'group_id' => $this->grupoId]);

            self::assertSame(400, $res->status, "{$novo}: {$res->body}");
        }

        $stmt = $this->db->pdo()->prepare('SELECT login FROM users WHERE id = ?');
        $stmt->execute([$id]);
        self::assertSame('joao', $stmt->fetchColumn());
    }

    /**
     * Conta antiga com login fora do formato continua editável enquanto o login
     * não mudar: o formato vale para o que entra, não para o que já existe;
     * senão editar o e-mail de quem tem login com espaço passaria a falhar.
     */
    public function testEdicaoDeContaAntigaComLoginForaDoFormatoSegueFuncionando(): void
    {
        $id = $this->db->seedUser('Maria Silva', 'senha-user-123', 'user', 'maria@empresa.com', $this->grupoId);

        $res = $this->editar(['user_id' => $id, 'login' => 'Maria Silva', 'email' => 'maria.nova@empresa.com', 'group_id' => $this->grupoId]);

        self::assertSame(200, $res->status, $res->body);
    }

    public function testEdicaoRecusaLoginIgualAoEmailDeOutraConta(): void
    {
        $alvo = $this->db->seedUser('joao', 'senha-user-123', 'user', 'joao@empresa.com', $this->grupoId);
        $outro = $this->db->seedUser('outra', 'senha-outra-123', 'master');
        $this->db->pdo()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute(['ana', $outro]);

        $res = $this->editar(['user_id' => $alvo, 'login' => 'ana', 'email' => 'joao@empresa.com', 'group_id' => $this->grupoId]);

        self::assertSame(400, $res->status, $res->body);
    }

    public function testEdicaoRecusaEmailIgualAoLoginDeOutraConta(): void
    {
        $alvo = $this->db->seedUser('joao', 'senha-user-123', 'user', 'joao@empresa.com', $this->grupoId);

        $res = $this->editar(['user_id' => $alvo, 'login' => 'joao', 'email' => 'admin', 'group_id' => $this->grupoId]);

        self::assertSame(400, $res->status, $res->body);
    }

    /** A checagem não pode tropeçar na própria conta: salvar sem mudar identidade passa. */
    public function testEdicaoSemMudarIdentidadeNaoColideComASiMesma(): void
    {
        $alvo = $this->db->seedUser('joao', 'senha-user-123', 'user', 'joao@empresa.com', $this->grupoId);

        $res = $this->editar(['user_id' => $alvo, 'login' => 'joao', 'email' => 'joao@empresa.com', 'group_id' => $this->grupoId]);

        self::assertSame(200, $res->status, $res->body);
    }
    // --- senha temporária e trava de troca: nunca as duas juntas --------------

    /** @return array<string,mixed> */
    private function linhaDe(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);

        return (array) $stmt->fetch();
    }

    /**
     * Senha temporária + trava de troca na mesma conta é beco sem saída:
     * force_password_change manda a pessoa trocar a senha, e password_locked faz
     * change_password.php responder 403. Ela ficava presa na temporária, que
     * nunca expirava. A edição só barrava quando a conta JÁ estava travada;
     * travar e gerar na mesma chamada passava.
     */
    public function testNaoGeraSenhaTemporariaETravaNaMesmaChamada(): void
    {
        $id = $this->db->seedUser('joao', 'senha-user-123', 'user', 'joao@empresa.com', $this->grupoId);
        $antes = $this->linhaDe($id);

        $res = $this->editar([
            'user_id' => $id,
            'login' => 'joao',
            'email' => 'joao@empresa.com',
            'group_id' => $this->grupoId,
            'generate_password' => true,
            'password_locked' => true,
        ]);

        self::assertSame(400, $res->status, $res->body);
        self::assertNull($res->json()['generated_password'] ?? null);

        $depois = $this->linhaDe($id);
        self::assertSame($antes['password_hash'], $depois['password_hash'], 'a senha foi trocada pela chamada recusada');
        self::assertSame(0, (int) $depois['password_locked'], 'a conta foi travada pela chamada recusada');
        self::assertSame(0, (int) $depois['force_password_change']);
    }

    public function testNaoDefineSenhaETravaNaMesmaChamada(): void
    {
        $id = $this->db->seedUser('joao', 'senha-user-123', 'user', 'joao@empresa.com', $this->grupoId);
        $antes = $this->linhaDe($id);

        $res = $this->editar([
            'user_id' => $id,
            'login' => 'joao',
            'email' => 'joao@empresa.com',
            'group_id' => $this->grupoId,
            'new_password' => 'senha-definida-123',
            'password_locked' => true,
        ]);

        self::assertSame(400, $res->status, $res->body);
        self::assertSame($antes['password_hash'], $this->linhaDe($id)['password_hash']);
        self::assertSame(0, (int) $this->linhaDe($id)['password_locked']);
    }

    /** Travar quem ainda não trocou a senha temporária prende a pessoa do mesmo jeito. */
    public function testNaoTravaContaQueAindaTemSenhaTemporaria(): void
    {
        $id = $this->db->seedUser('joao', 'senha-user-123', 'user', 'joao@empresa.com', $this->grupoId);
        $this->db->pdo()->prepare('UPDATE users SET force_password_change = 1 WHERE id = ?')->execute([$id]);

        $res = $this->editar([
            'user_id' => $id,
            'login' => 'joao',
            'email' => 'joao@empresa.com',
            'group_id' => $this->grupoId,
            'password_locked' => true,
        ]);

        self::assertSame(400, $res->status, $res->body);
        self::assertSame(0, (int) $this->linhaDe($id)['password_locked']);
    }

    /** A guarda não pode estar apertada demais: travar uma conta saudável continua valendo. */
    public function testTravaDeContaSemSenhaTemporariaContinuaFuncionando(): void
    {
        $id = $this->db->seedUser('joao', 'senha-user-123', 'user', 'joao@empresa.com', $this->grupoId);

        $res = $this->editar([
            'user_id' => $id,
            'login' => 'joao',
            'email' => 'joao@empresa.com',
            'group_id' => $this->grupoId,
            'password_locked' => true,
        ]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame(1, (int) $this->linhaDe($id)['password_locked']);
    }

    /** Destravar e gerar a senha na mesma chamada é o caminho legítimo e segue aberto. */
    public function testDestravarEGerarSenhaNaMesmaChamadaContinuaFuncionando(): void
    {
        $id = $this->db->seedUser('joao', 'senha-user-123', 'user', 'joao@empresa.com', $this->grupoId, 1);

        $res = $this->editar([
            'user_id' => $id,
            'login' => 'joao',
            'email' => 'joao@empresa.com',
            'group_id' => $this->grupoId,
            'generate_password' => true,
            'password_locked' => false,
        ]);

        self::assertSame(200, $res->status, $res->body);
        self::assertNotEmpty($res->json()['generated_password']);

        $depois = $this->linhaDe($id);
        self::assertSame(0, (int) $depois['password_locked']);
        self::assertSame(1, (int) $depois['force_password_change']);
    }

    /** Conta já travada e ainda travada: gerar senha segue recusado (403, com alerta). */
    public function testContaJaTravadaContinuaRecusandoGerarSenha(): void
    {
        $id = $this->db->seedUser('joao', 'senha-user-123', 'user', 'joao@empresa.com', $this->grupoId, 1);

        $res = $this->editar([
            'user_id' => $id,
            'login' => 'joao',
            'email' => 'joao@empresa.com',
            'group_id' => $this->grupoId,
            'generate_password' => true,
        ]);

        self::assertSame(403, $res->status, $res->body);
    }

    /**
     * Quem já está na combinação (travado e com senha temporária, de antes desta
     * regra) continua editável: a guarda barra só ATIVAR a trava, não toda
     * edição de conta que já tem as duas coisas.
     */
    public function testEditarOutroCampoDeContaJaPresaNaoEBarrado(): void
    {
        $id = $this->db->seedUser('joao', 'senha-user-123', 'user', 'joao@empresa.com', $this->grupoId, 1);
        $this->db->pdo()->prepare('UPDATE users SET force_password_change = 1 WHERE id = ?')->execute([$id]);

        $res = $this->editar([
            'user_id' => $id,
            'login' => 'joao',
            'email' => 'joao.novo@empresa.com',
            'group_id' => $this->grupoId,
            'password_locked' => true,
        ]);

        self::assertSame(200, $res->status, $res->body);
    }

    public function testSenhaDefinidaPeloAdministradorTambemTemOitoCaracteresNoMinimo(): void
    {
        $id = $this->db->seedUser('joao', 'senha-user-123', 'user', 'joao@empresa.com', $this->grupoId);
        $base = ['user_id' => $id, 'login' => 'joao', 'email' => 'joao@empresa.com', 'group_id' => $this->grupoId];

        $curta = $this->editar($base + ['new_password' => 'abcdefg']);
        self::assertSame(400, $curta->status, $curta->body);
        self::assertStringContainsString('8 caracteres', (string) $curta->error());

        $ok = $this->editar($base + ['new_password' => 'abcdefgh']);
        self::assertSame(200, $ok->status, $ok->body);
    }
}
