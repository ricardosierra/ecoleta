<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * public/api/auth/login.php, do começo ao fim, em um processo PHP de verdade.
 *
 * Os três caminhos que interessam são senha certa, senha errada e usuário
 * inexistente — e o ponto do teste não é só que os dois últimos falham, é que
 * falham do MESMO jeito. Resposta diferente para "esse login não existe" é um
 * oráculo de enumeração: sem tocar em senha nenhuma, dá para descobrir quais
 * contas existem.
 */
final class LoginTest extends TestCase
{
    private TestDatabase $db;

    private int $adminId;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        $grupo = $this->db->seedGroup('Coleta', 'https://app.powerbi.com/view?r=abc');
        $this->adminId = $this->db->seedUser('admin', 'senha-de-verdade-123', 'root', 'admin@ricasolucoes.com.br');
        $this->db->seedUser('joao', 'outra-senha-456', 'user', 'joao@ricasolucoes.com.br', $grupo);
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    private function login(array $body, array $options = []): EndpointResponse
    {
        return Endpoint::call('auth/login.php', array_merge([
            'dsn' => $this->db->dsn(),
            'body' => $body,
        ], $options));
    }

    // --- caminho 1: senha certa ---------------------------------------------

    public function testSenhaCertaAutentica(): void
    {
        $resposta = $this->login(['username' => 'admin', 'password' => 'senha-de-verdade-123']);

        self::assertSame(200, $resposta->status);

        $payload = $resposta->json();
        self::assertTrue($payload['ok']);
        self::assertSame($this->adminId, $payload['user']['id']);
        self::assertSame('admin', $payload['user']['login']);
        self::assertSame('root', $payload['user']['role']);
    }

    public function testLoginBemSucedidoGravaOPapelNaSessao(): void
    {
        $resposta = $this->login(['username' => 'admin', 'password' => 'senha-de-verdade-123']);

        self::assertSame($this->adminId, $resposta->session['user_id']);
        self::assertSame('root', $resposta->session['role']);
        self::assertSame('admin', $resposta->session['login']);
    }

    /**
     * O token que entrou na requisição não pode continuar valendo depois: uma
     * mudança de privilégio troca o ID da sessão e o token CSRF junto.
     */
    public function testTokenCsrfEhTrocadoNoLogin(): void
    {
        $resposta = $this->login(['username' => 'admin', 'password' => 'senha-de-verdade-123']);

        $novo = $resposta->json()['csrf_token'];
        self::assertSame(64, strlen($novo));
        self::assertNotSame(str_repeat('a', 64), $novo, 'o token anterior à autenticação continuou valendo');
        self::assertSame($novo, $resposta->session['csrf_token']);
    }

    public function testLoginTambemAceitaOEmail(): void
    {
        $resposta = $this->login(['username' => 'admin@ricasolucoes.com.br', 'password' => 'senha-de-verdade-123']);

        self::assertSame(200, $resposta->status);
        self::assertSame('admin', $resposta->json()['user']['login']);
    }

    public function testUsuarioComumRecebeOGrupoNoPayload(): void
    {
        $payload = $this->login(['username' => 'joao', 'password' => 'outra-senha-456'])->json();

        self::assertSame('user', $payload['user']['role']);
        self::assertSame('Coleta', $payload['user']['group_name']);
        self::assertSame('https://app.powerbi.com/view?r=abc', $payload['user']['group_powerbi_url']);
    }

    public function testLoginBemSucedidoDeixaTrilhaDeAuditoria(): void
    {
        $this->login(['username' => 'admin', 'password' => 'senha-de-verdade-123']);

        $acessos = $this->db->rows('access_logs');
        self::assertCount(1, $acessos);
        self::assertSame($this->adminId, (int) $acessos[0]['user_id']);
        self::assertSame('203.0.113.10', $acessos[0]['ip_address']);

        $atividades = $this->db->rows('activity_logs');
        self::assertCount(1, $atividades);
        self::assertSame('login', $atividades[0]['action']);
        self::assertSame('Login realizado com sucesso', $atividades[0]['description']);
        self::assertSame('admin', $atividades[0]['performed_by_login']);
    }

    // --- caminho 2: senha errada --------------------------------------------

    public function testSenhaErradaEhRecusada(): void
    {
        $resposta = $this->login(['username' => 'admin', 'password' => 'chute-errado']);

        self::assertSame(401, $resposta->status);
        self::assertSame('Credenciais inválidas.', $resposta->error());
    }

    /** A senha de outra conta não serve para esta. */
    public function testSenhaDeOutraContaNaoAutentica(): void
    {
        $resposta = $this->login(['username' => 'admin', 'password' => 'outra-senha-456']);

        self::assertSame(401, $resposta->status);
    }

    // --- caminho 3: usuário inexistente -------------------------------------

    public function testUsuarioInexistenteEhRecusado(): void
    {
        $resposta = $this->login(['username' => 'ninguem', 'password' => 'chute-errado']);

        self::assertSame(401, $resposta->status);
        self::assertSame('Credenciais inválidas.', $resposta->error());
    }

    // --- a propriedade que liga os caminhos 2 e 3 ---------------------------

    /**
     * Byte a byte: mesmo status, mesmo corpo. Qualquer diferença — "usuário não
     * encontrado", um campo a mais, outra pontuação — devolve de graça a lista
     * de logins válidos para quem estiver testando nomes.
     */
    public function testSenhaErradaEUsuarioInexistenteRespondemIgual(): void
    {
        $senhaErrada = $this->login(['username' => 'admin', 'password' => 'chute-errado']);
        $inexistente = $this->login(['username' => 'ninguem', 'password' => 'chute-errado']);

        self::assertSame($senhaErrada->status, $inexistente->status);
        self::assertSame($senhaErrada->body, $inexistente->body);
    }

    /** Nem no log do servidor a diferença pode aparecer para o cliente ver. */
    public function testNenhumaDasFalhasCriaSessaoAutenticada(): void
    {
        foreach ([['admin', 'chute-errado'], ['ninguem', 'chute-errado']] as [$login, $senha]) {
            $resposta = $this->login(['username' => $login, 'password' => $senha]);

            self::assertArrayNotHasKey('user_id', $resposta->session, "sessão criada para {$login}");
            self::assertArrayNotHasKey('role', $resposta->session, "papel gravado para {$login}");
        }
    }

    public function testFalhaDeLoginNaoDeixaTrilhaDeAcesso(): void
    {
        $this->login(['username' => 'admin', 'password' => 'chute-errado']);
        $this->login(['username' => 'ninguem', 'password' => 'chute-errado']);

        self::assertSame(0, $this->db->count('access_logs'));
        self::assertSame(0, $this->db->count('activity_logs'));
    }

    // --- entradas malformadas -----------------------------------------------

    public function testMetodoGetNaoEhAceito(): void
    {
        $resposta = $this->login([], ['method' => 'GET']);

        self::assertSame(405, $resposta->status);
    }

    public function testCorpoIncompletoEhRecusadoAntesDoBanco(): void
    {
        foreach ([[], ['username' => 'admin'], ['password' => 'x'], ['username' => '  ', 'password' => 'x']] as $corpo) {
            $resposta = $this->login($corpo);

            self::assertSame(400, $resposta->status, 'corpo: ' . json_encode($corpo));
            self::assertSame('Usuário e senha são obrigatórios.', $resposta->error());
        }
    }

    public function testCorpoQueNaoEhJsonEhTratadoComoVazio(): void
    {
        $resposta = $this->login([], ['body' => 'isto não é json']);

        self::assertSame(400, $resposta->status);
    }

    // --- CSRF ---------------------------------------------------------------

    /**
     * O DSN aponta para um caminho impossível: se o endpoint chegasse a abrir o
     * banco a resposta seria 500. O 403 prova que a recusa veio antes disso.
     */
    public function testSemTokenCsrfARequisicaoParaAntesDeTocarNoBanco(): void
    {
        $resposta = $this->login(
            ['username' => 'admin', 'password' => 'senha-de-verdade-123'],
            ['csrf' => false, 'dsn' => 'sqlite:/dev/null/impossivel.sqlite']
        );

        self::assertSame(403, $resposta->status);
        self::assertSame('csrf_invalid', $resposta->json()['code']);
        self::assertTrue($resposta->logged('CSRF rejeitado'), 'a recusa precisa aparecer no log do servidor');
    }

    public function testTokenCsrfErradoEhRecusado(): void
    {
        $resposta = $this->login(
            ['username' => 'admin', 'password' => 'senha-de-verdade-123'],
            ['csrf' => str_repeat('b', 64)]
        );

        self::assertSame(403, $resposta->status);
        self::assertSame('csrf_invalid', $resposta->json()['code']);
    }

    // --- degradação ----------------------------------------------------------

    /**
     * Rate limit é proteção, não pré-requisito. Com a tabela de throttle fora
     * do ar o login continua atendendo e o motivo vai para o log — o contrário
     * trancaria todo mundo para fora por causa de uma tabela auxiliar.
     */
    public function testLoginFuncionaComOThrottleIndisponivel(): void
    {
        $this->db->dropTable('login_throttle');

        $resposta = $this->login(['username' => 'admin', 'password' => 'senha-de-verdade-123']);

        self::assertSame(200, $resposta->status);
        self::assertTrue($resposta->logged('Rate limit de login indisponível'));
    }

    /** Banco atrás do código responde 503 e não tenta autenticar ninguém. */
    public function testBancoSemMigrationsRespondeIndisponivel(): void
    {
        $this->db->dropTable('schema_migrations');

        $resposta = $this->login(['username' => 'admin', 'password' => 'senha-de-verdade-123']);

        self::assertSame(503, $resposta->status);
        self::assertSame('schema_out_of_date', $resposta->json()['code']);
    }
}
