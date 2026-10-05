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

    /** @var array<string,array{scope:string,retry_after:int}> bucket => bloqueio ativo que a leitura devolve */
    public array $blocked = [];

    /** @var array<string,true> buckets de confiança presentes e dentro da validade */
    public array $trusted = [];

    /** A consulta de confiança lança, como numa tabela que quebrou no meio. */
    public bool $trustQueryFails = false;

    /** @var list<string> o que foi executado por exec() (a limpeza) */
    public array $execs = [];

    public function __construct()
    {
        // Sem parent::__construct: não há banco nenhum por trás.
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = [])
    {
        return new FakeThrottleStatement($this, $query);
    }

    #[\ReturnTypeWillChange]
    public function exec(string $statement)
    {
        $this->execs[] = $statement;

        return 0;
    }

    /** @return list<array{0:string,1:array}> as execuções cujo SQL contém o trecho */
    public function executedMatching(string $fragment): array
    {
        return array_values(array_filter(
            $this->executed,
            static fn (array $e): bool => str_contains($e[0], $fragment)
        ));
    }
}

final class FakeThrottleStatement extends PDOStatement
{
    private array $params = [];

    /** @var list<array<string,mixed>> linhas que a leitura de bloqueios devolve, uma por fetch() */
    private array $pending = [];

    public function __construct(private FakeThrottlePdo $pdo, private string $sql)
    {
    }

    #[\ReturnTypeWillChange]
    public function execute(?array $params = null)
    {
        $this->params = $params ?? [];
        $this->pdo->executed[] = [$this->sql, $this->params];

        // INSERT ... ON DUPLICATE KEY UPDATE de FALHA: soma uma falha no contador.
        // O upsert do marcador de confiança é outro INSERT (failures = 0) e não conta.
        if (str_contains($this->sql, 'failures = IF(')) {
            $bucket = (string) $this->params[0];
            $this->pdo->failures[$bucket] = ($this->pdo->failures[$bucket] ?? 0) + 1;
        }

        if (str_contains($this->sql, 'SELECT 1 FROM login_throttle') && $this->pdo->trustQueryFails) {
            throw new PDOException('tabela de throttle quebrou no meio');
        }

        // Leitura dos bloqueios ativos: uma linha por bucket bloqueado entre os pedidos.
        if (str_contains($this->sql, 'SELECT scope, GREATEST')) {
            $this->pending = [];
            foreach ($this->params as $bucket) {
                if (isset($this->pdo->blocked[(string) $bucket])) {
                    $this->pending[] = $this->pdo->blocked[(string) $bucket];
                }
            }
        }

        return true;
    }

    #[\ReturnTypeWillChange]
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0)
    {
        if (str_contains($this->sql, 'SELECT failures')) {
            return ['failures' => $this->pdo->failures[(string) $this->params[0]] ?? 0];
        }

        if (str_contains($this->sql, 'SELECT scope, GREATEST')) {
            return $this->pending === [] ? false : array_shift($this->pending);
        }

        if (str_contains($this->sql, 'SELECT 1 FROM login_throttle')) {
            return isset($this->pdo->trusted[(string) $this->params[0]]) ? [1 => 1] : false;
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

    /**
     * Sucesso zera o par e a CONTA e marca o IP como de confiança; o contador do
     * IP fica de pé (quem tem qualquer conta legítima espalharia chutes sem teto
     * se entrar nela entre uma rodada e outra).
     */
    public function testSucessoZeraParEContaMasNaoOIpEGravaConfianca(): void
    {
        $db = new FakeThrottlePdo();
        $conta = $this->conta();
        $buckets = loginThrottleBuckets('admin', '10.0.6.1', $conta);

        loginThrottleRegisterSuccess($db, 'admin', '10.0.6.1', $conta);

        self::assertCount(3, $db->executed);

        [$sqlPar, $paramsPar] = $db->executed[0];
        self::assertStringContainsString('DELETE FROM login_throttle WHERE bucket = ?', $sqlPar);
        self::assertSame([$buckets[0][1]], $paramsPar, 'o par');

        [$sqlConta, $paramsConta] = $db->executed[1];
        self::assertStringContainsString('DELETE FROM login_throttle', $sqlConta);
        self::assertSame([$buckets[2][1]], $paramsConta, 'a conta, pelo ID');

        [$sqlConfianca, $paramsConfianca] = $db->executed[2];
        self::assertStringContainsString('INSERT INTO login_throttle', $sqlConfianca);
        self::assertSame([loginThrottleTrustBucket($conta, '10.0.6.1'), LOGIN_THROTTLE_SCOPE_TRUSTED], $paramsConfianca);

        $tocados = array_merge(...array_map(static fn (array $e): array => $e[1], $db->executed));
        self::assertNotContains($buckets[1][1], $tocados, 'o contador do IP não pode ser zerado pelo sucesso');
    }

    /**
     * Um bloqueio da conta que já está ativo não é levantado pelo login do dono:
     * o DELETE da conta só atinge linha sem bloqueio vigente. Senão cada login do
     * dono durante um ataque devolveria ao atacante um orçamento novo de chutes.
     */
    public function testSucessoNaoLevantaBloqueioAtivoDaConta(): void
    {
        $db = new FakeThrottlePdo();

        loginThrottleRegisterSuccess($db, 'admin', '10.0.6.2', $this->conta());

        $sqlConta = $db->executed[1][0];
        self::assertStringContainsString('blocked_until IS NULL OR blocked_until <= NOW()', $sqlConta);
    }

    /** O marcador não é contador de falha: nasce com 0 e só renova a data do último sucesso. */
    public function testMarcadorDeConfiancaNaoCarregaFalhaNemBloqueio(): void
    {
        $db = new FakeThrottlePdo();

        loginThrottleRegisterSuccess($db, 'admin', '10.0.6.3', $this->conta());

        $sql = $db->executed[2][0];
        self::assertMatchesRegularExpression('/VALUES \(\?, \?, 0, NOW\(\), NOW\(\)\)/', $sql);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE last_failure_at = NOW()', $sql);
        self::assertStringNotContainsString('blocked_until', $sql);
        self::assertSame([], $db->failures, 'o marcador não pode ser somado como falha');
    }

    // --- normalização: o que a colação do banco iguala ---------------------------

    /** @return array{id:int,password_hash:string} a parte da linha de `users` que o throttle lê */
    private function conta(int $id = 7, string $hash = '$2y$10$hash-de-teste-um'): array
    {
        return ['id' => $id, 'password_hash' => $hash];
    }

    /**
     * Nas colações unicode do banco, "ａｄｍｉｎ" (largura total) é a conta "admin".
     * Sem dobrar a largura, cada grafia era um contador novo para um login que não
     * existe (e a conta que existe só é pega pelo ID, ver abaixo).
     */
    #[DataProvider('grafiasDeLarguraTotal')]
    public function testLarguraTotalCaiNoMesmoContadorQueOAscii(string $grafia, string $esperado): void
    {
        self::assertSame($esperado, loginThrottleNormalizeLogin($grafia));
        self::assertSame(
            loginThrottleBuckets($esperado, '203.0.113.9')[0][1],
            loginThrottleBuckets($grafia, '203.0.113.9')[0][1]
        );
    }

    public static function grafiasDeLarguraTotal(): array
    {
        return [
            'minúsculas' => ['ａｄｍｉｎ', 'admin'],
            'maiúsculas' => ['ＡＤＭＩＮ', 'admin'],
            'misturado com ASCII' => ['ａdmｉn', 'admin'],
            'dígitos' => ['joao１２３', 'joao123'],
            'e-mail inteiro' => ['ａｄｍｉｎ＠ｅｘａｍｐｌｅ．ｃｏｍ', 'admin@example.com'],
            'hífen e sublinhado' => ['ａ－ｂ＿ｃ', 'a-b_c'],
        ];
    }

    public function testDobrarLarguraNaoMexeNoQueNaoELarguraTotal(): void
    {
        self::assertSame('admin', loginThrottleFoldFullwidth('admin'));
        self::assertSame('João', loginThrottleFoldFullwidth('João'));
        self::assertSame('日本語', loginThrottleFoldFullwidth('日本語'));
        // Fim da faixa (U+FF5E) é o último; o que vem depois fica como está.
        self::assertSame('~', loginThrottleFoldFullwidth("\u{FF5E}"));
        self::assertSame("\u{FF5F}", loginThrottleFoldFullwidth("\u{FF5F}"));
        // Bytes que não são UTF-8 não derrubam nada.
        self::assertSame("\xff\xfe", loginThrottleFoldFullwidth("\xff\xfe"));
    }

    /** A colação ignora caracteres de formato: largura zero, hífen suave, BOM. */
    #[DataProvider('grafiasComInvisiveis')]
    public function testCaracteresInvisiveisNaoCriamOutroContador(string $grafia): void
    {
        self::assertSame('admin', loginThrottleNormalizeLogin($grafia));
    }

    public static function grafiasComInvisiveis(): array
    {
        return [
            'espaço de largura zero' => ["adm\u{200B}in"],
            'hífen suave' => ["ad\u{00AD}min"],
            'BOM no começo' => ["\u{FEFF}admin"],
            'junção de largura zero no fim' => ["admin\u{200D}"],
            'vários' => ["a\u{200B}d\u{00AD}m\u{200C}i\u{2060}n"],
        ];
    }

    /** ß vale "ss" e œ vale "oe" na colação; nenhuma decomposição Unicode os separa. */
    public function testEszettEOeExpandemComoAColacao(): void
    {
        self::assertSame('strasse', loginThrottleNormalizeLogin('Straße'));
        self::assertSame('oeuvre', loginThrottleNormalizeLogin('Œuvre'));
        // O caminho sem intl dá o mesmo.
        self::assertSame('strasse', loginThrottleStripAccentsFallback('straße'));
        self::assertSame('oeuvre', loginThrottleStripAccentsFallback('œuvre'));
    }

    public function testMarcasCombinantesSoltasSaemNosDoisCaminhos(): void
    {
        $acentoSolto = "a\u{0301}dmin";
        $marcaEspacadora = "admin\u{0903}";

        self::assertSame('admin', loginThrottleNormalizeLogin($acentoSolto));
        self::assertSame('admin', loginThrottleNormalizeLogin($marcaEspacadora));
        self::assertSame('admin', loginThrottleStripAccentsFallback($acentoSolto));
        self::assertSame('admin', loginThrottleStripAccentsFallback($marcaEspacadora));
    }

    /** O que já estava gravado (logins ASCII) continua caindo no mesmo contador. */
    public function testLoginAsciiTemAMesmaChaveDeAntes(): void
    {
        foreach (['admin', 'Joao.Silva', ' MARIA_01 ', 'a-b@c.com'] as $login) {
            self::assertSame(strtolower(trim($login)), loginThrottleNormalizeLogin($login), $login);
        }
    }

    // --- quem é "a conta": o ID, quando o login existe -----------------------------

    public function testContaQueExisteTemBucketDerivadoDoId(): void
    {
        $bucket = loginThrottleBuckets('admin', '203.0.113.9', $this->conta(7))[2][1];

        self::assertSame(hash('sha256', 'account_id|7'), $bucket);
    }

    /**
     * O defeito: a chave do contador da conta era o TEXTO normalizado. Login,
     * e-mail e grafias que só a colação do banco iguala viravam contadores
     * diferentes da MESMA conta, e o teto de 15 se multiplicava.
     */
    public function testQualquerGrafiaDaMesmaContaCaiNoMesmoContadorDeConta(): void
    {
        $conta = $this->conta(7);
        $referencia = loginThrottleBuckets('admin', '203.0.113.9', $conta)[2][1];

        foreach (['ADMIN', 'admin@ecoleta.com.br', 'ａｄｍｉｎ', 'admín', "adm\u{200B}in", 'qualquer coisa que o banco ache igual'] as $entrada) {
            self::assertSame(
                $referencia,
                loginThrottleBuckets($entrada, '198.51.100.4', $conta)[2][1],
                "'{$entrada}' deveria cair no contador da conta 7"
            );
        }
    }

    public function testContasComIdsDiferentesTemContadoresDiferentes(): void
    {
        self::assertNotSame(
            loginThrottleBuckets('admin', '203.0.113.9', $this->conta(7))[2][1],
            loginThrottleBuckets('admin', '203.0.113.9', $this->conta(8))[2][1]
        );
    }

    /** Login que não existe continua com a chave normalizada, como antes. */
    public function testLoginInexistenteMantemAChaveNormalizada(): void
    {
        self::assertSame(
            loginThrottleBucket(LOGIN_THROTTLE_SCOPE_ACCOUNT, 'fantasma'),
            loginThrottleBuckets('FANTASMA', '203.0.113.9', null)[2][1]
        );
        self::assertSame(
            loginThrottleBuckets('fantasma', '203.0.113.9')[2][1],
            loginThrottleBuckets('ｆａｎｔａｓｍａ', '198.51.100.4')[2][1]
        );
    }

    /**
     * Quem digita "5" (ou "id|5", ou "account_id|5") como login inexistente não
     * pode cair no contador da conta de ID 5 e inflá-lo.
     */
    public function testLoginInexistenteNaoCaiNoContadorDeUmaContaReal(): void
    {
        $contaReal = loginThrottleBuckets('admin', '203.0.113.9', $this->conta(5))[2][1];

        foreach (['5', 'id|5', 'id:5', 'account_id|5', 'account|5'] as $inventado) {
            self::assertNotSame($contaReal, loginThrottleBuckets($inventado, '203.0.113.9', null)[2][1], $inventado);
        }
    }

    /**
     * O par fica na chave de TEXTO, de propósito. Com o ID ali, uma grafia que o
     * normalizador não cobre dividiria o contador do par para conta que existe e
     * não dividiria para a que não existe: um IP sozinho descobriria o login com
     * poucas tentativas.
     */
    public function testContadorDoParNaoMudaComAConta(): void
    {
        self::assertSame(
            loginThrottleBuckets('admin', '203.0.113.9', null)[0][1],
            loginThrottleBuckets('admin', '203.0.113.9', $this->conta(7))[0][1]
        );
    }

    // --- IP de confiança ------------------------------------------------------

    public function testMarcadorDeConfiancaEDaContaDoIpEDaSenha(): void
    {
        $referencia = loginThrottleTrustBucket($this->conta(7, 'hash-a'), '203.0.113.9');

        self::assertSame($referencia, loginThrottleTrustBucket($this->conta(7, 'hash-a'), '203.0.113.9'));
        self::assertNotSame($referencia, loginThrottleTrustBucket($this->conta(8, 'hash-a'), '203.0.113.9'), 'outra conta');
        self::assertNotSame($referencia, loginThrottleTrustBucket($this->conta(7, 'hash-a'), '198.51.100.4'), 'outro IP');
        self::assertNotSame(
            $referencia,
            loginThrottleTrustBucket($this->conta(7, 'hash-b'), '203.0.113.9'),
            'senha trocada ou resetada: a confiança antiga não vale mais'
        );
    }

    /**
     * 'unknown' é o que apiThrottleIp() devolve sem REMOTE_ADDR, e é o mesmo valor
     * para todo mundo nessa situação. Nem se grava confiança nele nem se aceita.
     */
    public function testIpDesconhecidoNuncaGanhaNemUsaConfianca(): void
    {
        $conta = $this->conta();
        $db = new FakeThrottlePdo();

        loginThrottleRegisterSuccess($db, 'admin', 'unknown', $conta);

        self::assertSame([], $db->executedMatching('INSERT INTO login_throttle'), 'sem marcador para IP desconhecido');
        self::assertCount(2, $db->executed, 'o par e a conta ainda são zerados');

        $db->trusted[loginThrottleTrustBucket($conta, 'unknown')] = true;
        self::assertFalse(loginThrottleIsTrusted($db, $conta, 'unknown'));
        self::assertFalse(loginThrottleIsTrusted($db, $conta, ''));
        self::assertTrue(loginThrottleIpIsIdentifiable('203.0.113.9'));
        self::assertTrue(loginThrottleIpIsIdentifiable('2001:db8::1'));
    }

    public function testMarcadorDeConfiancaNaoColideComNenhumContador(): void
    {
        $conta = $this->conta(7);
        $contadores = array_column(loginThrottleBuckets('admin', '203.0.113.9', $conta), 1);

        self::assertNotContains(loginThrottleTrustBucket($conta, '203.0.113.9'), $contadores);
    }

    public function testBucketDeConfiancaNaoVazaOHashDaSenha(): void
    {
        $hash = '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012345';

        self::assertSame(64, strlen(loginThrottleTrustBucket($this->conta(7, $hash), '203.0.113.9')));
        self::assertStringNotContainsString('abcdefghijkl', loginThrottleTrustBucket($this->conta(7, $hash), '203.0.113.9'));
    }

    // --- teto menor para o bloqueio da conta -----------------------------------

    public function testBloqueioDaContaEscalaAteUmTetoMenorQueODosOutros(): void
    {
        $esperado = [14 => 0, 15 => 60, 16 => 120, 17 => 240, 18 => 300, 19 => 300, 1000 => 300];

        foreach ($esperado as $falhas => $segundos) {
            self::assertSame(
                $segundos,
                loginThrottleDecide([LOGIN_THROTTLE_SCOPE_ACCOUNT => $falhas])[LOGIN_THROTTLE_SCOPE_ACCOUNT],
                "falhas={$falhas}"
            );
        }
    }

    public function testParEIpSeguemNoTetoDeQuinzeMinutos(): void
    {
        $decisao = loginThrottleDecide([
            LOGIN_THROTTLE_SCOPE_PAIR => LOGIN_THROTTLE_MAX_FAILURES + 20,
            LOGIN_THROTTLE_SCOPE_IP => LOGIN_THROTTLE_IP_MAX_FAILURES + 20,
        ]);

        self::assertSame(LOGIN_THROTTLE_MAX_BLOCK, $decisao[LOGIN_THROTTLE_SCOPE_PAIR]);
        self::assertSame(LOGIN_THROTTLE_MAX_BLOCK, $decisao[LOGIN_THROTTLE_SCOPE_IP]);
    }

    public function testTetoDaContaFicaEntreOBaseEOTetoGeral(): void
    {
        self::assertGreaterThan(LOGIN_THROTTLE_BASE_BLOCK, LOGIN_THROTTLE_ACCOUNT_MAX_BLOCK);
        self::assertLessThan(LOGIN_THROTTLE_MAX_BLOCK, LOGIN_THROTTLE_ACCOUNT_MAX_BLOCK);
        self::assertLessThan(LOGIN_THROTTLE_WINDOW, LOGIN_THROTTLE_ACCOUNT_MAX_BLOCK);
    }

    public function testBlocoAceitaTetoExplicito(): void
    {
        self::assertSame(120, loginThrottleBlockSeconds(20, 5, 120));
        self::assertSame(0, loginThrottleBlockSeconds(4, 5, 120));
        // Sem o terceiro argumento vale o teto geral, como sempre valeu.
        self::assertSame(LOGIN_THROTTLE_MAX_BLOCK, loginThrottleBlockSeconds(20, 5));
    }

    /**
     * O robô lento falha uma vez a cada 29 minutos para manter o contador vivo
     * dentro da janela. A fração do tempo que ele consegue manter a conta trancada
     * é bloqueio / intervalo: era 900/1740 (52%).
     */
    public function testRoboLentoTravaMenosDeUmQuintoDoTempo(): void
    {
        $intervalo = LOGIN_THROTTLE_WINDOW - 60;

        self::assertLessThan(0.2, LOGIN_THROTTLE_ACCOUNT_MAX_BLOCK / $intervalo);
    }

    public function testConfiancaDuraTrintaDiasEOMarcadorSobreviveAoDescarteDasFalhas(): void
    {
        self::assertSame(30 * 86400, LOGIN_THROTTLE_TRUST_TTL);
        self::assertGreaterThan(LOGIN_THROTTLE_RETENTION, LOGIN_THROTTLE_TRUST_TTL);
    }

    // --- bloqueio efetivo: o que a confiança dispensa e o que não -----------------

    public function testIpDeConfiancaIgnoraSoOContadorDaConta(): void
    {
        $tudo = [LOGIN_THROTTLE_SCOPE_PAIR => 120, LOGIN_THROTTLE_SCOPE_IP => 60, LOGIN_THROTTLE_SCOPE_ACCOUNT => 240];

        self::assertSame(240, loginThrottleEffectiveBlock($tudo, false));
        self::assertSame(120, loginThrottleEffectiveBlock($tudo, true), 'par e IP continuam valendo');
        self::assertSame(0, loginThrottleEffectiveBlock([LOGIN_THROTTLE_SCOPE_ACCOUNT => 240], true));
        self::assertSame(240, loginThrottleEffectiveBlock([LOGIN_THROTTLE_SCOPE_ACCOUNT => 240], false));
        self::assertSame(0, loginThrottleEffectiveBlock([], true));
        self::assertSame(60, loginThrottleEffectiveBlock([LOGIN_THROTTLE_SCOPE_IP => 60, LOGIN_THROTTLE_SCOPE_ACCOUNT => 0], true));
    }

    // --- orquestração da leitura (antes da senha) ----------------------------------

    private function bloqueio(string $scope, int $restante): array
    {
        return ['scope' => $scope, 'retry_after' => $restante];
    }

    public function testLeituraNaoConsultaConfiancaQuandoAContaNaoBloqueia(): void
    {
        $db = new FakeThrottlePdo();
        $conta = $this->conta();
        $buckets = loginThrottleBuckets('admin', '10.0.7.1', $conta);
        $db->blocked[$buckets[0][1]] = $this->bloqueio(LOGIN_THROTTLE_SCOPE_PAIR, 90);

        self::assertSame(90, loginThrottleRetryAfter($db, 'admin', '10.0.7.1', $conta));
        self::assertSame([], $db->executedMatching('SELECT 1 FROM login_throttle'), 'a consulta de confiança só vale quando a conta bloqueia');
    }

    public function testContaBloqueadaParaIpSemConfiancaDevolveOBloqueio(): void
    {
        $db = new FakeThrottlePdo();
        $conta = $this->conta();
        $db->blocked[loginThrottleBuckets('admin', '10.0.7.2', $conta)[2][1]] = $this->bloqueio(LOGIN_THROTTLE_SCOPE_ACCOUNT, 240);

        self::assertSame(240, loginThrottleRetryAfter($db, 'admin', '10.0.7.2', $conta));

        $consultas = $db->executedMatching('SELECT 1 FROM login_throttle');
        self::assertCount(1, $consultas);
        self::assertSame([loginThrottleTrustBucket($conta, '10.0.7.2'), LOGIN_THROTTLE_SCOPE_TRUSTED], $consultas[0][1]);
    }

    public function testContaBloqueadaNaoBarraIpDeConfianca(): void
    {
        $db = new FakeThrottlePdo();
        $conta = $this->conta();
        $db->blocked[loginThrottleBuckets('admin', '10.0.7.3', $conta)[2][1]] = $this->bloqueio(LOGIN_THROTTLE_SCOPE_ACCOUNT, 240);
        $db->trusted[loginThrottleTrustBucket($conta, '10.0.7.3')] = true;

        self::assertSame(0, loginThrottleRetryAfter($db, 'admin', '10.0.7.3', $conta));
    }

    /** A confiança é de (conta, IP): outro IP da mesma conta não herda. */
    public function testConfiancaDeUmIpNaoValeParaOutroIp(): void
    {
        $db = new FakeThrottlePdo();
        $conta = $this->conta();
        $db->blocked[loginThrottleBuckets('admin', '10.0.7.4', $conta)[2][1]] = $this->bloqueio(LOGIN_THROTTLE_SCOPE_ACCOUNT, 240);
        $db->trusted[loginThrottleTrustBucket($conta, '10.0.7.99')] = true;

        self::assertSame(240, loginThrottleRetryAfter($db, 'admin', '10.0.7.4', $conta));
    }

    public function testIpDeConfiancaNaoEscapaDoBloqueioDoParNemDoIp(): void
    {
        $conta = $this->conta();

        $db = new FakeThrottlePdo();
        $b = loginThrottleBuckets('admin', '10.0.7.5', $conta);
        $db->blocked[$b[0][1]] = $this->bloqueio(LOGIN_THROTTLE_SCOPE_PAIR, 120);
        $db->blocked[$b[2][1]] = $this->bloqueio(LOGIN_THROTTLE_SCOPE_ACCOUNT, 240);
        $db->trusted[loginThrottleTrustBucket($conta, '10.0.7.5')] = true;
        self::assertSame(120, loginThrottleRetryAfter($db, 'admin', '10.0.7.5', $conta), 'o par vale');

        $db = new FakeThrottlePdo();
        $db->blocked[$b[1][1]] = $this->bloqueio(LOGIN_THROTTLE_SCOPE_IP, 60);
        $db->blocked[$b[2][1]] = $this->bloqueio(LOGIN_THROTTLE_SCOPE_ACCOUNT, 240);
        $db->trusted[loginThrottleTrustBucket($conta, '10.0.7.5')] = true;
        self::assertSame(60, loginThrottleRetryAfter($db, 'admin', '10.0.7.5', $conta), 'o IP vale');
    }

    /** Login que não existe não tem marcador: nunca é de confiança, e nem consulta. */
    public function testLoginInexistenteNuncaEDeConfianca(): void
    {
        $db = new FakeThrottlePdo();
        $db->blocked[loginThrottleBuckets('fantasma', '10.0.7.6', null)[2][1]] = $this->bloqueio(LOGIN_THROTTLE_SCOPE_ACCOUNT, 240);

        self::assertSame(240, loginThrottleRetryAfter($db, 'fantasma', '10.0.7.6', null));
        self::assertSame([], $db->executedMatching('SELECT 1 FROM login_throttle'));
    }

    /**
     * Falha FECHADA na confiança: se a consulta quebra, o IP não é de confiança e o
     * teto da conta vale. Perder a exceção custa um incômodo ao dono; ganhá-la por
     * engano abriria o teto.
     */
    public function testConsultaDeConfiancaQueQuebraNaoDaConfianca(): void
    {
        $db = new FakeThrottlePdo();
        $conta = $this->conta();
        $db->blocked[loginThrottleBuckets('admin', '10.0.7.7', $conta)[2][1]] = $this->bloqueio(LOGIN_THROTTLE_SCOPE_ACCOUNT, 240);
        $db->trusted[loginThrottleTrustBucket($conta, '10.0.7.7')] = true;
        $db->trustQueryFails = true;

        self::assertFalse(loginThrottleIsTrusted($db, $conta, '10.0.7.7'));
        self::assertSame(240, loginThrottleRetryAfter($db, 'admin', '10.0.7.7', $conta));
        self::assertStringContainsString('não consegui consultar a confiança', (string) file_get_contents($this->errorLogFile));
    }

    // --- orquestração da escrita (resposta a uma falha) ------------------------------

    /**
     * O dono num IP de confiança que erra a senha durante um ataque recebe 401
     * como qualquer erro: o bloqueio da conta é GRAVADO (vale para os outros IPs),
     * mas o 429 por causa dele não é para este IP.
     */
    public function testFalhaEmIpDeConfiancaNaoRecebe429PelaConta(): void
    {
        $db = new FakeThrottlePdo();
        $conta = $this->conta();
        $b = loginThrottleBuckets('admin', '10.0.8.1', $conta);
        $db->failures[$b[2][1]] = LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES - 1;
        $db->trusted[loginThrottleTrustBucket($conta, '10.0.8.1')] = true;

        $bloqueio = loginThrottleRegisterFailure($db, 'admin', '10.0.8.1', $conta);

        self::assertSame(0, $bloqueio);
        self::assertSame([$b[2][1] => LOGIN_THROTTLE_BASE_BLOCK], $this->bloqueiosAplicados($db), 'a conta bloqueia os outros IPs');
    }

    public function testFalhaEmIpSemConfiancaRecebeOBloqueioDaConta(): void
    {
        $db = new FakeThrottlePdo();
        $conta = $this->conta();
        $db->failures[loginThrottleBuckets('admin', '10.0.8.2', $conta)[2][1]] = LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES - 1;

        self::assertSame(LOGIN_THROTTLE_BASE_BLOCK, loginThrottleRegisterFailure($db, 'admin', '10.0.8.2', $conta));
    }

    public function testFalhaEmIpDeConfiancaAindaEstouraOPar(): void
    {
        $db = new FakeThrottlePdo();
        $conta = $this->conta();
        $b = loginThrottleBuckets('admin', '10.0.8.3', $conta);
        $db->failures[$b[0][1]] = LOGIN_THROTTLE_MAX_FAILURES - 1;
        $db->failures[$b[2][1]] = LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES - 1;
        $db->trusted[loginThrottleTrustBucket($conta, '10.0.8.3')] = true;

        self::assertSame(LOGIN_THROTTLE_BASE_BLOCK, loginThrottleRegisterFailure($db, 'admin', '10.0.8.3', $conta));
    }

    public function testConsultaDeConfiancaSoAcontecEmFalhaQuandoAContaEstoura(): void
    {
        $db = new FakeThrottlePdo();

        loginThrottleRegisterFailure($db, 'admin', '10.0.8.4', $this->conta());

        self::assertSame([], $db->executedMatching('SELECT 1 FROM login_throttle'));
    }

    /**
     * O defeito de T2 em nível de orquestração: grafias diferentes da MESMA conta
     * (login, e-mail, largura total) de IPs diferentes agora somam no MESMO
     * contador e o teto dispara na 15a falha. Antes cada grafia era um contador.
     */
    public function testGrafiasDiferentesDaMesmaContaSomamNoContadorDaConta(): void
    {
        $db = new FakeThrottlePdo();
        $conta = $this->conta(7);
        $grafias = ['admin', 'ADMIN', 'ａｄｍｉｎ', 'admín', 'admin@ecoleta.com.br', "adm\u{200B}in"];
        $contaBucket = loginThrottleBuckets('admin', '10.0.9.1', $conta)[2][1];

        for ($i = 1; $i < LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES; $i++) {
            self::assertSame(0, loginThrottleRegisterFailure($db, $grafias[$i % count($grafias)], '10.0.9.' . $i, $conta), "falha {$i}");
        }
        $bloqueio = loginThrottleRegisterFailure($db, 'admin', '10.0.9.99', $conta);

        self::assertSame(LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES, $db->failures[$contaBucket]);
        self::assertSame(LOGIN_THROTTLE_BASE_BLOCK, $bloqueio);
        self::assertArrayHasKey($contaBucket, $this->bloqueiosAplicados($db));
    }

    /** Para login que não existe a chave normalizada segue somando as grafias que ela cobre. */
    public function testGrafiasCobertasDeLoginInexistenteSomamNoMesmoContador(): void
    {
        $db = new FakeThrottlePdo();
        $contaBucket = loginThrottleBuckets('fantasma', '10.0.9.1', null)[2][1];

        foreach (['fantasma', 'FANTASMA', 'ｆａｎｔａｓｍａ', 'fantásma', "fanta\u{200B}sma"] as $i => $grafia) {
            loginThrottleRegisterFailure($db, $grafia, '10.0.10.' . $i, null);
        }

        self::assertSame(5, $db->failures[$contaBucket]);
    }

    // --- limpeza -----------------------------------------------------------------------

    /**
     * O descarte de 24 h das falhas não pode apagar a confiança: o remédio contra o
     * trancamento só funcionaria no dia do login. Marcador tem retenção própria.
     */
    public function testLimpezaDasFalhasNaoApagaMarcadoresEOsMarcadoresTemRetencaoPropria(): void
    {
        $db = new FakeThrottlePdo();

        loginThrottleCleanupNow($db);

        self::assertCount(2, $db->execs);
        [$falhas, $marcadores] = $db->execs;

        self::assertStringContainsString("scope <> 'trusted'", $falhas);
        self::assertStringContainsString('INTERVAL ' . LOGIN_THROTTLE_RETENTION . ' SECOND', $falhas);
        self::assertStringContainsString('blocked_until IS NULL OR blocked_until < NOW()', $falhas, 'bloqueio ativo não é apagado');

        self::assertStringContainsString("scope = 'trusted'", $marcadores);
        self::assertStringContainsString('INTERVAL ' . LOGIN_THROTTLE_TRUST_TTL . ' SECOND', $marcadores);
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
            loginThrottleRegisterSuccess($db->pdo(), 'admin', '203.0.113.9', $this->conta());
            loginThrottleCleanupNow($db->pdo());

            $registrado = (string) file_get_contents($errorLog);
            self::assertStringContainsString('Rate limit de login indisponível na leitura', $registrado);
            self::assertStringContainsString('Rate limit de login indisponível na escrita', $registrado);
            self::assertStringContainsString('Falha ao registrar sucesso no rate limit de login', $registrado);
            self::assertStringContainsString('Falha ao limpar tabela de rate limit', $registrado);
        } finally {
            ini_set('error_log', $previous);
            unlink($errorLog);
            $db->destroy();
        }
    }
}
