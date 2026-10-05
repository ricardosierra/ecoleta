<?php
declare(strict_types=1);

/**
 * Rate limit de login persistido em MySQL (a hospedagem é compartilhada e
 * o PHP não mantém estado entre requisições).
 *
 * São três contadores, sempre avaliados juntos:
 *
 *  - par (login, IP): poucas falhas toleradas. Pega a força bruta contra uma
 *    conta específica vinda de um endereço.
 *  - IP sozinho: teto mais alto, conta falhas de qualquer login. Pega o
 *    password spraying, que troca de login a cada tentativa e por isso nunca
 *    chegaria perto do limite do primeiro contador.
 *  - conta sozinha: teto que NÃO sabe de IP. Pega o inverso do spraying: muitos
 *    endereços contra o mesmo login, em que cada par fica longe do limite e o
 *    contador do par sozinho deixava testar "admin" sem teto nenhum.
 *
 * Passado o limite de um contador dentro da janela, cada nova falha bloqueia
 * por um tempo que dobra a cada tentativa, até o teto de cada contador.
 *
 * QUEM É "A CONTA". Quando o login existe, o contador da conta é do ID do
 * usuário (e não do texto digitado): login, e-mail e qualquer grafia que a
 * colação do banco trate como igual (caixa, acento, largura total) caem no mesmo
 * contador. A chave normalizada em texto só vale para login que NÃO existe, e é
 * por isso que a normalização tenta acompanhar a colação: sem isso, "admin" e
 * "ａｄｍｉｎ" seriam contas diferentes para o contador de um login inexistente
 * e a mesma conta para o de um login que existe, e contar rodadas de variantes
 * revelaria quais logins existem. O contador do par continua na chave normalizada
 * de propósito: com o ID ali, um IP sozinho descobriria a conta com poucas
 * tentativas (o par estoura só quando o login existe).
 *
 * RESÍDUO CONHECIDO. A normalização aproxima a colação, não a iguala. Medido em
 * MariaDB 10.11 com utf8mb4_unicode_ci, varrendo o BMP: com intl ela cobre tudo o
 * que é plausível (caixa, acento, largura total, caracteres invisíveis, ligaduras,
 * ß, œ) e 76% das equivalências com ASCII ou ignoráveis da colação; sem intl, 39%
 * (a tabela de acentos só conhece o português e vizinhos). O que falta é exótico
 * (dígitos de outros alfabetos, letras modificadoras como U+1D2C). Com uma grafia
 * dessas, 15 falhas de um único IP (o teto do IP é 20) mostram um 429 só quando o
 * login existe. Fica anotado em vez de fechado: custa um IP por candidato a cada
 * janela, e fechar de vez exigiria a colação do banco no PHP.
 *
 * O que o teto por conta custa, e como o custo é limitado. Quem sabe um login e
 * dispara falhas de vários IPs consegue travar a conta para quem entra de um IP
 * desconhecido. Três coisas limitam o estrago:
 *
 *  1. IP DE CONFIANÇA. O bloqueio do escopo conta não vale para um IP que
 *     autenticou aquela conta com sucesso nos últimos LOGIN_THROTTLE_TRUST_TTL
 *     segundos (30 dias, renovados a cada login). Sem isso o login de "admin"
 *     (público no repositório) era trancável sem limite de tempo contra o próprio
 *     dono. O marcador é uma linha desta mesma tabela, escopo 'trusted', sem
 *     migration: em linhas 'trusted' a coluna last_failure_at guarda o último
 *     SUCESSO (a coluna é reaproveitada porque o schema não muda). O bucket do
 *     marcador inclui um hash do password_hash vigente: trocar ou resetar a senha
 *     invalida a confiança de todos os IPs (quem tinha entrado com a senha
 *     comprometida não herda o desvio). A confiança só dispensa o contador da
 *     CONTA; o par e o IP continuam valendo para aquele endereço, então um IP de
 *     confiança que chuta senha esbarra nos mesmos limites de qualquer outro.
 *     Marcadores têm retenção própria (30 dias): o descarte de 24 h das linhas de
 *     falha não os apaga. A confiança é por apiThrottleIp(), que é REMOTE_ADDR: atrás
 *     de proxy ou CDN esse endereço é o do proxy e a confiança passa a valer para
 *     todos que saem por ele (o par e o IP continuam valendo). Sem endereço
 *     ('unknown') não há confiança.
 *  2. TETO MENOR PARA O BLOQUEIO DA CONTA (LOGIN_THROTTLE_ACCOUNT_MAX_BLOCK, 5 min
 *     em vez dos 15 dos outros contadores). Não existe valor que impeça um robô de
 *     manter a conta trancada: o atacante escolhe o ritmo, e 1 requisição por
 *     ciclo basta para qualquer duração. O que o teto controla é o preço: quem
 *     fica de fora espera no máximo 5 minutos, e o robô lento (1 falha a cada 29
 *     minutos, para manter o contador vivo dentro da janela) trava 17% do tempo em
 *     vez de 52%. O custo é no orçamento de chutes contra a conta: o ritmo
 *     sustentado sobe de 4 por hora para 12 por hora (288 por dia), ainda longe do
 *     que quebra uma senha que não seja trivial, e o par e o IP seguem valendo.
 *  3. O LOGIN COM SUCESSO ZERA o contador da conta (ver loginThrottleRegisterSuccess):
 *     o erro de digitação do dono depois de um ataque não derruba todo mundo. O
 *     contador do IP NÃO zera, porque quem tem qualquer conta legítima passaria a
 *     espalhar tentativas sem teto, entrando na própria conta entre uma rodada e
 *     outra. E um bloqueio da conta que já está ativo não é levantado pelo login do
 *     dono: ataque em andamento termina por tempo, ou à mão.
 *
 * Para liberar à mão uma conta travada: `DELETE FROM login_throttle WHERE scope =
 * 'account'` (libera todas as contas; o hash do bucket não deixa escolher uma).
 * Para um IP: `scope IN ('login_ip', 'ip')`. Para revogar toda a confiança:
 * `scope = 'trusted'`. As três são independentes.
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
 * (loginThrottleBlockSeconds, loginThrottleDecide, loginThrottleEffectiveBlock) e
 * é a parte coberta pela suíte, que roda em SQLite e não executa o SQL de MySQL
 * abaixo. O SQL da leitura por escopo, do marcador de confiança e da limpeza foi
 * conferido à mão em MariaDB 10.11 (users.login em utf8mb4_unicode_ci), mas
 * continua fora da suíte: mexeu nele, repita a conferência.
 */

require_once __DIR__ . '/security.php';

const LOGIN_THROTTLE_SCOPE_PAIR = 'login_ip';
const LOGIN_THROTTLE_SCOPE_IP = 'ip';
const LOGIN_THROTTLE_SCOPE_ACCOUNT = 'account';
// Marcador (conta, IP) de login bem-sucedido. Não é contador de falhas: ver
// "IP de confiança" no topo.
const LOGIN_THROTTLE_SCOPE_TRUSTED = 'trusted';
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
// Teto do bloqueio do contador por CONTA (5 min), menor que o dos demais: o
// atacante escolhe o ritmo e pode manter a conta trancada com qualquer duração,
// então o teto só decide o preço de quem fica de fora. Ver o item 2 do custo do
// teto por conta, no topo.
const LOGIN_THROTTLE_ACCOUNT_MAX_BLOCK = 300;
// Linhas de throttle mais antigas que isto são descartadas (24 h). Não vale para
// os marcadores de confiança, que têm retenção própria.
const LOGIN_THROTTLE_RETENTION = 86400;
// Um IP continua "de confiança" para uma conta por tanto tempo depois do último
// login bem-sucedido dela (30 dias). Também é a retenção do marcador.
const LOGIN_THROTTLE_TRUST_TTL = 2592000;
// Letras que a colação unicode do banco trata como duas ('ß' = 'ss', 'œ' = 'oe') e
// que nenhuma decomposição Unicode separa. 'æ' fica de fora: a colação não o
// iguala a 'ae'. Medido em MariaDB 10.11, utf8mb4_unicode_ci.
const LOGIN_THROTTLE_COLLATION_EXPANSIONS = ['ß' => 'ss', 'œ' => 'oe'];

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

    // Marcas combinantes soltas (acento digitado como caractere à parte).
    $value = preg_replace('/\p{M}+/u', '', $value) ?? $value;

    return strtr(strtr($value, $map), LOGIN_THROTTLE_COLLATION_EXPANSIONS);
}

/**
 * Troca os caracteres de LARGURA TOTAL (U+FF01 a U+FF5E, "ａｄｍｉｎ", "１２３") pelo
 * ASCII correspondente. A colação do banco os trata como iguais ao ASCII, e o
 * Normalizer do intl faria o mesmo, mas a hospedagem compartilhada nem sempre
 * tem intl, então a troca é aritmética e vale nos dois caminhos. Cada um desses
 * caracteres tem 3 bytes em UTF-8 (EF BC 81 a EF BD 9E); o ponto de código sai
 * dos bytes sem precisar de mbstring.
 */
function loginThrottleFoldFullwidth(string $value): string
{
    $folded = preg_replace_callback(
        '/[\x{FF01}-\x{FF5E}]/u',
        static function (array $match): string {
            $bytes = $match[0];
            $codePoint = ((ord($bytes[0]) & 0x0F) << 12) | ((ord($bytes[1]) & 0x3F) << 6) | (ord($bytes[2]) & 0x3F);

            return chr($codePoint - 0xFEE0);
        },
        $value
    );

    return is_string($folded) ? $folded : $value;
}

/**
 * Chave estável de um login para os contadores: aparada, em caixa baixa, sem
 * acento, sem largura total e sem caracteres invisíveis.
 *
 * O MySQL da hospedagem compara com uma colação que ignora caixa E acento, então
 * 'admin', 'ADMIN' e 'admín' encontram a MESMA conta, e nas colações unicode
 * também 'ａｄｍｉｎ' (largura total) e 'adm\u{200B}in' (caractere de largura zero).
 * Se a chave só baixasse a caixa, cada variação ganhava um contador novo e o
 * limite de tentativas se multiplicava por quantas variações o atacante
 * inventasse. Para logins ASCII o resultado é idêntico ao antigo
 * `strtolower(trim())`, então os contadores já gravados continuam valendo.
 *
 * Esta chave só decide o contador de um login que NÃO existe (e o do par). Para
 * conta que existe vale o ID do usuário, que acompanha a colação do banco por
 * construção. A normalização aproxima a colação e não a iguala (há equivalências
 * raras que ela não cobre), por isso a chave por ID existe.
 */
function loginThrottleNormalizeLogin(string $login): string
{
    $value = loginThrottleFoldFullwidth(trim($login));
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);

    // Caracteres de formato (categoria Cf: largura zero, hífen suave, marca de
    // ordem de bytes). A colação os ignora; sem isto, cada um multiplicava as
    // grafias de um mesmo login.
    $visible = preg_replace('/\p{Cf}+/u', '', $value);
    if (is_string($visible)) {
        $value = $visible;
    }

    if (class_exists('Normalizer')) {
        // KD (e não D): além de separar o acento, desfaz as formas de
        // compatibilidade (ligaduras, sobrescritos).
        $decomposed = Normalizer::normalize($value, Normalizer::FORM_KD);
        if (is_string($decomposed)) {
            // Marcas soltas (categoria M inteira: acento, mas também as marcas
            // espaçadoras e envolventes, que a colação ignora) depois da
            // decomposição.
            $stripped = preg_replace('/\p{M}+/u', '', $decomposed);
            if (is_string($stripped)) {
                return strtr($stripped, LOGIN_THROTTLE_COLLATION_EXPANSIONS);
            }
        }
    }

    return loginThrottleStripAccentsFallback($value);
}

/**
 * Identidade da conta para os contadores de CONTA: o ID quando o login existe,
 * a chave normalizada quando não existe.
 *
 * Os dois ramos usam pseudo-escopos diferentes ('account_id' e 'account') para
 * que um login inexistente digitado como "5" nunca caia no bucket da conta de
 * ID 5.
 *
 * @param array<string,mixed>|null $account linha de `users` (precisa de `id`), ou
 *        null quando o login não existe
 */
function loginThrottleAccountBucket(string $login, ?array $account): string
{
    if ($account !== null) {
        return loginThrottleBucket(LOGIN_THROTTLE_SCOPE_ACCOUNT . '_id', (string) (int) $account['id']);
    }

    return loginThrottleBucket(LOGIN_THROTTLE_SCOPE_ACCOUNT, loginThrottleNormalizeLogin($login));
}

/**
 * Bucket do marcador de confiança (conta, IP). Amarrado ao password_hash
 * vigente: depois de uma troca ou reset de senha o bucket muda, e os marcadores
 * antigos deixam de valer sozinhos (e expiram pelo descarte de 30 dias).
 *
 * @param array<string,mixed> $account linha de `users` (precisa de `id` e `password_hash`)
 */
function loginThrottleTrustBucket(array $account, string $ip): string
{
    $binding = hash('sha256', (string) ($account['password_hash'] ?? ''));

    return loginThrottleBucket(LOGIN_THROTTLE_SCOPE_TRUSTED, (int) $account['id'] . '|' . $binding . '|' . $ip);
}

/**
 * Os contadores que uma tentativa deste par alimenta, como
 * [scope, bucket, limite de falhas]. O do par vem primeiro e o da conta por
 * último (loginThrottleRegisterSuccess depende disso).
 *
 * @param array<string,mixed>|null $account linha de `users` quando o login existe
 */
function loginThrottleBuckets(string $login, string $ip, ?array $account = null): array
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
            loginThrottleAccountBucket($login, $account),
            LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES,
        ],
    ];
}

/**
 * Segundos de bloqueio para um contador com `$failures` falhas (a atual
 * inclusive) e limite `$maxFailures`. Zero abaixo do limite; no limite, o tempo
 * base; daí em diante dobra a cada falha, até `$maxBlock` (por padrão
 * LOGIN_THROTTLE_MAX_BLOCK; o contador da conta passa um teto menor).
 *
 * Função pura: é a decisão, separada do SQL, e é o que a suíte exercita.
 */
function loginThrottleBlockSeconds(int $failures, int $maxFailures, int $maxBlock = LOGIN_THROTTLE_MAX_BLOCK): int
{
    if ($failures < $maxFailures) {
        return 0;
    }

    // `min(..., 20)` evita estourar a potência com contador gigante.
    $steps = min($failures - $maxFailures, 20);

    return (int) min($maxBlock, LOGIN_THROTTLE_BASE_BLOCK * (2 ** $steps));
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
    // escopo => [limite de falhas, teto do bloqueio]
    $rules = [
        LOGIN_THROTTLE_SCOPE_PAIR => [LOGIN_THROTTLE_MAX_FAILURES, LOGIN_THROTTLE_MAX_BLOCK],
        LOGIN_THROTTLE_SCOPE_IP => [LOGIN_THROTTLE_IP_MAX_FAILURES, LOGIN_THROTTLE_MAX_BLOCK],
        LOGIN_THROTTLE_SCOPE_ACCOUNT => [LOGIN_THROTTLE_ACCOUNT_MAX_FAILURES, LOGIN_THROTTLE_ACCOUNT_MAX_BLOCK],
    ];

    $blocks = [];
    foreach ($failuresByScope as $scope => $failures) {
        if (!isset($rules[$scope])) {
            continue;
        }

        [$maxFailures, $maxBlock] = $rules[$scope];
        $blocks[$scope] = loginThrottleBlockSeconds((int) $failures, $maxFailures, $maxBlock);
    }

    return $blocks;
}

/** O maior bloqueio entre os contadores, que é o que a resposta ao cliente anuncia. */
function loginThrottleLongestBlock(array $blocksByScope): int
{
    return $blocksByScope === [] ? 0 : max(0, ...array_map('intval', array_values($blocksByScope)));
}

/**
 * O maior bloqueio que VALE para esta tentativa. Num IP de confiança o contador
 * da conta é ignorado; o do par e o do IP continuam contando.
 *
 * Serve tanto para segundos que faltam (leitura antes da senha) quanto para
 * segundos recém-aplicados (resposta a uma falha): o dono num IP de confiança
 * que erra a senha durante um ataque recebe 401 como qualquer erro, e não um 429
 * causado por falhas de outros.
 *
 * @param array<string,int> $blocksByScope escopo => segundos
 */
function loginThrottleEffectiveBlock(array $blocksByScope, bool $trusted): int
{
    if ($trusted) {
        unset($blocksByScope[LOGIN_THROTTLE_SCOPE_ACCOUNT]);
    }

    return loginThrottleLongestBlock($blocksByScope);
}

/**
 * O IP identifica alguém? apiThrottleIp() devolve 'unknown' quando o servidor não
 * informa REMOTE_ADDR, e esse valor é de TODO MUNDO que chega assim: confiar nele
 * seria confiar em qualquer requisição sem endereço.
 */
function loginThrottleIpIsIdentifiable(string $ip): bool
{
    return $ip !== '' && $ip !== 'unknown';
}

/**
 * Este IP autenticou esta conta com sucesso nos últimos LOGIN_THROTTLE_TRUST_TTL
 * segundos (e com a senha vigente)?
 *
 * Falha FECHADA, ao contrário do resto do arquivo: se a consulta quebrar, o IP
 * não é de confiança e o teto da conta vale. Perder a exceção custa um incômodo
 * ao dono; ganhá-la por engano abriria o teto.
 *
 * @param array<string,mixed>|null $account linha de `users`; null (login que não existe) nunca é de confiança
 */
function loginThrottleIsTrusted(PDO $db, ?array $account, string $ip): bool
{
    if ($account === null || !loginThrottleIpIsIdentifiable($ip)) {
        return false;
    }

    try {
        $stmt = $db->prepare(sprintf(
            "SELECT 1 FROM login_throttle
             WHERE bucket = ? AND scope = ? AND last_failure_at >= (NOW() - INTERVAL %d SECOND)
             LIMIT 1",
            (int) LOGIN_THROTTLE_TRUST_TTL
        ));
        $stmt->execute([loginThrottleTrustBucket($account, $ip), LOGIN_THROTTLE_SCOPE_TRUSTED]);

        return $stmt->fetch() !== false;
    } catch (\Throwable $e) {
        error_log('Rate limit de login: não consegui consultar a confiança do IP: ' . $e->getMessage());

        return false;
    }
}

/**
 * Segundos que faltam para a tentativa sair do bloqueio: o maior valor entre
 * os contadores que valem para ela (ver loginThrottleEffectiveBlock). Zero
 * significa liberado.
 *
 * `$account` é a linha de `users` quando o login existe. O chamador precisa
 * consultar o usuário ANTES: é o ID dele que identifica a conta nos contadores.
 *
 * Falha aberta de propósito: se a tabela de throttle estiver indisponível o
 * login continua funcionando (com o erro registrado), em vez de derrubar o
 * acesso de todo mundo.
 *
 * @param array<string,mixed>|null $account
 */
function loginThrottleRetryAfter(PDO $db, string $login, string $ip, ?array $account = null): int
{
    try {
        $buckets = array_column(loginThrottleBuckets($login, $ip, $account), 1);
        $placeholders = implode(', ', array_fill(0, count($buckets), '?'));

        $stmt = $db->prepare("
            SELECT scope, GREATEST(TIMESTAMPDIFF(SECOND, NOW(), blocked_until), 1) AS retry_after
            FROM login_throttle
            WHERE bucket IN ({$placeholders}) AND blocked_until IS NOT NULL AND blocked_until > NOW()
        ");
        $stmt->execute($buckets);

        $remaining = [];
        while (($row = $stmt->fetch()) !== false && is_array($row)) {
            $remaining[(string) $row['scope']] = (int) $row['retry_after'];
        }

        // A consulta de confiança só acontece quando a CONTA é o que bloqueia: é
        // o único caso em que ela muda a resposta.
        $trusted = isset($remaining[LOGIN_THROTTLE_SCOPE_ACCOUNT]) && loginThrottleIsTrusted($db, $account, $ip);

        return loginThrottleEffectiveBlock($remaining, $trusted);
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
 * bloqueio que vale para ela (0 quando nenhum estourou).
 *
 * O bloqueio é GRAVADO em todo contador que estourou, inclusive o da conta num
 * IP de confiança: ele continua valendo para os outros IPs. O que o IP de
 * confiança não recebe é o 429 por causa dele (ver loginThrottleEffectiveBlock).
 *
 * @param array<string,mixed>|null $account linha de `users` quando o login existe
 */
function loginThrottleRegisterFailure(PDO $db, string $login, string $ip, ?array $account = null): int
{
    try {
        $buckets = loginThrottleBuckets($login, $ip, $account);

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

        // Se a conta é o que bloqueia, o IP de confiança não recebe o 429 por
        // ela. A consulta só acontece nesse caso (o raro).
        $trusted = ($blocks[LOGIN_THROTTLE_SCOPE_ACCOUNT] ?? 0) > 0 && loginThrottleIsTrusted($db, $account, $ip);

        return loginThrottleEffectiveBlock($blocks, $trusted);
    } catch (\Throwable $e) {
        error_log('Rate limit de login indisponível na escrita: ' . $e->getMessage());

        return 0;
    }
}

/**
 * Autenticação bem-sucedida: zera o que é da pessoa e marca o IP como de
 * confiança.
 *
 *  - Zera o contador do PAR (login, IP), sempre.
 *  - Zera o contador da CONTA, a menos que ele esteja bloqueando neste momento.
 *    Sem isto, depois de um ataque um único erro de digitação do dono dentro da
 *    janela de 30 minutos bloqueava todo mundo. Quem ganha o zero é a própria
 *    conta, que acabou de provar ter dono ali; um login de outra conta nunca
 *    mexe nela. Bloqueio ativo não é levantado: o dono em IP de confiança entra
 *    durante um ataque, mas não devolve ao atacante um orçamento novo de chutes
 *    (o bloqueio de até 5 minutos termina sozinho).
 *  - NÃO zera o contador do IP. Quem tem qualquer conta legítima (mesmo uma de
 *    papel `user`) entraria nela entre uma rodada e outra e espalharia chutes sem
 *    teto, que é o que o contador do IP existe para impedir.
 *  - Grava (ou renova) o marcador de confiança (conta, IP), com validade de
 *    LOGIN_THROTTLE_TRUST_TTL a partir de agora.
 *
 * @param array<string,mixed> $account linha de `users` (precisa de `id` e `password_hash`)
 */
function loginThrottleRegisterSuccess(PDO $db, string $login, string $ip, array $account): void
{
    try {
        $buckets = loginThrottleBuckets($login, $ip, $account);
        $pairBucket = $buckets[0][1];
        $accountBucket = $buckets[2][1];

        $pair = $db->prepare("DELETE FROM login_throttle WHERE bucket = ?");
        $pair->execute([$pairBucket]);

        $accountRow = $db->prepare(
            "DELETE FROM login_throttle WHERE bucket = ? AND (blocked_until IS NULL OR blocked_until <= NOW())"
        );
        $accountRow->execute([$accountBucket]);

        if (!loginThrottleIpIsIdentifiable($ip)) {
            return;
        }

        // `failures` fica 0 e `blocked_until` NULL: esta linha nunca bloqueia
        // ninguém. last_failure_at guarda o último sucesso (ver topo do arquivo).
        $trust = $db->prepare("
            INSERT INTO login_throttle (bucket, scope, failures, first_failure_at, last_failure_at)
            VALUES (?, ?, 0, NOW(), NOW())
            ON DUPLICATE KEY UPDATE last_failure_at = NOW()
        ");
        $trust->execute([loginThrottleTrustBucket($account, $ip), LOGIN_THROTTLE_SCOPE_TRUSTED]);
    } catch (\Throwable $e) {
        error_log('Falha ao registrar sucesso no rate limit de login: ' . $e->getMessage());
    }
}

/**
 * Limpeza probabilística (1 em 100 requisições) para a tabela não crescer sem fim.
 * A decisão de rodar mora aqui; o que apagar mora em loginThrottleCleanupNow().
 */
function loginThrottleCleanup(PDO $db): void
{
    try {
        if (random_int(1, 100) !== 1) {
            return;
        }
    } catch (\Throwable $e) {
        error_log('Falha ao sortear a limpeza do rate limit: ' . $e->getMessage());

        return;
    }

    loginThrottleCleanupNow($db);
}

/**
 * Apaga o que venceu. Contadores de falha vencem em LOGIN_THROTTLE_RETENTION
 * (24 h) depois da última falha, e só se não estiverem bloqueando. Marcadores de
 * confiança têm retenção PRÓPRIA, LOGIN_THROTTLE_TRUST_TTL (30 dias): apagá-los
 * junto com os contadores tiraria a confiança do dono 24 horas depois do último
 * login, e o remédio contra o trancamento só funcionaria no dia do login.
 */
function loginThrottleCleanupNow(PDO $db): void
{
    try {
        $db->exec(sprintf(
            "DELETE FROM login_throttle
             WHERE scope <> '%s'
               AND last_failure_at < (NOW() - INTERVAL %d SECOND)
               AND (blocked_until IS NULL OR blocked_until < NOW())",
            LOGIN_THROTTLE_SCOPE_TRUSTED,
            (int) LOGIN_THROTTLE_RETENTION
        ));

        $db->exec(sprintf(
            "DELETE FROM login_throttle
             WHERE scope = '%s' AND last_failure_at < (NOW() - INTERVAL %d SECOND)",
            LOGIN_THROTTLE_SCOPE_TRUSTED,
            (int) LOGIN_THROTTLE_TRUST_TTL
        ));
    } catch (\Throwable $e) {
        error_log('Falha ao limpar tabela de rate limit: ' . $e->getMessage());
    }
}
