<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/authz.php';

/**
 * A sessão não é a fonte de verdade sobre quem a pessoa é: o banco é.
 *
 * O papel era gravado na sessão no login e nunca mais conferido, e o timeout
 * de inatividade renova a sessão a cada requisição — então revogar acesso não
 * revogava. Um master rebaixado a `user` seguia com 200 em clientes; um master
 * excluído seguia lendo a carteira e criando usuário; reset de senha não
 * derrubava a sessão que já estava aberta.
 *
 * Estes testes semeiam a sessão com o papel que valia no login e mudam o banco
 * por baixo, que é exatamente o cenário de quem perde o acesso com a aba aberta.
 *
 * Também cobre `force_password_change` no servidor: a tela já barrava, mas um
 * master com a senha temporária chamava a API direto e a senha nunca expirava.
 */
final class SessionRevalidationTest extends TestCase
{
    private TestDatabase $db;

    private int $rootId;

    private int $masterId;

    private int $userId;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        $grupo = $this->db->seedGroup('Coleta');
        $this->rootId = $this->db->seedUser('admin', 'senha-root-123', 'root');
        $this->masterId = $this->db->seedUser('chefe', 'senha-master-123', 'master');
        $this->userId = $this->db->seedUser('joao', 'senha-user-123', 'user', null, $grupo);
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    /** @return array<string,mixed> */
    private function sessaoMaster(): array
    {
        return ['user_id' => $this->masterId, 'role' => 'master', 'login' => 'chefe'];
    }

    /** @return array<string,mixed> */
    private function sessaoRoot(): array
    {
        return ['user_id' => $this->rootId, 'role' => 'root', 'login' => 'admin'];
    }

    /** @param array<string,mixed> $session */
    private function get(string $script, array $session, array $query = []): EndpointResponse
    {
        return Endpoint::call($script, [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'session' => $session,
            'query' => $query,
            'env' => ['MAIL_TRANSPORT' => 'log', 'WHATSAPP_TRANSPORT' => 'off'],
        ]);
    }

    /** @param array<string,mixed> $session */
    private function post(string $script, array $session, array $body): EndpointResponse
    {
        return Endpoint::call($script, [
            'dsn' => $this->db->dsn(),
            'session' => $session,
            'body' => $body,
            'env' => ['MAIL_TRANSPORT' => 'log', 'WHATSAPP_TRANSPORT' => 'off'],
        ]);
    }

    private function sql(string $sql, array $params = []): void
    {
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    private function hashDe(int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$id]);

        return (string) $stmt->fetchColumn();
    }

    // --- papel: o banco manda ------------------------------------------------

    /** O teste que não mente: antes da mudança no banco, a mesma sessão passa. */
    public function testMasterEntraEnquantoOBancoConcordaComASessao(): void
    {
        self::assertSame(200, $this->get('clients/index.php', $this->sessaoMaster())->status);
    }

    public function testMasterRebaixadoPerdeOAcessoNaProximaRequisicao(): void
    {
        $this->sql("UPDATE users SET role = 'user' WHERE id = ?", [$this->masterId]);

        $res = $this->get('clients/index.php', $this->sessaoMaster());

        self::assertSame(403, $res->status, $res->body);
        self::assertSame('user', $res->session['role'] ?? null, 'a sessão tem que acompanhar o papel do banco');
    }

    public function testRootRebaixadoNaoAdministraMais(): void
    {
        $this->sql("UPDATE users SET role = 'user' WHERE id = ?", [$this->rootId]);

        self::assertSame(403, $this->get('users/index.php', $this->sessaoRoot())->status);
        self::assertSame(403, $this->post('groups/index.php', $this->sessaoRoot(), ['name' => 'Novo'])->status);
    }

    /** Rebaixar de root a master tira o que só root faz, na hora. */
    public function testRootRebaixadoAMasterPerdeOQueSoRootFaz(): void
    {
        $this->sql("UPDATE users SET role = 'master' WHERE id = ?", [$this->rootId]);

        $res = $this->post('users/password_history_check.php', $this->sessaoRoot(), ['user_id' => $this->userId, 'password' => 'x']);

        self::assertSame(403, $res->status, $res->body);
        self::assertSame('master', $res->session['role'] ?? null);
    }

    public function testMasterExcluidoPerdeOAcessoEASessaoEhDestruida(): void
    {
        $this->sql('DELETE FROM users WHERE id = ?', [$this->masterId]);

        $res = $this->get('clients/index.php', $this->sessaoMaster());

        self::assertSame(403, $res->status, $res->body);
        self::assertSame([], $res->session, 'a sessão de uma conta que não existe mais tem que ser esvaziada');
    }

    public function testMasterExcluidoNaoCriaUsuario(): void
    {
        $this->sql('DELETE FROM users WHERE id = ?', [$this->masterId]);
        $antes = $this->db->count('users');

        $res = $this->post('users/index.php', $this->sessaoMaster(), ['login' => 'invasor', 'role' => 'user', 'group_id' => 1]);

        self::assertSame(403, $res->status, $res->body);
        self::assertSame($antes, $this->db->count('users'), 'um master excluído criou uma conta');
    }

    /** Rotas que só exigem login (sem papel de administrador) também conferem o banco. */
    public function testContaExcluidaRecebe401NasRotasQueSoExigemLogin(): void
    {
        $this->sql('DELETE FROM users WHERE id = ?', [$this->userId]);
        $sessao = ['user_id' => $this->userId, 'role' => 'user', 'login' => 'joao'];

        $logs = $this->get('users/logs.php', $sessao, ['user_id' => $this->userId]);
        self::assertSame(401, $logs->status, $logs->body);
        self::assertSame([], $logs->session);

        $troca = $this->post('auth/change_password.php', $sessao, ['new_password' => 'senha-nova-456']);
        self::assertSame(401, $troca->status, $troca->body);
    }

    public function testContaComumRebaixadaNaoMudaOQueJaNaoPodia(): void
    {
        // Papel gravado na sessão que já não é de administrador: recusa sem nem
        // consultar o banco. Só pode estar defasado "para baixo", então é seguro.
        $res = Endpoint::call('clients/index.php', [
            'method' => 'GET',
            'dsn' => 'sqlite:/dev/null/impossivel.sqlite',
            'session' => ['user_id' => $this->userId, 'role' => 'user', 'login' => 'joao'],
        ]);

        self::assertSame(403, $res->status, $res->body);
    }

    /**
     * Promoção: a sessão ainda diz `user`, e a recusa é a escolha segura. O
     * dashboard chama me.php ao abrir, que traz a sessão para o papel do banco.
     */
    public function testPromocaoChegaPelaConsultaDeSessao(): void
    {
        $this->sql("UPDATE users SET role = 'master' WHERE id = ?", [$this->userId]);
        $sessao = ['user_id' => $this->userId, 'role' => 'user', 'login' => 'joao'];

        self::assertSame(403, $this->get('clients/index.php', $sessao)->status, 'sessão defasada para baixo fica recusada');

        $me = $this->get('auth/me.php', $sessao);
        self::assertSame(200, $me->status, $me->body);
        self::assertSame('master', $me->json()['user']['role']);
        self::assertSame('master', $me->session['role'] ?? null);

        self::assertSame(200, $this->get('clients/index.php', $me->session)->status);
    }

    public function testLoginRenomeadoNoBancoChegaNaTrilhaDeAuditoria(): void
    {
        $this->sql("UPDATE users SET login = 'admin2' WHERE id = ?", [$this->rootId]);

        $res = $this->post('users/edit.php', $this->sessaoRoot(), [
            'user_id' => $this->userId,
            'login' => 'joao',
            'email' => 'joao@exemplo.com.br',
            'group_id' => 1,
        ]);

        self::assertSame(200, $res->status, $res->body);
        $auditoria = $this->db->rows('activity_logs');
        self::assertSame('admin2', $auditoria[0]['performed_by_login'], 'a trilha usou o login velho da sessão');
    }

    // --- me.php --------------------------------------------------------------

    public function testMeDevolveOPapelDoBancoEAtualizaASessao(): void
    {
        $this->sql("UPDATE users SET role = 'user', group_id = 1 WHERE id = ?", [$this->masterId]);

        $res = $this->get('auth/me.php', $this->sessaoMaster());

        self::assertSame(200, $res->status, $res->body);
        self::assertSame('user', $res->json()['user']['role']);
        self::assertSame('user', $res->session['role'] ?? null);
    }

    public function testMeDeContaExcluidaRespondeNaoAutenticadoEEsvaziaASessao(): void
    {
        $this->sql('DELETE FROM users WHERE id = ?', [$this->masterId]);

        $res = $this->get('auth/me.php', $this->sessaoMaster());

        self::assertSame(401, $res->status, $res->body);
        self::assertSame([], $res->session);
    }

    // --- senha: trocar derruba as sessões abertas ----------------------------

    public function testLoginAmarraASessaoAoHashDaSenha(): void
    {
        $res = Endpoint::call('auth/login.php', [
            'dsn' => $this->db->dsn(),
            'body' => ['username' => 'chefe', 'password' => 'senha-master-123'],
        ]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame(apiPasswordFingerprint($this->hashDe($this->masterId)), $res->session['pwd_fp'] ?? null);
    }

    /**
     * O fluxo inteiro: o master loga e fica com a aba aberta; o root gera uma
     * senha nova para ele; a sessão que já estava aberta cai, e não só o próximo
     * login passa a exigir a senha nova.
     */
    public function testResetDeSenhaDerrubaASessaoJaAberta(): void
    {
        $login = Endpoint::call('auth/login.php', [
            'dsn' => $this->db->dsn(),
            'body' => ['username' => 'chefe', 'password' => 'senha-master-123'],
        ]);
        $sessaoAberta = $login->session;
        self::assertSame(200, $this->get('clients/index.php', $sessaoAberta)->status, 'a sessão recém-aberta precisa funcionar');

        $reset = $this->post('users/edit.php', $this->sessaoRoot(), [
            'user_id' => $this->masterId,
            'login' => 'chefe',
            'email' => 'chefe@exemplo.com.br',
            'generate_password' => true,
        ]);
        self::assertSame(200, $reset->status, $reset->body);

        $depois = $this->get('clients/index.php', $sessaoAberta);
        self::assertSame(403, $depois->status, $depois->body);
        self::assertSame([], $depois->session, 'a sessão aberta antes do reset tem que ser esvaziada');
    }

    public function testResetPeloGeradorDeSenhaTambemDerrubaASessaoJaAberta(): void
    {
        $login = Endpoint::call('auth/login.php', [
            'dsn' => $this->db->dsn(),
            'body' => ['username' => 'joao', 'password' => 'senha-user-123'],
        ]);

        $this->post('users/generate_password.php', $this->sessaoRoot(), ['user_id' => $this->userId]);

        $depois = $this->get('users/logs.php', $login->session, ['user_id' => $this->userId]);
        self::assertSame(401, $depois->status, $depois->body);
    }

    /** Sessão aberta antes desta versão não tem impressão digital: é adotada, não derrubada. */
    public function testSessaoSemImpressaoDigitalEhAdotadaEmVezDeDerrubada(): void
    {
        $res = $this->get('clients/index.php', $this->sessaoMaster());

        self::assertSame(200, $res->status, $res->body);
        self::assertSame(apiPasswordFingerprint($this->hashDe($this->masterId)), $res->session['pwd_fp'] ?? null);
    }

    public function testTrocaDaPropriaSenhaMantemASessaoQueTrocou(): void
    {
        $this->sql('UPDATE users SET force_password_change = 1 WHERE id = ?', [$this->masterId]);
        $login = Endpoint::call('auth/login.php', [
            'dsn' => $this->db->dsn(),
            'body' => ['username' => 'chefe', 'password' => 'senha-master-123'],
        ]);

        $troca = $this->post('auth/change_password.php', $login->session, ['new_password' => 'senha-nova-456']);

        self::assertSame(200, $troca->status, $troca->body);
        self::assertSame(apiPasswordFingerprint($this->hashDe($this->masterId)), $troca->session['pwd_fp'] ?? null);
        self::assertSame(200, $this->get('clients/index.php', $troca->session)->status, 'quem trocou a senha foi deslogado');
    }

    // --- senha temporária: o servidor também barra ---------------------------

    /** @return array<string, array{0:string, 1:string}> */
    public static function rotasQueExigemTrocaDeSenhaPrimeiro(): array
    {
        return [
            'clientes' => ['GET', 'clients/index.php'],
            'faturas' => ['GET', 'invoices/index.php'],
            'usuários' => ['GET', 'users/index.php'],
            'grupos' => ['GET', 'groups/index.php'],
            'histórico' => ['GET', 'users/logs.php'],
            'painel de WhatsApp' => ['GET', 'whatsapp/conversations.php'],
        ];
    }

    #[DataProvider('rotasQueExigemTrocaDeSenhaPrimeiro')]
    public function testSenhaTemporariaBloqueiaAApi(string $metodo, string $script): void
    {
        $this->sql('UPDATE users SET force_password_change = 1 WHERE id = ?', [$this->masterId]);

        $res = $this->get($script, $this->sessaoMaster(), ['user_id' => $this->masterId]);

        self::assertSame(403, $res->status, "{$script}: {$res->body}");
        self::assertSame('password_change_required', $res->json()['code'] ?? null, $script);
    }

    public function testContaComumComSenhaTemporariaTambemEBloqueadaNoHistorico(): void
    {
        $this->sql('UPDATE users SET force_password_change = 1 WHERE id = ?', [$this->userId]);

        $res = $this->get('users/logs.php', ['user_id' => $this->userId, 'role' => 'user', 'login' => 'joao'], ['user_id' => $this->userId]);

        self::assertSame(403, $res->status, $res->body);
        self::assertSame('password_change_required', $res->json()['code'] ?? null);
    }

    /** As três portas que precisam continuar abertas para a pessoa conseguir trocar a senha. */
    public function testSenhaTemporariaDeixaPassarMeTrocaELogout(): void
    {
        $this->sql('UPDATE users SET force_password_change = 1 WHERE id = ?', [$this->masterId]);

        $me = $this->get('auth/me.php', $this->sessaoMaster());
        self::assertSame(200, $me->status, $me->body);
        self::assertTrue($me->json()['user']['force_password_change']);

        $troca = $this->post('auth/change_password.php', $this->sessaoMaster(), ['new_password' => 'senha-nova-456']);
        self::assertSame(200, $troca->status, $troca->body);

        $this->sql('UPDATE users SET force_password_change = 1 WHERE id = ?', [$this->masterId]);
        $logout = $this->post('auth/logout.php', $this->sessaoMaster(), []);
        self::assertSame(200, $logout->status, $logout->body);
    }

    public function testDepoisDeTrocarASenhaAApiVoltaAFuncionar(): void
    {
        $this->sql('UPDATE users SET force_password_change = 1 WHERE id = ?', [$this->masterId]);

        $troca = $this->post('auth/change_password.php', $this->sessaoMaster(), ['new_password' => 'senha-nova-456']);
        self::assertSame(200, $troca->status, $troca->body);

        self::assertSame(200, $this->get('clients/index.php', $troca->session)->status);
    }
}
