<?php
declare(strict_types=1);

/**
 * Rate limit de login persistido em MySQL (a hospedagem é compartilhada e
 * o PHP não mantém estado entre requisições).
 *
 * São três contadores, sempre avaliados juntos:
 *
 *  - par (login, IP) — poucas falhas toleradas. Pega a força bruta contra uma
 *    conta específica vinda de um endereço.
 *  - IP sozinho — teto mais alto, conta falhas de qualquer login. Pega o
 *    password spraying, que troca de login a cada tentativa e por isso nunca
 *    chegaria perto do limite do primeiro contador.
 *  - conta sozinha: teto que NÃO sabe de IP. Pega o inverso do spraying: muitos
 *    endereços contra o mesmo login, em que cada par fica longe do limite e o
 *    contador do par sozinho deixava testar "admin" sem teto nenhum.
 *
 * Passado o limite de um contador dentro da janela, cada nova falha bloqueia
 * por um tempo que dobra a cada tentativa, até o teto.
 *
 * O que o teto por conta custa: quem sabe um login e dispara falhas de vários
 * IPs consegue travar o login daquela conta (para todo mundo, o dono incluso)
 * por até LOGIN_THROTTLE_MAX_BLOCK segundos por rodada. É o preço de limitar o
 * ataque distribuído, e por isso o limite da conta é folgado: quem erra sozinho
 * esbarra no limite do par muito antes. Para liberar à mão uma conta travada
 * assim, apague a linha dela: `DELETE FROM login_throttle WHERE scope = 'account'`.
 *
 * JANELA x BLOQUEIO MÁXIMO. As falhas somem do contador quando passa
 * LOGIN_THROTTLE_WINDOW sem nova falha. Com a janela igual ao bloqueio máximo
 * (os dois eram 900s) o contador zerava no instante em que o bloqueio mais longo
 * terminava: a primeira falha depois dele voltava a contar 1 e a escada de
 * bloqueio recomeçava do zero. Quem esperava o bloqueio acabar ganhava cinco
 * tentativas novas por ciclo, e o ritmo de um atacante paciente ficava perto de
 * 18 por hora por par, em vez das 4 por hora do bloqueio máximo contínuo. A
 * janela agora é o dobro do bloqueio máximo: a falha que vem logo depois do fim
 * do bloqueio ainda cai dentro dela, o contador continua alto e o bloqueio
 * continua no teto. O custo é pequeno: quem errou cinco vezes e espera menos de
 * 30 minutos (antes, 15) ainda carrega as falhas.
 *
 * O cálculo de TEMPO é feito pelo MySQL (NOW(), TIMESTAMPDIFF): assim o bloqueio
 * não depende de PHP e banco estarem no mesmo fuso. A DECISÃO (quantos
 * segundos bloquear dado o número de falhas) é função pura aqui em cima
 * (loginThrottleBlockSeconds, loginThrottleDecide) e é a parte coberta pela
 * suíte, que roda em SQLite e não executa o SQL de MySQL abaixo.
 */

require_once __DIR__ . '/security.php';

const LOGIN_THROTTLE_SCOPE_PAIR = 'login_ip';
const LOGIN_THROTTLE_SCOPE_IP = 'ip';
const LOGIN_THROTTLE_SCOPE_ACCOUNT = 'account';
// Falhas toleradas antes do primeiro bloqueio, por contador.
const LOGIN_THROTTLE_MAX_FAILURES = 5;
// Teto mais folgado no contador por IP: um escritório inteiro pode sair pelo
// mesmo endereço, e bloquear cedo demais derrubaria gente legítima junto.
const LOGIN_THROTTLE_IP_MAX_FAILURES = 20;
// Teto por conta, qualquer que seja o IP. Acima do limite do par (quem erra
// sozinho bate no par primeiro e o contador da conta só acompanha) e abaixo do
// que um ataque distribuído levaria horas para gastar. Ver o custo no topo.
const LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES = 15;
// Falhas espaçadas por mais que isto reiniciam o contador (30 min). Precisa ser
// MAIOR que LOGIN_THROTTLE_MAX_BLOCK, e com folga: ver "janela x bloqueio
// máximo" no topo. A suíte confere a relação.
const LOGIN_THROTTLE_WINDOW = 1800;
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
 * Tira os acentos de um texto JÁ em caixa baixa, sem depender da extensão intl
 * (que a hospedagem compartilhada nem sempre tem). Cobre as letras latinas com
 * diacrítico do português e das línguas vizinhas; o que não está na tabela passa
 * como veio.
 */
function loginThrottleStripAccentsFallback(string $value): string
{
    static $map = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'ď' => 'd',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ę' => 'e', 'ě' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'ı' => 'i',
        'ł' => 'l', 'ľ' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o',
        'ř' => 'r',
        'ś' => 's', 'š' => 's', 'ş' => 's',
        'ť' => 't',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    return strtr($value, $map);
}

/**
 * Chave estável de um login para os contadores: aparada, em caixa baixa e sem
 * acento.
 *
 * O MySQL da hospedagem compara com uma colação que ignora caixa E acento, então
 * 'admin', 'ADMIN' e 'admín' encontram a MESMA conta. Se a chave só baixasse a
 * caixa (como era), cada variação ganhava um contador novo e o limite de
 * tentativas se multiplicava por quantas variações o atacante inventasse. Para
 * logins ASCII o resultado é idêntico ao antigo `strtolower(trim())`, então os
 * contadores já gravados continuam valendo.
 */
function loginThrottleNormalizeLogin(string $login): string
{
    $value = trim($login);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);

    if (class_exists('Normalizer')) {
        $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);
        if (is_string($decomposed)) {
            // Marcas de acento soltas (categoria Mn) depois da decomposição.
            $stripped = preg_replace('/\p{Mn}+/u', '', $decomposed);
            if (is_string($stripped)) {
                return $stripped;
            }
        }
    }

    return loginThrottleStripAccentsFallback($value);
}

/**
 * Os contadores que uma tentativa deste par alimenta, como
 * [scope, bucket, limite de falhas]. O do par vem primeiro (loginThrottleClear
 * depende disso).
 */
function loginThrottleBuckets(string $login, string $ip): array
{
    $normalized = loginThrottleNormalizeLogin($login);

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
        [
            LOGIN_THROTTLE_SCOPE_ACCOUNT,
            loginThrottleBucket(LOGIN_THROTTLE_SCOPE_ACCOUNT, $normalized),
            LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES,
        ],
    ];
}

/**
 * Segundos de bloqueio para um contador com `$failures` falhas (a atual
 * inclusive) e limite `$maxFailures`. Zero abaixo do limite; no limite, o tempo
 * base; daí em diante dobra a cada falha, até LOGIN_THROTTLE_MAX_BLOCK.
 *
 * Função pura: é a decisão, separada do SQL, e é o que a suíte exercita.
 */
function loginThrottleBlockSeconds(int $failures, int $maxFailures): int
{
    if ($failures < $maxFailures) {
        return 0;
    }

    // `min(..., 20)` evita estourar a potência com contador gigante.
    $steps = min($failures - $maxFailures, 20);

    return (int) min(LOGIN_THROTTLE_MAX_BLOCK, LOGIN_THROTTLE_BASE_BLOCK * (2 ** $steps));
}

/**
 * O bloqueio de cada contador, dado quantas falhas cada um acumulou.
 *
 * @param array<string,int> $failuresByScope escopo => falhas (a atual inclusive)
 * @return array<string,int> escopo => segundos de bloqueio (0 = nenhum); escopo
 *         que não é um dos três conhecidos é ignorado
 */
function loginThrottleDecide(array $failuresByScope): array
{
    $limits = [
        LOGIN_THROTTLE_SCOPE_PAIR => LOGIN_THROTTLE_MAX_FAILURES,
        LOGIN_THROTTLE_SCOPE_IP => LOGIN_THROTTLE_IP_MAX_FAILURES,
        LOGIN_THROTTLE_SCOPE_ACCOUNT => LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES,
    ];

    $blocks = [];
    foreach ($failuresByScope as $scope => $failures) {
        if (!isset($limits[$scope])) {
            continue;
        }

        $blocks[$scope] = loginThrottleBlockSeconds((int) $failures, $limits[$scope]);
    }

    return $blocks;
}

/** O maior bloqueio entre os contadores, que é o que a resposta ao cliente anuncia. */
function loginThrottleLongestBlock(array $blocksByScope): int
{
    return $blocksByScope === [] ? 0 : max(0, ...array_map('intval', array_values($blocksByScope)));
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
 * Soma uma falha em um contador e devolve quantas ele acumulou na janela.
 *
 * O INSERT ... ON DUPLICATE KEY UPDATE é atômico: duas tentativas simultâneas
 * não se atropelam (ler, somar em PHP e gravar deixaria as duas verem o mesmo
 * número e contar uma só). Por isso este é o único trecho que continua em SQL.
 */
function loginThrottleBumpBucket(PDO $db, string $scope, string $bucket): int
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

    return (int) ($row['failures'] ?? 0);
}

/**
 * Registra uma tentativa falha em todos os contadores e devolve o maior
 * bloqueio aplicado (0 quando nenhum estourou).
 */
function loginThrottleRegisterFailure(PDO $db, string $login, string $ip): int
{
    try {
        $buckets = loginThrottleBuckets($login, $ip);

        $failures = [];
        $bucketOf = [];
        foreach ($buckets as [$scope, $bucket]) {
            $failures[$scope] = loginThrottleBumpBucket($db, $scope, $bucket);
            $bucketOf[$scope] = $bucket;
        }

        $blocks = loginThrottleDecide($failures);

        foreach ($blocks as $scope => $seconds) {
            if ($seconds <= 0) {
                continue;
            }

            $block = $db->prepare(sprintf(
                "UPDATE login_throttle SET blocked_until = (NOW() + INTERVAL %d SECOND) WHERE bucket = ?",
                $seconds
            ));
            $block->execute([$bucketOf[$scope]]);

            error_log(sprintf(
                'Login bloqueado por %ds após %d falhas (escopo %s, bucket %s, ip %s)',
                $seconds,
                $failures[$scope],
                $scope,
                substr($bucketOf[$scope], 0, 12),
                $ip
            ));
        }

        return loginThrottleLongestBlock($blocks);
    } catch (\Throwable $e) {
        error_log('Rate limit de login indisponível na escrita: ' . $e->getMessage());

        return 0;
    }
}

/**
 * Zera o contador do par (login, IP) — chamar após autenticação bem-sucedida.
 *
 * Os contadores por IP e por CONTA ficam de pé de propósito: quem acabou de
 * varrer dezenas de logins não deve zerar a conta só porque acertou um deles no
 * fim, e quem testou uma conta de vários IPs não zera o que gastou só porque um
 * dos palpites bateu.
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
