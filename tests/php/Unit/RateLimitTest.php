<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/rate_limit.php';

/**
 * PDO de mentira para a ORQUESTRAÇÃO do rate limit: registra o que foi
 * executado e responde "quantas falhas o contador tem" a partir de um mapa.
 *
 * Não valida SQL: o SQL de rate_limit.php é MySQL (NOW, TIMESTAMPDIFF,
 * ON DUPLICATE KEY) e a suíte roda em SQLite, que falha aberto nele. O que isto
 * prova é a lógica em PHP em volta do SQL: qual contador é alimentado por qual
 * tentativa e qual contador recebe qual bloqueio.
 */
final class FakeThrottlePdo extends PDO
{
    /** @var list<array{0:string,1:array}> */
    public array $executed = [];

    /** @var array<string,int> bucket => falhas acumuladas */
    public array $failures = [];

    public function __construct()
    {
        // Sem parent::__construct: não há banco nenhum por trás.
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = [])
    {
        return new FakeThrottleStatement($this, $query);
    }
}

final class FakeThrottleStatement extends PDOStatement
{
    private array $params = [];

    public function __construct(private FakeThrottlePdo $pdo, private string $sql)
    {
    }

    #[\ReturnTypeWillChange]
    public function execute(?array $params = null)
    {
        $this->params = $params ?? [];
        $this->pdo->executed[] = [$this->sql, $this->params];

        // INSERT ... ON DUPLICATE KEY UPDATE: soma uma falha no contador.
        if (str_contains($this->sql, 'INSERT INTO login_throttle')) {
            $bucket = (string) $this->params[0];
            $this->pdo->failures[$bucket] = ($this->pdo->failures[$bucket] ?? 0) + 1;
        }

        return true;
    }

    #[\ReturnTypeWillChange]
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0)
    {
        if (str_contains($this->sql, 'SELECT failures')) {
            return ['failures' => $this->pdo->failures[(string) $this->params[0]] ?? 0];
        }

        return false;
    }
}

/**
 * Rate limit de login — public/api/rate_limit.php.
 *
 * O cálculo de tempo é feito pelo MySQL de propósito (NOW(), TIMESTAMPDIFF),
 * e por isso não é reproduzível aqui. O que dá para fixar sem banco é a parte
 * que decide QUAL contador uma tentativa alimenta, mais a promessa de falhar
 * aberto quando a tabela não responde.
 */
final class RateLimitTest extends TestCase
{
    private string $previousErrorLog = '';

    private string $errorLogFile = '';

    /**
     * O rate limit registra cada bloqueio com error_log(). Sem desviar, o texto
     * vai para o terminal no meio dos pontos do PHPUnit.
     */
    protected function setUp(): void
    {
        $this->previousErrorLog = (string) ini_get('error_log');
        $this->errorLogFile = (string) tempnam(sys_get_temp_dir(), 'ecoleta_ratelimit_');
        ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        if ($this->errorLogFile !== '' && is_file($this->errorLogFile)) {
            unlink($this->errorLogFile);
        }
    }

    public function testBucketEhOSha256DoEscopoComAChave(): void
    {
        self::assertSame(
            hash('sha256', 'ip|203.0.113.9'),
            loginThrottleBucket(LOGIN_THROTTLE_SCOPE_IP, '203.0.113.9')
        );
    }

    /**
     * Toda tentativa alimenta três contadores: o do par (login, IP), que pega a
     * força bruta contra uma conta vinda de um endereço; o do IP sozinho, que
     * pega o password spraying (trocar de login a cada tentativa nunca encheria
     * o primeiro); e o da CONTA sozinha, que pega o contrário do spraying: muitos
     * IPs contra o mesmo login, em que cada par fica longe do limite.
     */
    public function testTentativaAlimentaOsTresContadores(): void
    {
        $buckets = loginThrottleBuckets('admin', '203.0.113.9');

        self::assertCount(3, $buckets);
        self::assertSame(LOGIN_THROTTLE_SCOPE_PAIR, $buckets[0][0]);
        self::assertSame(LOGIN_THROTTLE_SCOPE_IP, $buckets[1][0]);
        self::assertSame(LOGIN_THROTTLE_SCOPE_ACCOUNT, $buckets[2][0]);
        self::assertSame(LOGIN_THROTTLE_MAX_FAILURES, $buckets[0][2]);
        self::assertSame(LOGIN_THROTTLE_IP_MAX_FAILURES, $buckets[1][2]);
        self::assertSame(LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES, $buckets[2][2]);
    }

    public function testContadorPorIpEhMaisFolgadoQueODoPar(): void
    {
        self::assertGreaterThan(
            LOGIN_THROTTLE_MAX_FAILURES,
            LOGIN_THROTTLE_IP_MAX_FAILURES,
            'um escritório inteiro pode sair pelo mesmo IP; bloquear no mesmo limite do par derrubaria gente legítima'
        );
    }

    /**
     * 'Admin', 'admin' e ' admin ' são o mesmo login para o MySQL — se não
     * fossem o mesmo contador aqui, bastaria variar a caixa para ganhar cinco
     * tentativas extras a cada variação.
     */
    public function testVariacaoDeCaixaEEspacoCaiNoMesmoContador(): void
    {
        $referencia = loginThrottleBuckets('admin', '203.0.113.9')[0][1];

        foreach (['Admin', 'ADMIN', ' admin ', "\tadmin\n"] as $variacao) {
            self::assertSame(
                $referencia,
                loginThrottleBuckets($variacao, '203.0.113.9')[0][1],
                "'{$variacao}' deveria cair no contador de 'admin'"
            );
        }
    }

    public function testContadorPorIpIgnoraOLogin(): void
    {
        $a = loginThrottleBuckets('admin', '203.0.113.9')[1][1];
        $b = loginThrottleBuckets('outro', '203.0.113.9')[1][1];

        self::assertSame($a, $b);
    }

    public function testIpsDiferentesNaoCompartilhamContador(): void
    {
        $a = loginThrottleBuckets('admin', '203.0.113.9');
        $b = loginThrottleBuckets('admin', '198.51.100.4');

        self::assertNotSame($a[0][1], $b[0][1]);
        self::assertNotSame($a[1][1], $b[1][1]);
    }

    // --- teto por conta ------------------------------------------------------

    /**
     * O defeito: o contador do par inclui o IP, então cada IP novo ganhava as
     * suas cinco tentativas contra "admin". O contador da conta não pode saber
     * de IP nenhum.
     */
    public function testContadorDaContaIgnoraOIp(): void
    {
        $a = loginThrottleBuckets('admin', '203.0.113.9')[2][1];
        $b = loginThrottleBuckets('admin', '198.51.100.4')[2][1];
        $c = loginThrottleBuckets('admin', '2001:db8::1')[2][1];

        self::assertSame($a, $b);
        self::assertSame($a, $c);
    }

    public function testContasDiferentesTemContadoresDeContaDiferentes(): void
    {
        self::assertNotSame(
            loginThrottleBuckets('admin', '203.0.113.9')[2][1],
            loginThrottleBuckets('joao', '203.0.113.9')[2][1]
        );
    }

    public function testContadorDaContaNaoColideComOsOutros(): void
    {
        $buckets = loginThrottleBuckets('admin', '203.0.113.9');

        self::assertCount(3, array_unique(array_column($buckets, 1)));
    }

    /**
     * O MySQL da hospedagem compara com colação que ignora caixa E acento:
     * 'admin', 'ADMIN' e 'admín' encontram a mesma conta. Se a chave só baixasse
     * a caixa, 'admín' ganharia um contador novo a cada variação.
     */
    #[DataProvider('variacoesDeAdmin')]
    public function testVariacoesQueOBancoTrataComoIguaisCaemNoMesmoContador(string $variacao): void
    {
        $referencia = loginThrottleBuckets('admin', '203.0.113.9');
        $buckets = loginThrottleBuckets($variacao, '203.0.113.9');

        self::assertSame($referencia[0][1], $buckets[0][1], "par: '{$variacao}'");
        self::assertSame($referencia[2][1], $buckets[2][1], "conta: '{$variacao}'");
    }

    public static function variacoesDeAdmin(): array
    {
        return [
            'caixa alta' => ['ADMIN'],
            'caixa mista' => ['AdMiN'],
            'espaços' => ['  admin  '],
            'acento agudo' => ['admín'],
            'acento grave' => ['àdmin'],
            'til' => ['ãdmin'],
            'circunflexo e caixa alta' => ['ÂDMIN'],
            'trema' => ['admïn'],
        ];
    }

    public function testNormalizacaoCobreLoginsComAcentoDePortugues(): void
    {
        self::assertSame('joao', loginThrottleNormalizeLogin('João'));
        self::assertSame('jose', loginThrottleNormalizeLogin(' JOSÉ '));
        self::assertSame('conceicao', loginThrottleNormalizeLogin('Conceição'));
        self::assertSame('maria.silva@exemplo.com.br', loginThrottleNormalizeLogin('Maria.Silva@Exemplo.com.br'));
    }

    /**
     * O caminho sem a extensão intl (hospedagem que não a tem) precisa dar o
     * mesmo resultado que o com intl nas letras que importam aqui.
     */
    public function testCaminhoSemIntlDaOMesmoResultadoNasLetrasDoPortugues(): void
    {
        foreach (['joão', 'josé', 'conceição', 'águia', 'pingüim', 'açaí', 'ônibus', 'avô'] as $palavra) {
            self::assertSame(
                loginThrottleNormalizeLogin($palavra),
                loginThrottleStripAccentsFallback(mb_strtolower($palavra, 'UTF-8')),
                $palavra
            );
        }
    }

    public function testLoginDiferenteNaoViraOMesmoContador(): void
    {
        // Só acento e caixa são "iguais": ponto, hífen e dígito distinguem contas.
        self::assertNotSame(loginThrottleNormalizeLogin('joao.silva'), loginThrottleNormalizeLogin('joaosilva'));
        self::assertNotSame(loginThrottleNormalizeLogin('joao1'), loginThrottleNormalizeLogin('joao'));
    }

    public function testContadorDaContaEMaisFolgadoQueODoParEPodeSerMenosQueODoIp(): void
    {
        self::assertGreaterThan(
            LOGIN_THROTTLE_MAX_FAILURES,
            LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES,
            'quem erra sozinho esbarra no limite do par primeiro; o da conta existe para o ataque vindo de vários IPs'
        );
    }

    // --- decisão pura: quanto bloquear ----------------------------------------

    public function testAbaixoDoLimiteNaoBloqueia(): void
    {
        foreach ([0, 1, 4] as $falhas) {
            self::assertSame(0, loginThrottleBlockSeconds($falhas, 5), "falhas={$falhas}");
        }
    }

    public function testNoLimiteBloqueiaPeloTempoBaseEDobraAteOTeto(): void
    {
        self::assertSame(60, loginThrottleBlockSeconds(5, 5));
        self::assertSame(120, loginThrottleBlockSeconds(6, 5));
        self::assertSame(240, loginThrottleBlockSeconds(7, 5));
        self::assertSame(480, loginThrottleBlockSeconds(8, 5));
        self::assertSame(LOGIN_THROTTLE_MAX_BLOCK, loginThrottleBlockSeconds(9, 5), '960 seria o próximo: o teto corta');
        self::assertSame(LOGIN_THROTTLE_MAX_BLOCK, loginThrottleBlockSeconds(10, 5));
    }

    public function testBloqueioNuncaPassaDoTetoNemEstouraComMuitasFalhas(): void
    {
        foreach ([50, 1000, PHP_INT_MAX] as $falhas) {
            self::assertSame(LOGIN_THROTTLE_MAX_BLOCK, loginThrottleBlockSeconds($falhas, 5), "falhas={$falhas}");
        }
    }

    public function testCadaContadorUsaOProprioLimite(): void
    {
        $decisao = loginThrottleDecide([
            LOGIN_THROTTLE_SCOPE_PAIR => LOGIN_THROTTLE_MAX_FAILURES,
            LOGIN_THROTTLE_SCOPE_IP => 3,
            LOGIN_THROTTLE_SCOPE_ACCOUNT => LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES - 1,
        ]);

        self::assertSame(LOGIN_THROTTLE_BASE_BLOCK, $decisao[LOGIN_THROTTLE_SCOPE_PAIR]);
        self::assertSame(0, $decisao[LOGIN_THROTTLE_SCOPE_IP]);
        self::assertSame(0, $decisao[LOGIN_THROTTLE_SCOPE_ACCOUNT]);
    }

    /** Muitos IPs, cada um longe do limite do par: só o contador da conta dispara. */
    public function testAtaqueDistribuidoSoEPegoPeloContadorDaConta(): void
    {
        $decisao = loginThrottleDecide([
            LOGIN_THROTTLE_SCOPE_PAIR => 1,
            LOGIN_THROTTLE_SCOPE_IP => 1,
            LOGIN_THROTTLE_SCOPE_ACCOUNT => LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES,
        ]);

        self::assertSame(0, $decisao[LOGIN_THROTTLE_SCOPE_PAIR]);
        self::assertSame(0, $decisao[LOGIN_THROTTLE_SCOPE_IP]);
        self::assertSame(LOGIN_THROTTLE_BASE_BLOCK, $decisao[LOGIN_THROTTLE_SCOPE_ACCOUNT]);
    }

    public function testEscopoDesconhecidoNaoBloqueiaNada(): void
    {
        self::assertSame([], loginThrottleDecide(['inventado' => 9999]));
    }

    public function testMaiorBloqueioEscolheOMaiorDosContadores(): void
    {
        self::assertSame(0, loginThrottleLongestBlock([]));
        self::assertSame(0, loginThrottleLongestBlock([LOGIN_THROTTLE_SCOPE_PAIR => 0]));
        self::assertSame(240, loginThrottleLongestBlock([LOGIN_THROTTLE_SCOPE_PAIR => 60, LOGIN_THROTTLE_SCOPE_ACCOUNT => 240, LOGIN_THROTTLE_SCOPE_IP => 120]));
    }

    // --- orquestração: quem recebe qual bloqueio -------------------------------

    /** @return list<string> buckets que receberam UPDATE de bloqueio, com os segundos */
    private function bloqueiosAplicados(FakeThrottlePdo $db): array
    {
        $aplicados = [];
        foreach ($db->executed as [$sql, $params]) {
            if (str_contains($sql, 'UPDATE login_throttle SET blocked_until')) {
                preg_match('/INTERVAL (\d+) SECOND/', $sql, $m);
                $aplicados[(string) $params[0]] = (int) $m[1];
            }
        }

        return $aplicados;
    }

    /**
     * O defeito reproduzido em nível de orquestração: "admin" testado de IPs
     * diferentes, cada um com poucas falhas. Antes só o par e o IP contavam, e
     * nenhum dos dois chegava perto do limite; agora o contador da conta
     * dispara na falha de número LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES, de onde
     * vier.
     */
    public function testVariosIpsContraOMesmoLoginAcabamBloqueadosPelaConta(): void
    {
        $db = new FakeThrottlePdo();
        $contaBucket = loginThrottleBuckets('admin', '10.0.0.1')[2][1];

        for ($i = 1; $i < LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES; $i++) {
            $bloqueio = loginThrottleRegisterFailure($db, 'admin', '10.0.0.' . $i);
            self::assertSame(0, $bloqueio, "falha {$i} de IP diferente já bloqueou");
        }
        self::assertSame([], $this->bloqueiosAplicados($db), 'nenhum contador deveria ter estourado ainda');

        $bloqueio = loginThrottleRegisterFailure($db, 'admin', '10.0.0.' . LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES);

        self::assertSame(LOGIN_THROTTLE_BASE_BLOCK, $bloqueio);
        self::assertSame([$contaBucket => LOGIN_THROTTLE_BASE_BLOCK], $this->bloqueiosAplicados($db), 'só o contador da conta deveria bloquear');
    }

    /** Variar acento e caixa do login não escapa do contador da conta. */
    public function testVariarAcentoECaixaNaoEscapaDoContadorDaConta(): void
    {
        $db = new FakeThrottlePdo();
        $variacoes = ['admin', 'ADMIN', 'admín', 'Àdmin', ' admin '];

        for ($i = 0; $i < LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES; $i++) {
            loginThrottleRegisterFailure($db, $variacoes[$i % count($variacoes)], '10.0.1.' . $i);
        }

        $contaBucket = loginThrottleBuckets('admin', '10.0.0.1')[2][1];
        self::assertSame(LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES, $db->failures[$contaBucket], 'as variações viraram contadores diferentes');
        self::assertArrayHasKey($contaBucket, $this->bloqueiosAplicados($db));
    }

    /** Logins diferentes de IPs diferentes não se somam: cada conta tem o seu teto. */
    public function testContasDiferentesNaoSomamFalhas(): void
    {
        $db = new FakeThrottlePdo();

        for ($i = 0; $i < LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES - 1; $i++) {
            loginThrottleRegisterFailure($db, 'conta' . $i, '10.0.2.' . $i);
        }

        self::assertSame([], $this->bloqueiosAplicados($db));
    }

    public function testParEstouraPrimeiroQuandoUmSoIpErra(): void
    {
        $db = new FakeThrottlePdo();
        $buckets = loginThrottleBuckets('admin', '10.0.3.1');

        for ($i = 1; $i < LOGIN_THROTTLE_MAX_FAILURES; $i++) {
            self::assertSame(0, loginThrottleRegisterFailure($db, 'admin', '10.0.3.1'));
        }
        $bloqueio = loginThrottleRegisterFailure($db, 'admin', '10.0.3.1');

        self::assertSame(LOGIN_THROTTLE_BASE_BLOCK, $bloqueio);
        self::assertSame([$buckets[0][1] => LOGIN_THROTTLE_BASE_BLOCK], $this->bloqueiosAplicados($db));
    }

    public function testBloqueioMaiorEntreOsContadoresEhOQueVolta(): void
    {
        $db = new FakeThrottlePdo();
        $buckets = loginThrottleBuckets('admin', '10.0.4.1');
        // O par já estava 3 falhas além do limite; a conta acabou de chegar no dela.
        $db->failures[$buckets[0][1]] = LOGIN_THROTTLE_MAX_FAILURES + 2;
        $db->failures[$buckets[2][1]] = LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES - 1;

        $bloqueio = loginThrottleRegisterFailure($db, 'admin', '10.0.4.1');

        // par: 5+3 = 8 falhas => 60 * 2^3 = 480; conta: 15 => 60.
        self::assertSame(480, $bloqueio);
        self::assertSame(
            [$buckets[0][1] => 480, $buckets[2][1] => LOGIN_THROTTLE_BASE_BLOCK],
            $this->bloqueiosAplicados($db)
        );
    }

    /** A leitura do bloqueio consulta os três contadores, o da conta inclusive. */
    public function testLeituraDoBloqueioConsultaOsTresContadores(): void
    {
        $db = new FakeThrottlePdo();

        loginThrottleRetryAfter($db, 'admin', '10.0.5.1');

        [$sql, $params] = $db->executed[0];
        self::assertStringContainsString('bucket IN (?, ?, ?)', $sql);
        self::assertSame(array_column(loginThrottleBuckets('admin', '10.0.5.1'), 1), $params);
    }

    /** Sucesso zera só o par: o teto por conta e o por IP seguem de pé. */
    public function testSucessoZeraSoOContadorDoPar(): void
    {
        $db = new FakeThrottlePdo();

        loginThrottleClear($db, 'admin', '10.0.6.1');

        self::assertCount(1, $db->executed);
        self::assertSame([loginThrottleBuckets('admin', '10.0.6.1')[0][1]], $db->executed[0][1]);
    }

    // --- janela x bloqueio máximo --------------------------------------------

    /**
     * Quando a janela era IGUAL ao bloqueio máximo (900s), o contador reiniciava
     * junto com o fim do bloqueio mais longo: a primeira falha depois dele
     * voltava a contar 1 e a escada de bloqueio recomeçava do zero. O atacante
     * paciente ganhava cinco tentativas novas a cada ciclo em vez de uma por
     * bloqueio máximo (algo como 18 por hora no lugar de 4). A janela tem que
     * sobreviver ao bloqueio máximo, com folga para a falha que vem logo depois
     * dele.
     */
    public function testJanelaSobreviveAoBloqueioMaximo(): void
    {
        self::assertGreaterThan(
            LOGIN_THROTTLE_MAX_BLOCK + 1,
            LOGIN_THROTTLE_WINDOW,
            'a janela não pode acabar junto com o bloqueio máximo: o contador zeraria no instante em que ele termina'
        );
    }

    public function testRetencaoDasLinhasSobreviveAJanela(): void
    {
        self::assertGreaterThan(LOGIN_THROTTLE_WINDOW, LOGIN_THROTTLE_RETENTION);
    }

    /** Escopos diferentes não colidem mesmo com a mesma chave literal. */
    public function testEscoposNaoColidem(): void
    {
        self::assertNotSame(
            loginThrottleBucket(LOGIN_THROTTLE_SCOPE_PAIR, 'x'),
            loginThrottleBucket(LOGIN_THROTTLE_SCOPE_IP, 'x')
        );
    }

    /**
     * Falha aberta é decisão de projeto: tabela de throttle indisponível não
     * pode trancar o login de todo mundo. O preço é ficar sem proteção nessa
     * janela, e o motivo tem que aparecer no log.
     */
    public function testThrottleIndisponivelNaoBloqueiaNemLancaExcecao(): void
    {
        $db = new TestDatabase();
        $db->dropTable('login_throttle');

        $errorLog = tempnam(sys_get_temp_dir(), 'ecoleta_throttle_');
        $previous = (string) ini_get('error_log');
        ini_set('error_log', $errorLog);

        try {
            self::assertSame(0, loginThrottleRetryAfter($db->pdo(), 'admin', '203.0.113.9'));
            self::assertSame(0, loginThrottleRegisterFailure($db->pdo(), 'admin', '203.0.113.9'));
            loginThrottleClear($db->pdo(), 'admin', '203.0.113.9');

            $registrado = (string) file_get_contents($errorLog);
            self::assertStringContainsString('Rate limit de login indisponível na leitura', $registrado);
            self::assertStringContainsString('Rate limit de login indisponível na escrita', $registrado);
        } finally {
            ini_set('error_log', $previous);
            unlink($errorLog);
            $db->destroy();
        }
    }
}
