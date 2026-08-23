<?php
declare(strict_types=1);

/**
 * Rate limit de login persistido em MySQL (a hospedagem é compartilhada e
 * o PHP não mantém estado entre requisições).
 *
 * Contador por par (login, IP). Depois de LOGIN_THROTTLE_MAX_FAILURES falhas
 * dentro da janela, cada nova falha bloqueia o par por um tempo que dobra a
 * cada tentativa, até o teto.
 *
 * Todo o cálculo de tempo é feito pelo MySQL (NOW(), TIMESTAMPDIFF): assim o
 * bloqueio não depende de PHP e banco estarem no mesmo fuso.
 */

require_once __DIR__ . '/security.php';

const LOGIN_THROTTLE_SCOPE = 'login_ip';
// Falhas toleradas antes do primeiro bloqueio.
const LOGIN_THROTTLE_MAX_FAILURES = 5;
// Falhas espaçadas por mais que isto reiniciam o contador (15 min).
const LOGIN_THROTTLE_WINDOW = 900;
// Duração do primeiro bloqueio, dobrando a cada falha seguinte.
const LOGIN_THROTTLE_BASE_BLOCK = 60;
// Teto do bloqueio progressivo (15 min).
const LOGIN_THROTTLE_MAX_BLOCK = 900;
// Linhas de throttle mais antigas que isto são descartadas (24 h).
const LOGIN_THROTTLE_RETENTION = 86400;

function loginThrottleBucket(string $login, string $ip): string
{
    return hash('sha256', LOGIN_THROTTLE_SCOPE . '|' . strtolower(trim($login)) . '|' . $ip);
}

/**
 * Segundos que faltam para o par (login, IP) sair do bloqueio.
 * Zero significa liberado.
 *
 * Falha aberta de propósito: se a tabela de throttle estiver indisponível o
 * login continua funcionando (com o erro registrado), em vez de derrubar o
 * acesso de todo mundo.
 */
function loginThrottleRetryAfter(PDO $db, string $login, string $ip): int
{
    try {
        $stmt = $db->prepare("
            SELECT GREATEST(TIMESTAMPDIFF(SECOND, NOW(), blocked_until), 1) AS retry_after
            FROM login_throttle
            WHERE bucket = ? AND blocked_until IS NOT NULL AND blocked_until > NOW()
            LIMIT 1
        ");
        $stmt->execute([loginThrottleBucket($login, $ip)]);
        $row = $stmt->fetch();

        return $row ? (int) $row['retry_after'] : 0;
    } catch (\Throwable $e) {
        error_log('Rate limit de login indisponível na leitura: ' . $e->getMessage());

        return 0;
    }
}

/**
 * Registra uma tentativa falha e devolve o tempo de bloqueio aplicado
 * (0 quando ainda está dentro da tolerância).
 */
function loginThrottleRegisterFailure(PDO $db, string $login, string $ip): int
{
    $bucket = loginThrottleBucket($login, $ip);
    $window = (int) LOGIN_THROTTLE_WINDOW;

    try {
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
        $stmt->execute([$bucket, LOGIN_THROTTLE_SCOPE]);

        $read = $db->prepare("SELECT failures FROM login_throttle WHERE bucket = ? LIMIT 1");
        $read->execute([$bucket]);
        $row = $read->fetch();
        $failures = (int) ($row['failures'] ?? 0);

        if ($failures < LOGIN_THROTTLE_MAX_FAILURES) {
            return 0;
        }

        $steps = min($failures - LOGIN_THROTTLE_MAX_FAILURES, 20);
        $seconds = (int) min(LOGIN_THROTTLE_MAX_BLOCK, LOGIN_THROTTLE_BASE_BLOCK * (2 ** $steps));

        $block = $db->prepare(sprintf(
            "UPDATE login_throttle SET blocked_until = (NOW() + INTERVAL %d SECOND) WHERE bucket = ?",
            $seconds
        ));
        $block->execute([$bucket]);

        error_log(sprintf(
            'Login bloqueado por %ds após %d falhas (bucket %s, ip %s)',
            $seconds,
            $failures,
            substr($bucket, 0, 12),
            $ip
        ));

        return $seconds;
    } catch (\Throwable $e) {
        error_log('Rate limit de login indisponível na escrita: ' . $e->getMessage());

        return 0;
    }
}

/** Zera o contador do par (login, IP) — chamar após autenticação bem-sucedida. */
function loginThrottleClear(PDO $db, string $login, string $ip): void
{
    try {
        $stmt = $db->prepare("DELETE FROM login_throttle WHERE bucket = ?");
        $stmt->execute([loginThrottleBucket($login, $ip)]);
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
