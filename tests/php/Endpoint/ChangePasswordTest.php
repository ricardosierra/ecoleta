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

        $resposta = $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456', 'current_password' => 'senha-antiga-123']);

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
        $resposta = $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456', 'current_password' => 'senha-antiga-123']);

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
            'current_password' => 'senha-antiga-123',
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

        $resposta = $this->call($this->comoJoao(), ['new_password' => 'abc', 'current_password' => 'senha-antiga-123']);

        self::assertSame(400, $resposta->status);
        self::assertSame($antes, $this->hashDe($this->joaoId));
    }

    /**
     * Seis caracteres passavam. O corte agora é oito: sete é recusado, oito
     * passa — a fronteira exata, nos dois lados.
     */
    public function testSenhaDeSeteCaracteresEhRecusadaEDeOitoPassa(): void
    {
        $antes = $this->hashDe($this->joaoId);

        $curta = $this->call($this->comoJoao(), ['new_password' => 'abcdefg', 'current_password' => 'senha-antiga-123']);
        self::assertSame(400, $curta->status, $curta->body);
        self::assertStringContainsString('8 caracteres', (string) $curta->error());
        self::assertSame($antes, $this->hashDe($this->joaoId));

        $ok = $this->call($this->comoJoao(), ['new_password' => 'abcdefgh', 'current_password' => 'senha-antiga-123']);
        self::assertSame(200, $ok->status, $ok->body);
    }

    // --- troca voluntária: a senha atual vale ---------------------------------

    public function testTrocaVoluntariaExigeASenhaAtual(): void
    {
        $antes = $this->hashDe($this->joaoId);

        $resposta = $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456']);

        self::assertSame(400, $resposta->status, $resposta->body);
        self::assertSame('current_password_required', $resposta->json()['code'] ?? null);
        self::assertSame($antes, $this->hashDe($this->joaoId), 'a senha mudou sem a senha atual');
    }

    public function testTrocaVoluntariaRecusaSenhaAtualErrada(): void
    {
        $antes = $this->hashDe($this->joaoId);

        $resposta = $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456', 'current_password' => 'chute-errado']);

        self::assertSame(403, $resposta->status, $resposta->body);
        self::assertSame('current_password_invalid', $resposta->json()['code'] ?? null);
        self::assertSame($antes, $this->hashDe($this->joaoId));

        $rastro = $this->db->rows('activity_logs');
        self::assertCount(1, $rastro);
        self::assertSame('change_password_wrong_current', $rastro[0]['action']);
    }

    /**
     * Errar a senha atual é adivinhar a senha da conta por outra porta, então
     * entra nos contadores do login. Sob SQLite o throttle falha aberto (é
     * SQL de MySQL) e a tentativa continua recusada; o que a suíte confere é
     * que o contador foi de fato chamado, pelo erro que ele registra.
     */
    public function testSenhaAtualErradaPassaPeloThrottleDoLogin(): void
    {
        $resposta = $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456', 'current_password' => 'chute-errado']);

        self::assertSame(403, $resposta->status, $resposta->body);
        self::assertTrue(
            $resposta->logged('Rate limit de login indisponível na escrita'),
            'a falha de senha atual não foi registrada no contador de login'
        );
    }

    public function testTrocaVoluntariaVerificaOThrottleAntesDeConferirASenha(): void
    {
        $resposta = $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456', 'current_password' => 'senha-antiga-123']);

        self::assertSame(200, $resposta->status, $resposta->body);
        self::assertTrue($resposta->logged('Rate limit de login indisponível na leitura'));
    }

    // --- troca forçada: não exige a senha atual -------------------------------

    public function testTrocaForcadaNaoExigeASenhaAtual(): void
    {
        $this->db->pdo()->exec("UPDATE users SET force_password_change = 1 WHERE id = {$this->joaoId}");

        $resposta = $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456']);

        self::assertSame(200, $resposta->status, $resposta->body);
        self::assertTrue(password_verify('senha-nova-456', $this->hashDe($this->joaoId)));
    }

    // --- nova senha diferente da atual ----------------------------------------

    public function testNovaSenhaIgualAAtualEhRecusada(): void
    {
        $antes = $this->hashDe($this->joaoId);

        $resposta = $this->call($this->comoJoao(), ['new_password' => 'senha-antiga-123', 'current_password' => 'senha-antiga-123']);

        self::assertSame(400, $resposta->status, $resposta->body);
        self::assertStringContainsString('diferente', (string) $resposta->error());
        self::assertSame($antes, $this->hashDe($this->joaoId));
    }

    /** Na troca forçada a "atual" é a temporária: repeti-la deixaria a temporária valendo. */
    public function testTrocaForcadaTambemRecusaRepetirASenhaTemporaria(): void
    {
        $this->db->pdo()->exec("UPDATE users SET force_password_change = 1 WHERE id = {$this->joaoId}");
        $antes = $this->hashDe($this->joaoId);

        $resposta = $this->call($this->comoJoao(), ['new_password' => 'senha-antiga-123']);

        self::assertSame(400, $resposta->status, $resposta->body);
        self::assertSame($antes, $this->hashDe($this->joaoId));
        self::assertSame(1, $this->forcaTroca($this->joaoId), 'a exigência de troca foi desligada sem trocar nada');
    }

    private function forcaTroca(int $id): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT force_password_change FROM users WHERE id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
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
        $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456', 'current_password' => 'senha-antiga-123']);

        $auditoria = $this->db->rows('activity_logs');
        self::assertCount(1, $auditoria);
        self::assertSame('change_password', $auditoria[0]['action']);
        self::assertSame($this->joaoId, (int) $auditoria[0]['user_id']);
        self::assertSame('joao', $auditoria[0]['performed_by_login']);
    }

    /** A linha que o trigger grava ganha autoria: troca pela aplicação nunca fica como 'db_trigger'. */
    public function testHistoricoDeHashRecebeAutoriaDaTroca(): void
    {
        $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456', 'current_password' => 'senha-antiga-123']);

        $stmt = $this->db->pdo()->prepare('SELECT * FROM password_hash_history WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$this->joaoId]);
        $rows = $stmt->fetchAll();

        self::assertCount(2, $rows, 'criação + troca: o trigger grava uma linha por evento e a aplicação a enriquece, sem duplicar');
        self::assertSame('change_password', $rows[0]['change_type']);
        self::assertSame('joao', $rows[0]['changed_by_login']);
        self::assertSame($this->joaoId, (int) $rows[0]['changed_by_user_id']);
        self::assertSame($this->hashDe($this->joaoId), $rows[0]['new_hash']);
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

    /** Conta que já tem e-mail recusa tentativa de alterá-lo pela troca de senha. */
    public function testContaComEmailRecusaTentativaDeAlterarEmail(): void
    {
        $antes = $this->hashDe($this->joaoId);

        $resposta = $this->call($this->comoJoao(), [
            'new_password' => 'senha-nova-456',
            'current_password' => 'senha-antiga-123',
            'email' => 'tentativa@outro.com',
        ]);

        self::assertSame(400, $resposta->status, $resposta->body);
        self::assertSame(
            'A alteração de e-mail deve ser feita pela gestão de usuários.',
            $resposta->json()['error'] ?? null
        );
        $stmt = $this->db->pdo()->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$this->joaoId]);
        self::assertSame('joao@exemplo.com.br', $stmt->fetch()['email'], 'e-mail existente não muda por aqui');
        self::assertSame($antes, $this->hashDe($this->joaoId), 'senha não deve ser alterada quando a requisição é inválida');
    }

    /** Conta que já tem e-mail aceita envio redundante do mesmo e-mail. */
    public function testContaComEmailAceitaMesmoEmail(): void
    {
        $resposta = $this->call($this->comoJoao(), [
            'new_password' => 'senha-nova-456',
            'current_password' => 'senha-antiga-123',
            'email' => 'joao@exemplo.com.br',
        ]);

        self::assertSame(200, $resposta->status, $resposta->body);
        self::assertTrue(password_verify('senha-nova-456', $this->hashDe($this->joaoId)));
    }

    /** Conta que já tem e-mail aceita envio com campo de e-mail vazio ou em branco. */
    public function testContaComEmailAceitaEmailEmBranco(): void
    {
        $resposta = $this->call($this->comoJoao(), [
            'new_password' => 'senha-nova-456',
            'current_password' => 'senha-antiga-123',
            'email' => '   ',
        ]);

        self::assertSame(200, $resposta->status, $resposta->body);
        self::assertTrue(password_verify('senha-nova-456', $this->hashDe($this->joaoId)));
    }

    /** A senha nova é a que passa a valer no login. */
    public function testSenhaNovaAutenticaEAAntigaNao(): void
    {
        $this->call($this->comoJoao(), ['new_password' => 'senha-nova-456', 'current_password' => 'senha-antiga-123']);

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

    public function testUsuarioComTrocaBloqueadaNaoConsegueTrocarASenha(): void
    {
        $this->db->pdo()->exec("UPDATE users SET password_locked = 1 WHERE id = {$this->joaoId}");
        $antes = $this->hashDe($this->joaoId);

        $resposta = $this->call($this->comoJoao(), ['new_password' => 'senha-proibida-999']);

        self::assertSame(403, $resposta->status);
        self::assertStringContainsString('bloqueada', $resposta->json()['error'] ?? '');
        self::assertSame($antes, $this->hashDe($this->joaoId));

        // Verifica que o evento de bloqueio foi registrado no histórico
        $stmt = $this->db->pdo()->query("SELECT COUNT(*) AS total FROM activity_logs WHERE action = 'change_password_blocked'");
        self::assertSame(1, (int) $stmt->fetch()['total']);
    }
}
