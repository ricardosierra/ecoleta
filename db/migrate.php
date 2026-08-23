<?php
declare(strict_types=1);

/**
 * Aplicador de migrations do dashboard Ecoleta.
 *
 * Roda por SSH/CLI, à mão, NUNCA por requisição HTTP — é ele que tem permissão
 * de DDL. A aplicação, em public/api/, só conecta e lê/escreve dados; quando o
 * banco está atrás do código ela responde 503 (ver public/api/schema.php).
 *
 * Uso:
 *
 *   php db/migrate.php status         # o que já foi aplicado e o que falta
 *   php db/migrate.php migrate        # aplica as pendentes (padrão)
 *   php db/migrate.php migrate --dry-run
 *   php db/migrate.php --help
 *
 * Configuração, em ordem de precedência:
 *
 *   1. variáveis de ambiente reais    (DB_HOST=... php db/migrate.php)
 *   2. public/api/env.php             (gerado pelo deploy)
 *   3. .env na raiz do repositório    (--env-file=CAMINHO para outro arquivo)
 *
 * Credenciais: DB_DDL_USER / DB_DDL_PASS quando existirem — o usuário separado
 * que tem permissão de DDL. Sem eles o runner cai em DB_USER / DB_PASS e avisa,
 * porque nesse caso o usuário da aplicação ainda carrega privilégio de sobra.
 *
 * Sobre falha no meio do caminho: MySQL faz commit implícito em cada DDL, então
 * não existe rollback de migration. Uma migration que quebra na terceira
 * instrução não é registrada, e rodar de novo repete as três — por isso toda
 * instrução em db/migrations/ é escrita para ser idempotente.
 */

// ─── Só CLI ──────────────────────────────────────────────────────────────────
// db/ não é publicado pelo deploy (só out/ sobe por FTP), mas se um dia este
// arquivo acabar dentro do webroot, ele não responde nada.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

const MIGRATE_DIR = __DIR__ . '/migrations';
const MIGRATE_ROOT = __DIR__ . '/..';
const MIGRATE_TABLE = 'schema_migrations';

/**
 * Placeholders aceitos dentro dos .sql, na forma {{NOME}}. O valor entra já
 * escapado por PDO::quote(). Nome fora desta lista é erro, não string vazia:
 * um typo em placeholder deve parar a migration, não semear dado errado.
 */
const MIGRATE_PLACEHOLDERS = ['NEXT_PUBLIC_POWERBI_URL'];

// ─── Saída ───────────────────────────────────────────────────────────────────

function migrateOut(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function migrateWarn(string $message): void
{
    fwrite(STDERR, 'aviso: ' . $message . PHP_EOL);
}

/** Encerra com código 1 — nenhum passo seguinte do deploy deve continuar. */
function migrateFail(string $message): void
{
    fwrite(STDERR, 'erro: ' . $message . PHP_EOL);
    exit(1);
}

// ─── Configuração ────────────────────────────────────────────────────────────

/**
 * Lê um arquivo .env simples (KEY=VALUE). Sem dependência externa: a hospedagem
 * é compartilhada e o projeto não usa Composer.
 */
function migrateParseEnvFile(string $path): array
{
    $values = [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $values;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        // Remove aspas em volta do valor, se houver.
        $len = strlen($value);
        if ($len >= 2 && (
            ($value[0] === '"' && $value[$len - 1] === '"') ||
            ($value[0] === "'" && $value[$len - 1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '') {
            $values[$key] = $value;
        }
    }

    return $values;
}

/**
 * Monta a configuração e devolve de onde cada camada veio, para o runner
 * conseguir dizer no log qual arquivo ele leu.
 */
function migrateLoadConfig(?string $envFileOverride): array
{
    $sources = [];

    $envPhp = MIGRATE_ROOT . '/public/api/env.php';
    if (is_file($envPhp)) {
        require_once $envPhp;
        $sources[] = $envPhp;
    }

    $envFile = $envFileOverride ?? (MIGRATE_ROOT . '/.env');
    $fromFile = [];
    if (is_file($envFile)) {
        $fromFile = migrateParseEnvFile($envFile);
        $sources[] = $envFile;
    } elseif ($envFileOverride !== null) {
        migrateFail('arquivo de ambiente não encontrado: ' . $envFileOverride);
    }

    return ['file' => $fromFile, 'sources' => $sources];
}

/** Valor de configuração: ambiente real > constante do env.php > .env. */
function migrateConfig(array $config, string $name, string $default = ''): string
{
    $fromEnv = getenv($name);
    if (is_string($fromEnv) && trim($fromEnv) !== '') {
        return trim($fromEnv);
    }

    if (defined($name)) {
        $value = trim((string) constant($name));
        if ($value !== '') {
            return $value;
        }
    }

    if (isset($config['file'][$name]) && trim($config['file'][$name]) !== '') {
        return trim($config['file'][$name]);
    }

    return $default;
}

/**
 * Igual à anterior, mas aceita valor definido e vazio como resposta legítima —
 * usada por placeholder, onde "definido como vazio" e "não definido" precisam
 * ser distinguíveis apenas para a mensagem de aviso.
 */
function migrateConfigIsDefined(array $config, string $name): bool
{
    $fromEnv = getenv($name);
    if (is_string($fromEnv)) {
        return true;
    }

    return defined($name) || isset($config['file'][$name]);
}

// ─── Conexão ─────────────────────────────────────────────────────────────────

function migrateConnect(array $config): PDO
{
    $host = migrateConfig($config, 'DB_HOST', 'localhost');
    $name = migrateConfig($config, 'DB_NAME');
    $port = migrateConfig($config, 'DB_PORT', '3306');

    if ($name === '') {
        migrateFail('DB_NAME não definido. Exporte as variáveis ou aponte --env-file para o arquivo certo.');
    }

    $user = migrateConfig($config, 'DB_DDL_USER');
    $pass = migrateConfig($config, 'DB_DDL_PASS');

    if ($user === '') {
        $user = migrateConfig($config, 'DB_USER');
        $pass = migrateConfig($config, 'DB_PASS');
        migrateWarn(
            'DB_DDL_USER não definido — usando DB_USER. Em produção, crie um usuário separado com permissão de DDL '
            . '(ver docs/deploy.md); o usuário da aplicação só precisa de SELECT/INSERT/UPDATE/DELETE.'
        );
    }

    if ($user === '') {
        migrateFail('nenhum usuário de banco definido (DB_DDL_USER ou DB_USER).');
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

    try {
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        migrateFail(sprintf('não foi possível conectar em %s@%s/%s: %s', $user, $host, $name, $e->getMessage()));
    }

    exit(1); // inalcançável: migrateFail encerra
}

// ─── Registro de migrations ──────────────────────────────────────────────────

/**
 * Cria a tabela-registro se ela não existir. É o único DDL que o runner emite
 * por conta própria — sem ele não há como saber o que já rodou.
 */
function migrateEnsureLedger(PDO $db): void
{
    $db->exec('
        CREATE TABLE IF NOT EXISTS ' . MIGRATE_TABLE . ' (
            version INT UNSIGNED NOT NULL,
            filename VARCHAR(191) NOT NULL,
            checksum CHAR(64) NOT NULL,
            statements INT UNSIGNED NOT NULL DEFAULT 0,
            execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

/** Linhas já registradas, indexadas por versão. */
function migrateAppliedRows(PDO $db): array
{
    $rows = $db->query('SELECT version, filename, checksum, applied_at FROM ' . MIGRATE_TABLE . ' ORDER BY version')->fetchAll();

    $applied = [];
    foreach ($rows as $row) {
        $applied[(int) $row['version']] = $row;
    }

    return $applied;
}

/** Lê db/migrations/ e devolve as migrations ordenadas por versão. */
function migrateDiscover(): array
{
    if (!is_dir(MIGRATE_DIR)) {
        migrateFail('diretório de migrations não encontrado: ' . MIGRATE_DIR);
    }

    $files = glob(MIGRATE_DIR . '/*.sql');
    if ($files === false || $files === []) {
        migrateFail('nenhuma migration em ' . MIGRATE_DIR);
    }

    $migrations = [];
    foreach ($files as $path) {
        $filename = basename($path);

        if (!preg_match('/^(\d{3,})_([A-Za-z0-9_\-]+)\.sql$/', $filename, $matches)) {
            migrateFail(sprintf(
                'nome de migration fora do padrão: %s (esperado NNN_descricao.sql, ex.: 006_nova_tabela.sql)',
                $filename
            ));
        }

        $version = (int) $matches[1];
        if (isset($migrations[$version])) {
            migrateFail(sprintf(
                'duas migrations com a versão %d: %s e %s',
                $version,
                $migrations[$version]['filename'],
                $filename
            ));
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            migrateFail('não foi possível ler ' . $path);
        }

        $migrations[$version] = [
            'version'  => $version,
            'filename' => $filename,
            'path'     => $path,
            'sql'      => $sql,
            // Checksum do arquivo cru, antes da substituição de placeholder:
            // trocar a URL do Power BI no .env não pode parecer alteração da migration.
            'checksum' => hash('sha256', $sql),
        ];
    }

    ksort($migrations);

    return $migrations;
}

// ─── SQL ─────────────────────────────────────────────────────────────────────

/**
 * Quebra o arquivo em instruções, respeitando strings ('', "" e ``), escapes com
 * barra invertida e comentários de linha e de bloco. Comentários são descartados;
 * não sobram para o servidor.
 */
function migrateSplitStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $length = strlen($sql);
    $i = 0;

    while ($i < $length) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        // Comentário de bloco.
        if ($char === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $length : $end + 2;
            continue;
        }

        // Comentário de linha. MySQL exige espaço depois de `--`; sem ele é o
        // operador de subtração e a instrução continua.
        $isDashComment = $char === '-' && $next === '-' && (
            $i + 2 >= $length || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t"
            || $sql[$i + 2] === "\n" || $sql[$i + 2] === "\r"
        );
        if ($char === '#' || $isDashComment) {
            $i += strcspn($sql, "\n", $i);
            continue;
        }

        // Literais e identificadores citados entram inteiros, sem interpretação.
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $current .= $char;
            $i++;

            while ($i < $length) {
                $c = $sql[$i];

                if ($c === '\\' && $quote !== '`') {
                    $current .= $c;
                    if ($i + 1 < $length) {
                        $current .= $sql[$i + 1];
                        $i += 2;
                    } else {
                        $i++;
                    }
                    continue;
                }

                if ($c === $quote) {
                    // Aspas dobradas dentro da string são a própria aspa.
                    if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                        $current .= $c . $quote;
                        $i += 2;
                        continue;
                    }

                    $current .= $c;
                    $i++;
                    break;
                }

                $current .= $c;
                $i++;
            }

            continue;
        }

        if ($char === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            $i++;
            continue;
        }

        $current .= $char;
        $i++;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

/** Troca {{NOME}} pelo valor de configuração, já citado por PDO::quote(). */
function migrateExpandPlaceholders(PDO $db, array $config, array $migration): string
{
    $sql = $migration['sql'];

    if (!preg_match_all('/\{\{\s*([A-Z0-9_]+)\s*\}\}/', $sql, $matches)) {
        return $sql;
    }

    foreach (array_unique($matches[1]) as $name) {
        if (!in_array($name, MIGRATE_PLACEHOLDERS, true)) {
            migrateFail(sprintf(
                'placeholder desconhecido {{%s}} em %s. Aceitos: %s',
                $name,
                $migration['filename'],
                implode(', ', MIGRATE_PLACEHOLDERS)
            ));
        }

        $value = migrateConfig($config, $name);
        if ($value === '' && !migrateConfigIsDefined($config, $name)) {
            migrateWarn(sprintf(
                '%s não está definido — {{%s}} entra como string vazia em %s.',
                $name,
                $name,
                $migration['filename']
            ));
        }

        $sql = preg_replace(
            '/\{\{\s*' . preg_quote($name, '/') . '\s*\}\}/',
            // O valor citado pode conter $ ou \, que preg_replace leria como
            // referência de captura. addcslashes fecha isso.
            addcslashes($db->quote($value), '\\$'),
            $sql
        );
    }

    return $sql;
}

// ─── Aplicação ───────────────────────────────────────────────────────────────

function migrateApplyOne(PDO $db, array $config, array $migration, bool $dryRun): void
{
    $sql = migrateExpandPlaceholders($db, $config, $migration);
    $statements = migrateSplitStatements($sql);

    if ($statements === []) {
        migrateFail($migration['filename'] . ' não contém nenhuma instrução SQL.');
    }

    migrateOut(sprintf(
        '  %s %s (%d instrução%s)',
        $dryRun ? '[dry-run]' : '→',
        $migration['filename'],
        count($statements),
        count($statements) === 1 ? '' : 'ões'
    ));

    if ($dryRun) {
        foreach ($statements as $index => $statement) {
            migrateOut(sprintf('      %2d. %s', $index + 1, migrateSummarize($statement)));
        }

        return;
    }

    $startedAt = microtime(true);

    foreach ($statements as $index => $statement) {
        try {
            $db->exec($statement);
        } catch (PDOException $e) {
            migrateFail(sprintf(
                "%s falhou na instrução %d de %d:\n      %s\n      %s\n\n"
                . "Nada foi registrado em %s: MySQL faz commit implícito em DDL, então as instruções anteriores\n"
                . "continuam aplicadas. Corrija a causa e rode de novo — as migrations são idempotentes.",
                $migration['filename'],
                $index + 1,
                count($statements),
                migrateSummarize($statement),
                $e->getMessage(),
                MIGRATE_TABLE
            ));
        }
    }

    $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

    $stmt = $db->prepare('
        INSERT INTO ' . MIGRATE_TABLE . ' (version, filename, checksum, statements, execution_ms)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $migration['version'],
        $migration['filename'],
        $migration['checksum'],
        count($statements),
        $elapsedMs,
    ]);

    migrateOut(sprintf('     aplicada em %dms', $elapsedMs));
}

/** Primeira linha da instrução, encurtada — para log e dry-run. */
function migrateSummarize(string $statement): string
{
    $flat = trim(preg_replace('/\s+/', ' ', $statement) ?? $statement);

    return strlen($flat) > 96 ? substr($flat, 0, 93) . '...' : $flat;
}

/**
 * Confere se ECOLETA_SCHEMA_VERSION (public/api/schema.php) acompanha a última
 * migration. É o que impede a constante de ficar para trás sem ninguém ver: o
 * guard de 503 da API depende dela.
 */
function migrateCheckVersionConstant(array $migrations): void
{
    $schemaFile = MIGRATE_ROOT . '/public/api/schema.php';
    if (!is_file($schemaFile)) {
        migrateWarn('public/api/schema.php não encontrado — não deu para conferir ECOLETA_SCHEMA_VERSION.');

        return;
    }

    $source = (string) file_get_contents($schemaFile);
    if (!preg_match('/const\s+ECOLETA_SCHEMA_VERSION\s*=\s*(\d+)\s*;/', $source, $matches)) {
        migrateWarn('não achei ECOLETA_SCHEMA_VERSION em public/api/schema.php.');

        return;
    }

    $declared = (int) $matches[1];
    $latest = (int) array_key_last($migrations);

    if ($declared !== $latest) {
        migrateFail(sprintf(
            "ECOLETA_SCHEMA_VERSION está %d e a última migration é %d.\n"
            . "      Atualize a constante em public/api/schema.php para %d — sem isso a API aceita\n"
            . "      requisições contra um banco que ela acha que está em dia, ou responde 503 sem motivo.",
            $declared,
            $latest,
            $latest
        ));
    }
}

// ─── Comandos ────────────────────────────────────────────────────────────────

function migrateCommandStatus(array $migrations, array $applied): int
{
    migrateOut('versão  migration                     estado');
    migrateOut('──────  ────────────────────────────  ─────────────────────────────');

    $pending = 0;
    $drifted = 0;

    foreach ($migrations as $version => $migration) {
        $row = $applied[$version] ?? null;

        if ($row === null) {
            $state = 'PENDENTE';
            $pending++;
        } elseif ($row['checksum'] !== $migration['checksum']) {
            $state = 'ALTERADA APÓS APLICADA';
            $drifted++;
        } else {
            $state = 'aplicada em ' . $row['applied_at'];
        }

        migrateOut(sprintf('  %03d   %-28s  %s', $version, $migration['filename'], $state));
    }

    // Versão registrada no banco sem arquivo correspondente: código antigo
    // rodando contra banco novo, ou migration apagada do repositório.
    foreach ($applied as $version => $row) {
        if (!isset($migrations[$version])) {
            migrateOut(sprintf('  %03d   %-28s  registrada, sem arquivo no repositório', $version, $row['filename']));
        }
    }

    migrateOut();
    migrateOut(sprintf(
        'banco na versão %d · %d pendente(s) · %d alterada(s) após aplicada',
        $applied === [] ? 0 : max(array_keys($applied)),
        $pending,
        $drifted
    ));

    return $drifted > 0 ? 1 : 0;
}

function migrateCommandMigrate(PDO $db, array $config, array $migrations, array $applied, bool $dryRun): int
{
    foreach ($migrations as $version => $migration) {
        $row = $applied[$version] ?? null;
        if ($row !== null && $row['checksum'] !== $migration['checksum']) {
            migrateFail(sprintf(
                "%s mudou depois de ter sido aplicada (%s).\n"
                . "      Migration aplicada é imutável: crie uma nova em vez de editar esta.\n"
                . "      Se a edição foi intencional e o banco já reflete o novo conteúdo, atualize o checksum:\n"
                . "      UPDATE %s SET checksum = '%s' WHERE version = %d;",
                $migration['filename'],
                $row['applied_at'],
                MIGRATE_TABLE,
                $migration['checksum'],
                $version
            ));
        }
    }

    $pending = array_filter(
        $migrations,
        static function (array $migration) use ($applied): bool {
            return !isset($applied[$migration['version']]);
        }
    );

    if ($pending === []) {
        migrateOut(sprintf('Nada a fazer: banco já na versão %d.', (int) array_key_last($migrations)));

        return 0;
    }

    migrateOut(sprintf(
        '%s %d migration(s) pendente(s):',
        $dryRun ? 'Simulando' : 'Aplicando',
        count($pending)
    ));

    foreach ($pending as $migration) {
        migrateApplyOne($db, $config, $migration, $dryRun);
    }

    migrateOut();

    if ($dryRun) {
        migrateOut('Dry-run: nada foi executado nem registrado.');

        return 0;
    }

    migrateOut(sprintf('Pronto. Banco na versão %d.', (int) array_key_last($migrations)));
    migrateOut('Agora sim: publique os arquivos (npm run deploy:ftp).');

    return 0;
}

function migrateCommandHelp(): int
{
    migrateOut(<<<TXT
    Migrations do dashboard Ecoleta.

      php db/migrate.php status              o que já foi aplicado e o que falta
      php db/migrate.php migrate             aplica as pendentes (padrão)
      php db/migrate.php migrate --dry-run   mostra o que rodaria, sem executar
      php db/migrate.php --help              esta ajuda

    Opções:
      --env-file=CAMINHO   arquivo .env alternativo (padrão: .env na raiz)
      --dry-run            não executa nada; só lista

    Ordem do deploy: migrations primeiro, arquivos depois. Ver docs/deploy.md.
    TXT);

    return 0;
}

// ─── Entrada ─────────────────────────────────────────────────────────────────

function migrateMain(array $argv): int
{
    $command = 'migrate';
    $dryRun = false;
    $envFileOverride = null;

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') {
            $dryRun = true;
            continue;
        }

        if ($argument === '--help' || $argument === '-h' || $argument === 'help') {
            return migrateCommandHelp();
        }

        if (str_starts_with($argument, '--env-file=')) {
            $envFileOverride = substr($argument, strlen('--env-file='));
            continue;
        }

        if ($argument === 'status' || $argument === 'migrate') {
            $command = $argument;
            continue;
        }

        migrateFail('argumento desconhecido: ' . $argument . ' (use --help)');
    }

    $migrations = migrateDiscover();
    migrateCheckVersionConstant($migrations);

    $config = migrateLoadConfig($envFileOverride);
    if ($config['sources'] !== []) {
        migrateOut('Configuração lida de: ' . implode(', ', $config['sources']));
    }

    $db = migrateConnect($config);
    migrateEnsureLedger($db);
    $applied = migrateAppliedRows($db);

    return $command === 'status'
        ? migrateCommandStatus($migrations, $applied)
        : migrateCommandMigrate($db, $config, $migrations, $applied, $dryRun);
}

// Só roda quando o arquivo é chamado direto. Incluir migrate.php de outro script
// (db/tests/) carrega as funções sem disparar nenhuma conexão.
if (isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(migrateMain($argv));
}
