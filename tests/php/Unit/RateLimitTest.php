<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/rate_limit.php';

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
    public function testBucketEhOSha256DoEscopoComAChave(): void
    {
        self::assertSame(
            hash('sha256', 'ip|203.0.113.9'),
            loginThrottleBucket(LOGIN_THROTTLE_SCOPE_IP, '203.0.113.9')
        );
    }

    /**
     * Toda tentativa alimenta dois contadores: o do par (login, IP), que pega a
     * força bruta contra uma conta, e o do IP sozinho, que pega o password
     * spraying — trocar de login a cada tentativa nunca encheria o primeiro.
     */
    public function testTentativaAlimentaOsDoisContadores(): void
    {
        $buckets = loginThrottleBuckets('admin', '203.0.113.9');

        self::assertCount(2, $buckets);
        self::assertSame(LOGIN_THROTTLE_SCOPE_PAIR, $buckets[0][0]);
        self::assertSame(LOGIN_THROTTLE_SCOPE_IP, $buckets[1][0]);
        self::assertSame(LOGIN_THROTTLE_MAX_FAILURES, $buckets[0][2]);
        self::assertSame(LOGIN_THROTTLE_IP_MAX_FAILURES, $buckets[1][2]);
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
