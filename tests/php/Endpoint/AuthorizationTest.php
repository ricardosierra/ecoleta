<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Controle de acesso dos endpoints do dashboard, exercitado pelo caminho de
 * verdade: um processo PHP rodando o arquivo de public/api/.
 *
 * A sessão do atacante aqui não é uma sessão qualquer — é uma sessão viva, com
 * token CSRF válido, exatamente como a de quem já fez login como 'user' e abriu
 * o console do navegador. É esse o cenário que interessa: quem já está dentro
 * tentando o que não é dele.
 *
 * As telas do dashboard também escondem esses botões (tests/lib/authz.test.ts),
 * mas isso é escolha de desenho. A recusa que vale é esta.
 */
final class AuthorizationTest extends TestCase
{
    private TestDatabase $db;

    private int $rootId;

    private int $masterId;

    private int $userId;

    private int $grupoId;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        $this->grupoId = $this->db->seedGroup('Coleta', 'https://app.powerbi.com/view?r=abc');
        $this->rootId = $this->db->seedUser('admin', 'senha-root-123', 'root');
        $this->masterId = $this->db->seedUser('chefe', 'senha-master-123', 'master');
        $this->userId = $this->db->seedUser('joao', 'senha-user-123', 'user', null, $this->grupoId);
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    /** @param array<string,mixed> $session */
    private function call(string $script, array $session, array $options = []): EndpointResponse
    {
        return Endpoint::call($script, array_merge([
            'dsn' => $this->db->dsn(),
            'session' => $session,
        ], $options));
    }

    private function comoUsuarioComum(): array
    {
        return ['user_id' => $this->userId, 'role' => 'user', 'login' => 'joao'];
    }

    private function comoMaster(): array
    {
        return ['user_id' => $this->masterId, 'role' => 'master', 'login' => 'chefe'];
    }

    private function comoRoot(): array
    {
        return ['user_id' => $this->rootId, 'role' => 'root', 'login' => 'admin'];
    }

    // --- a regra central: 'user' não administra nada -------------------------

    /**
     * Os sete endpoints que exigem papel de administrador. A lista é escrita à
     * mão de propósito: um endpoint novo em users/ ou groups/ não entra sozinho
     * na cobertura, e testEnumeraTodosOsEndpointsDeAdministracao() falha até
     * alguém decidir explicitamente o que fazer com ele.
     *
     * @return array<string, array{0:string, 1:array}>
     */
    public static function endpointsDeAdministracao(): array
    {
        return [
            'listar usuários' => ['users/index.php', []],
            'editar usuário' => ['users/edit.php', ['user_id' => 1, 'login' => 'x', 'email' => 'x@y.z']],
            'excluir usuário' => ['users/delete.php', ['user_id' => 1]],
            'gerar senha' => ['users/generate_password.php', ['user_id' => 1]],
            'listar grupos' => ['groups/index.php', []],
            'editar grupo' => ['groups/edit.php', ['group_id' => 1, 'name' => 'x']],
            'excluir grupo' => ['groups/delete.php', ['group_id' => 1]],
        ];
    }

    #[DataProvider('endpointsDeAdministracao')]
    public function testUsuarioComumNaoChamaEndpointDeAdministracao(string $script, array $body): void
    {
        $resposta = $this->call($script, $this->comoUsuarioComum(), ['body' => $body]);

        self::assertSame(403, $resposta->status, "{$script} deixou passar um usuário comum");
        self::assertSame('Acesso negado.', $resposta->error());
    }

    #[DataProvider('endpointsDeAdministracao')]
    public function testSessaoAnonimaNaoChamaEndpointDeAdministracao(string $script, array $body): void
    {
        $resposta = $this->call($script, [], ['body' => $body]);

        self::assertSame(403, $resposta->status, "{$script} deixou passar uma sessão sem login");
        self::assertSame('Acesso negado.', $resposta->error());
    }

    /**
     * Sessão com papel que não existe no sistema é tratada como não
     * autenticada. Um papel escrito com caixa diferente ('Root') cai aqui: a
     * regra compara com `===` e não normaliza, então tem que recusar.
     */
    #[DataProvider('papeisForjados')]
    public function testPapelForjadoNaSessaoNaoAutoriza(string $papel): void
    {
        $resposta = $this->call('users/edit.php', ['user_id' => $this->userId, 'role' => $papel, 'login' => 'joao'], [
            'body' => ['user_id' => $this->userId, 'login' => 'joao', 'email' => 'joao@x.com'],
        ]);

        self::assertSame(403, $resposta->status, "o papel '{$papel}' foi aceito");
    }

    public static function papeisForjados(): array
    {
        return [['Root'], ['ROOT'], [' root'], ['administrator'], ['superuser'], ['']];
    }

    /**
     * A recusa é a mesma para "não logado" e para "logado sem permissão": a
     * resposta não conta ao cliente qual dos dois foi.
     */
    public function testRecusaEhIdenticaParaAnonimoEParaUsuarioComum(): void
    {
        $anonimo = $this->call('groups/delete.php', [], ['body' => ['group_id' => $this->grupoId]]);
        $comum = $this->call('groups/delete.php', $this->comoUsuarioComum(), ['body' => ['group_id' => $this->grupoId]]);

        self::assertSame($anonimo->status, $comum->status);
        self::assertSame($anonimo->body, $comum->body);
    }

    /**
     * O DSN aponta para um caminho impossível: se o endpoint chegasse a abrir o
     * banco, a resposta seria 500. O 403 prova que o papel foi conferido antes
     * de qualquer consulta.
     */
    #[DataProvider('endpointsDeAdministracao')]
    public function testRecusaAconteceAntesDeTocarNoBanco(string $script, array $body): void
    {
        $resposta = $this->call($script, $this->comoUsuarioComum(), [
            'body' => $body,
            'dsn' => 'sqlite:/dev/null/impossivel.sqlite',
        ]);

        self::assertSame(403, $resposta->status, "{$script} abriu o banco antes de conferir o papel");
    }

    /** Nenhuma tentativa recusada pode ter mudado alguma coisa. */
    public function testTentativaRecusadaNaoAlteraNada(): void
    {
        $antes = $this->db->rows('users');

        foreach (self::endpointsDeAdministracao() as [$script, $body]) {
            $this->call($script, $this->comoUsuarioComum(), ['body' => $body]);
        }

        self::assertSame($antes, $this->db->rows('users'), 'uma tentativa recusada mexeu na tabela de usuários');
        self::assertSame(1, $this->db->count('groups'), 'o teste semeia um grupo só; outro a mais seria criação indevida');
        self::assertSame(0, $this->db->count('activity_logs'), 'recusa não deve gerar registro de auditoria de ação');
    }

    /**
     * Guarda contra endpoint novo entrar sem cobertura: se alguém acrescentar um
     * arquivo em users/ ou groups/, este teste falha até a lista acima ser
     * revisada.
     */
    public function testEnumeraTodosOsEndpointsDeAdministracao(): void
    {
        $emDisco = [];
        foreach (['users', 'groups'] as $pasta) {
            foreach (glob(ECOLETA_API_DIR . '/' . $pasta . '/*.php') ?: [] as $arquivo) {
                $emDisco[] = $pasta . '/' . basename($arquivo);
            }
        }
        sort($emDisco);

        $cobertos = array_column(self::endpointsDeAdministracao(), 0);
        // users/logs.php não exige papel de administrador: quem é 'user' vê o
        // próprio histórico. É coberto separadamente, mais abaixo.
        $cobertos[] = 'users/logs.php';
        sort($cobertos);

        self::assertSame(
            $emDisco,
            $cobertos,
            'endpoint novo em users/ ou groups/: acrescente-o a endpointsDeAdministracao() ou justifique a exceção'
        );
    }

    // --- histórico: a exceção da regra --------------------------------------

    public function testUsuarioComumVeOProprioHistorico(): void
    {
        $resposta = $this->call('users/logs.php', $this->comoUsuarioComum(), [
            'method' => 'GET',
            'query' => ['user_id' => $this->userId],
        ]);

        self::assertSame(200, $resposta->status);
        self::assertSame('joao', $resposta->json()['user']['login']);
    }

    public function testUsuarioComumNaoVeOHistoricoDeOutraConta(): void
    {
        $resposta = $this->call('users/logs.php', $this->comoUsuarioComum(), [
            'method' => 'GET',
            'query' => ['user_id' => $this->rootId],
        ]);

        self::assertSame(403, $resposta->status);
        self::assertSame('Acesso negado.', $resposta->error());
    }

    public function testAdministradorVeOHistoricoDeQualquerConta(): void
    {
        foreach ([$this->comoRoot(), $this->comoMaster()] as $sessao) {
            $resposta = $this->call('users/logs.php', $sessao, [
                'method' => 'GET',
                'query' => ['user_id' => $this->userId],
            ]);

            self::assertSame(200, $resposta->status);
        }
    }

    // --- master não alcança root --------------------------------------------

    public function testMasterNaoEditaUmRoot(): void
    {
        $resposta = $this->call('users/edit.php', $this->comoMaster(), [
            'body' => ['user_id' => $this->rootId, 'login' => 'admin', 'email' => 'admin@x.com', 'role' => 'user'],
        ]);

        self::assertSame(403, $resposta->status);
        self::assertSame('root', $this->userById($this->rootId)['role']);
        self::assertSame('admin', $this->userById($this->rootId)['login']);
    }

    public function testMasterNaoExcluiUmRoot(): void
    {
        $resposta = $this->call('users/delete.php', $this->comoMaster(), ['body' => ['user_id' => $this->rootId]]);

        self::assertSame(403, $resposta->status);
        self::assertNotNull($this->userById($this->rootId));
    }

    public function testMasterNaoGeraSenhaParaUmRoot(): void
    {
        $antes = $this->userById($this->rootId)['password_hash'];

        $resposta = $this->call('users/generate_password.php', $this->comoMaster(), [
            'body' => ['user_id' => $this->rootId],
        ]);

        self::assertSame(403, $resposta->status);
        self::assertSame($antes, $this->userById($this->rootId)['password_hash']);
    }

    /**
     * Escalada de privilégio pelo corpo da requisição: master editando um
     * usuário comum e pedindo 'root' no JSON. A conta tem que continuar 'user'.
     */
    public function testMasterNaoPromoveUsuarioPeloCorpoDaRequisicao(): void
    {
        $resposta = $this->call('users/edit.php', $this->comoMaster(), [
            'body' => [
                'user_id' => $this->userId,
                'login' => 'joao',
                'email' => 'joao@ricasolucoes.com.br',
                'role' => 'root',
                'group_id' => $this->grupoId,
            ],
        ]);

        self::assertSame(200, $resposta->status);
        self::assertSame('user', $resposta->json()['user']['role']);
        self::assertSame('user', $this->userById($this->userId)['role']);
    }

    public function testNinguemExcluiAPropriaConta(): void
    {
        $resposta = $this->call('users/delete.php', $this->comoRoot(), ['body' => ['user_id' => $this->rootId]]);

        self::assertSame(400, $resposta->status);
        self::assertNotNull($this->userById($this->rootId));
    }

    // --- o outro lado: administrador consegue -------------------------------

    /** A guarda não pode estar apertada demais: root edita e a mudança grava. */
    public function testRootEditaUsuarioComum(): void
    {
        $resposta = $this->call('users/edit.php', $this->comoRoot(), [
            'body' => [
                'user_id' => $this->userId,
                'login' => 'joao-novo',
                'email' => 'joao.novo@ricasolucoes.com.br',
                'role' => 'master',
            ],
        ]);

        self::assertSame(200, $resposta->status);

        $atualizado = $this->userById($this->userId);
        self::assertSame('joao-novo', $atualizado['login']);
        self::assertSame('master', $atualizado['role']);
    }

    public function testRootExcluiGrupoVazioEAudita(): void
    {
        $vazio = $this->db->seedGroup('Infectantes');

        $resposta = $this->call('groups/delete.php', $this->comoRoot(), ['body' => ['group_id' => $vazio]]);

        self::assertSame(200, $resposta->status);
        self::assertSame(1, $this->db->count('groups'));

        $auditoria = $this->db->rows('activity_logs');
        self::assertCount(1, $auditoria);
        self::assertSame('delete_group', $auditoria[0]['action']);
        self::assertSame('admin', $auditoria[0]['performed_by_login']);
        self::assertStringContainsString('Infectantes', (string) $auditoria[0]['description']);
    }

    /** Grupo com usuário vinculado não some por baixo de quem depende dele. */
    public function testGrupoComUsuarioVinculadoNaoEhExcluido(): void
    {
        $resposta = $this->call('groups/delete.php', $this->comoRoot(), ['body' => ['group_id' => $this->grupoId]]);

        self::assertSame(400, $resposta->status);
        self::assertSame(1, $this->db->count('groups'));
    }

    /** @return array<string,mixed>|null */
    private function userById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
