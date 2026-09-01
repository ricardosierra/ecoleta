<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * public/api/auth/change_password.php — a troca de senha feita pelo próprio
 * usuário, que é por onde passa todo mundo no primeiro acesso
 * (`force_password_change`).
 *
 * O alvo nunca sai do corpo da requisição: é sempre o id da sessão. É a
 * propriedade que impede a troca de senha de virar um sequestro de conta.
 */
final class ChangePasswordTest extends TestCase
{
    private TestDatabase $db;

    private int $joaoId;

    private int $adminId;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        $this->joaoId = $this->db->seedUser('joao', 'senha-antiga-123', 'user', null, $this->db->seedGroup('Coleta'));
        $this->adminId = $this->db->seedUser('admin', 'senha-root-123', 'root');
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    private function call(array $session, array $body, array $options = []): EndpointResponse
    {
        return Endpoint::call('auth/change_password.php', array_merge([
            'dsn' => $this->db->dsn(),
            'session' => $session,
            'body' => $body,
        ], $options));
    }

    private function comoJoao(): array
    {
        return ['user_id' => $this->joaoId, 'role' => 'user', 'login' => 'joao'];
    }

    private function hashDe(int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$id]);

        return (string) $stmt->fetch()['password_hash'];
    }

    public function testUsuarioTrocaAPropriaSenha(): void
    {
        $antes = $this->hashDe($this->joaoId);

        $resposta = $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456']);

        self::assertSame(200, $resposta->status);
        self::assertNotSame($antes, $this->hashDe($this->joaoId));
        self::assertTrue(password_verify('senha-nova-456', $this->hashDe($this->joaoId)));
    }

    /** A troca encerra o primeiro acesso: o dashboard para de exigir a tela. */
    public function testTrocaDesligaAExigenciaDeTrocaNoProximoAcesso(): void
    {
        $this->db->pdo()->exec("UPDATE users SET force_password_change = 1 WHERE id = {$this->joaoId}");

        $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456']);

        $stmt = $this->db->pdo()->prepare('SELECT force_password_change FROM users WHERE id = ?');
        $stmt->execute([$this->joaoId]);
        self::assertSame(0, (int) $stmt->fetch()['force_password_change']);
    }

    /** Troca de senha é mudança de privilégio: o token CSRF anterior morre. */
    public function testTokenCsrfEhTrocado(): void
    {
        $resposta = $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456']);

        self::assertNotSame(str_repeat('a', 64), $resposta->json()['csrf_token']);
        self::assertSame(64, strlen($resposta->json()['csrf_token']));
    }

    /**
     * O corpo pode pedir o que quiser: quem é trocado é o dono da sessão. Um
     * `user_id` alheio no JSON não pode alcançar a senha de um root.
     */
    public function testAlvoNoCorpoNaoAlcancaOutraConta(): void
    {
        $senhaDoRoot = $this->hashDe($this->adminId);

        $resposta = $this->call($this->comoJoao(), [
            'new_password' => 'senha-invadida-789',
            'user_id' => $this->adminId,
            'id' => $this->adminId,
        ]);

        self::assertSame(200, $resposta->status);
        self::assertSame($senhaDoRoot, $this->hashDe($this->adminId), 'a senha do root foi trocada por outra conta');
        self::assertTrue(password_verify('senha-invadida-789', $this->hashDe($this->joaoId)));
    }

    /**
     * Toda a API trata sessão de papel desconhecido como não autenticada. Este
     * endpoint era o único que lia $_SESSION['user_id'] na mão e deixava passar.
     */
    public function testSessaoComPapelDesconhecidoNaoTrocaSenha(): void
    {
        $antes = $this->hashDe($this->joaoId);

        $resposta = $this->call(
            ['user_id' => $this->joaoId, 'role' => 'papel-inventado', 'login' => 'joao'],
            ['new_password' => 'senha-nova-456']
        );

        self::assertSame(401, $resposta->status);
        self::assertSame($antes, $this->hashDe($this->joaoId));
    }

    public function testSessaoAnonimaNaoTrocaSenha(): void
    {
        $resposta = $this->call([], ['new_password' => 'senha-nova-456']);

        self::assertSame(401, $resposta->status);
    }

    public function testSenhaCurtaEhRecusada(): void
    {
        $antes = $this->hashDe($this->joaoId);

        $resposta = $this->call($this->comoJoao(), ['new_password' => 'abc']);

        self::assertSame(400, $resposta->status);
        self::assertSame($antes, $this->hashDe($this->joaoId));
    }

    public function testSemTokenCsrfNaoTrocaSenha(): void
    {
        $antes = $this->hashDe($this->joaoId);

        $resposta = $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456'], ['csrf' => false]);

        self::assertSame(403, $resposta->status);
        self::assertSame($antes, $this->hashDe($this->joaoId));
    }

    public function testTrocaFicaNaTrilhaDeAuditoria(): void
    {
        $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456']);

        $auditoria = $this->db->rows('activity_logs');
        self::assertCount(1, $auditoria);
        self::assertSame('change_password', $auditoria[0]['action']);
        self::assertSame($this->joaoId, (int) $auditoria[0]['user_id']);
        self::assertSame('joao', $auditoria[0]['performed_by_login']);
    }

    /** Conta criada sem e-mail: o primeiro acesso exige informar um. */
    public function testPrimeiroAcessoSemEmailExigeEmail(): void
    {
        $this->db->pdo()->exec("UPDATE users SET email = NULL, force_password_change = 1 WHERE id = {$this->joaoId}");

        $resposta = $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456']);

        self::assertSame(400, $resposta->status, $resposta->body);
        $stmt = $this->db->pdo()->prepare('SELECT force_password_change FROM users WHERE id = ?');
        $stmt->execute([$this->joaoId]);
        self::assertSame(1, (int) $stmt->fetch()['force_password_change'], 'sem e-mail o primeiro acesso não conclui');
    }

    /** Com o e-mail informado, o primeiro acesso grava e conclui. */
    public function testPrimeiroAcessoGravaEmailInformado(): void
    {
        $this->db->pdo()->exec("UPDATE users SET email = NULL, force_password_change = 1 WHERE id = {$this->joaoId}");

        $resposta = $this->call($this->comoJoao(), [
            'new_password' => 'senha-nova-456',
            'email' => 'joao.novo@empresa.com',
        ]);

        self::assertSame(200, $resposta->status, $resposta->body);
        $stmt = $this->db->pdo()->prepare('SELECT email, force_password_change FROM users WHERE id = ?');
        $stmt->execute([$this->joaoId]);
        $row = $stmt->fetch();
        self::assertSame('joao.novo@empresa.com', $row['email']);
        self::assertSame(0, (int) $row['force_password_change']);
    }

    /** E-mail já usado por outra conta é recusado no primeiro acesso. */
    public function testEmailDuplicadoNoPrimeiroAcessoEhRecusado(): void
    {
        $this->db->pdo()->exec("UPDATE users SET email = NULL, force_password_change = 1 WHERE id = {$this->joaoId}");

        $resposta = $this->call($this->comoJoao(), [
            'new_password' => 'senha-nova-456',
            'email' => 'admin@exemplo.com.br', // já é o e-mail do root semeado
        ]);

        self::assertSame(400, $resposta->status, $resposta->body);
    }

    /** Conta que já tem e-mail ignora o campo: a troca não o altera. */
    public function testContaComEmailIgnoraOCampoEmail(): void
    {
        $resposta = $this->call($this->comoJoao(), [
            'new_password' => 'senha-nova-456',
            'email' => 'tentativa@outro.com',
        ]);

        self::assertSame(200, $resposta->status, $resposta->body);
        $stmt = $this->db->pdo()->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$this->joaoId]);
        self::assertSame('joao@exemplo.com.br', $stmt->fetch()['email'], 'e-mail existente não muda por aqui');
    }

    /** A senha nova é a que passa a valer no login. */
    public function testSenhaNovaAutenticaEAAntigaNao(): void
    {
        $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456']);

        $comNova = Endpoint::call('auth/login.php', [
            'dsn' => $this->db->dsn(),
            'body' => ['username' => 'joao', 'password' => 'senha-nova-456'],
        ]);
        self::assertSame(200, $comNova->status);

        $comAntiga = Endpoint::call('auth/login.php', [
            'dsn' => $this->db->dsn(),
            'body' => ['username' => 'joao', 'password' => 'senha-antiga-123'],
        ]);
        self::assertSame(401, $comAntiga->status);
    }
}
