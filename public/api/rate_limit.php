<?php
declare(strict_types=1);

/**
 * Rate limit de login persistido em MySQL (a hospedagem é compartilhada e
 * o PHP não mantém estado entre requisições).
 *
 * São dois contadores, sempre avaliados juntos:
 *
 *  - par (login, IP) — poucas falhas toleradas. Pega a força bruta contra uma
 *    conta específica.
 *  - IP sozinho — teto mais alto, conta falhas de qualquer login. Pega o
 *    password spraying, que troca de login a cada tentativa e por isso nunca
 *    chegaria perto do limite do primeiro contador.
 *
 * Passado o limite de um contador dentro da janela, cada nova falha bloqueia
 * por um tempo que dobra a cada tentativa, até o teto.
 *
 * Todo o cálculo de tempo é feito pelo MySQL (NOW(), TIMESTAMPDIFF): assim o
 * bloqueio não depende de PHP e banco estarem no mesmo fuso.
 */

require_once __DIR__ . '/security.php';

const LOGIN_THROTTLE_SCOPE_PAIR = 'login_ip';
const LOGIN_THROTTLE_SCOPE_IP = 'ip';
// Falhas toleradas antes do primeiro bloqueio, por contador.
const LOGIN_THROTTLE_MAX_FAILURES = 5;
// Teto mais folgado no contador por IP: um escritório inteiro pode sair pelo
// mesmo endereço, e bloquear cedo demais derrubaria gente legítima junto.
const LOGIN_THROTTLE_IP_MAX_FAILURES = 20;
// Falhas espaçadas por mais que isto reiniciam o contador (15 min).
const LOGIN_THROTTLE_WINDOW = 900;
// Duração do primeiro bloqueio, dobrando a cada falha seguinte.
const LOGIN_THROTTLE_BASE_BLOCK = 60;
// Teto do bloqueio progressivo (15 min).
const LOGIN_THROTTLE_MAX_BLOCK = 900;
// Linhas de throttle mais antigas que isto são descartadas (24 h).
const LOGIN_THROTTLE_RETENTION = 86400;

function loginThrottleBucket(string $scope, string $key): string
{
    return hash('sha256', $scope . '|' . $key);
}

/**
 * Os contadores que uma tentativa deste par alimenta, como
 * [scope, bucket, limite de falhas].
 */
function loginThrottleBuckets(string $login, string $ip): array
{
    $normalized = strtolower(trim($login));

    return [
        [
            LOGIN_THROTTLE_SCOPE_PAIR,
            loginThrottleBucket(LOGIN_THROTTLE_SCOPE_PAIR, $normalized . '|' . $ip),
            LOGIN_THROTTLE_MAX_FAILURES,
        ],
        [
            LOGIN_THROTTLE_SCOPE_IP,
            loginThrottleBucket(LOGIN_THROTTLE_SCOPE_IP, $ip),
            LOGIN_THROTTLE_IP_MAX_FAILURES,
        ],
    ];
}

/**
 * Segundos que faltam para a tentativa sair do bloqueio — o maior valor entre
 * os contadores. Zero significa liberado.
 *
 * Falha aberta de propósito: se a tabela de throttle estiver indisponível o
 * login continua funcionando (com o erro registrado), em vez de derrubar o
 * acesso de todo mundo.
 */
function loginThrottleRetryAfter(PDO $db, string $login, string $ip): int
{
    try {
        $buckets = array_column(loginThrottleBuckets($login, $ip), 1);
        $placeholders = implode(', ', array_fill(0, count($buckets), '?'));

        $stmt = $db->prepare("
            SELECT MAX(GREATEST(TIMESTAMPDIFF(SECOND, NOW(), blocked_until), 1)) AS retry_after
            FROM login_throttle
            WHERE bucket IN ({$placeholders}) AND blocked_until IS NOT NULL AND blocked_until > NOW()
        ");
        $stmt->execute($buckets);
        $row = $stmt->fetch();

        // Agregado sem GROUP BY sempre devolve uma linha, mas ela vem com NULL
        // quando nenhum bucket está bloqueado.
        return is_array($row) ? (int) ($row['retry_after'] ?? 0) : 0;
    } catch (\Throwable $e) {
        error_log('Rate limit de login indisponível na leitura: ' . $e->getMessage());

        return 0;
    }
}

/**
 * Registra a falha em um contador e devolve o bloqueio aplicado
 * (0 quando ainda está dentro da tolerância).
 */
function loginThrottleRegisterBucketFailure(PDO $db, string $scope, string $bucket, int $maxFailures, string $ip): int
{
    $window = (int) LOGIN_THROTTLE_WINDOW;

    // A ordem das atribuições importa: `failures` e `first_failure_at` ainda
    // enxergam o valor antigo de `last_failure_at`, que só é atualizado no fim.
    $stmt = $db->prepare(sprintf("
        INSERT INTO login_throttle (bucket, scope, failures, first_failure_at, last_failure_at)
        VALUES (?, ?, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            failures = IF(last_failure_at < (NOW() - INTERVAL %d SECOND), 1, failures + 1),
            first_failure_at = IF(last_failure_at < (NOW() - INTERVAL %d SECOND), NOW(), first_failure_at),
            last_failure_at = NOW()
    ", $window, $window));
    $stmt->execute([$bucket, $scope]);

    $read = $db->prepare("SELECT failures FROM login_throttle WHERE bucket = ? LIMIT 1");
    $read->execute([$bucket]);
    $row = $read->fetch();
    $failures = (int) ($row['failures'] ?? 0);

    if ($failures < $maxFailures) {
        return 0;
    }

    $steps = min($failures - $maxFailures, 20);
    $seconds = (int) min(LOGIN_THROTTLE_MAX_BLOCK, LOGIN_THROTTLE_BASE_BLOCK * (2 ** $steps));

    $block = $db->prepare(sprintf(
        "UPDATE login_throttle SET blocked_until = (NOW() + INTERVAL %d SECOND) WHERE bucket = ?",
        $seconds
    ));
    $block->execute([$bucket]);

    error_log(sprintf(
        'Login bloqueado por %ds após %d falhas (escopo %s, bucket %s, ip %s)',
        $seconds,
        $failures,
        $scope,
        substr($bucket, 0, 12),
        $ip
    ));

    return $seconds;
}

/**
 * Registra uma tentativa falha em todos os contadores e devolve o maior
 * bloqueio aplicado (0 quando nenhum estourou).
 */
function loginThrottleRegisterFailure(PDO $db, string $login, string $ip): int
{
    try {
        $blocked = 0;
        foreach (loginThrottleBuckets($login, $ip) as [$scope, $bucket, $maxFailures]) {
            $blocked = max($blocked, loginThrottleRegisterBucketFailure($db, $scope, $bucket, $maxFailures, $ip));
        }

        return $blocked;
    } catch (\Throwable $e) {
        error_log('Rate limit de login indisponível na escrita: ' . $e->getMessage());

        return 0;
    }
}

/**
 * Zera o contador do par (login, IP) — chamar após autenticação bem-sucedida.
 *
 * O contador por IP fica de pé de propósito: quem acabou de varrer dezenas de
 * logins não deve zerar a conta só porque acertou um deles no fim.
 */
function loginThrottleClear(PDO $db, string $login, string $ip): void
{
    try {
        $pairBucket = loginThrottleBuckets($login, $ip)[0][1];
        $stmt = $db->prepare("DELETE FROM login_throttle WHERE bucket = ?");
        $stmt->execute([$pairBucket]);
    } catch (\Throwable $e) {
        error_log('Falha ao limpar rate limit de login: ' . $e->getMessage());
    }
}

/** Limpeza probabilística (1 em 100 requisições) para a tabela não crescer sem fim. */
function loginThrottleCleanup(PDO $db): void
{
    try {
        if (random_int(1, 100) !== 1) {
            return;
        }

        $db->exec(sprintf(
            "DELETE FROM login_throttle
             WHERE last_failure_at < (NOW() - INTERVAL %d SECOND)
               AND (blocked_until IS NULL OR blocked_until < NOW())",
            (int) LOGIN_THROTTLE_RETENTION
        ));
    } catch (\Throwable $e) {
        error_log('Falha ao limpar tabela de rate limit: ' . $e->getMessage());
    }
}
