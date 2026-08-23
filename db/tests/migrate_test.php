<?php
declare(strict_types=1);

/**
 * Testes das partes puras de db/migrate.php — as que dá para exercitar sem
 * banco: o separador de instruções, a substituição de placeholder e o leitor
 * de .env.
 *
 * O separador é o pedaço mais arriscado do runner: um ponto e vírgula lido
 * dentro de uma string quebraria a migration ao meio e aplicaria SQL cortado.
 *
 *   php db/tests/migrate_test.php    (ou npm run test:migrations)
 */

require_once __DIR__ . '/../migrate.php';

$failures = 0;
$checks = 0;

function check(string $label, $expected, $actual): void
{
    global $failures, $checks;
    $checks++;

    if ($expected === $actual) {
        echo "  ok    {$label}\n";

        return;
    }

    $failures++;
    echo "  FALHA {$label}\n";
    echo '        esperado: ' . var_export($expected, true) . "\n";
    echo '        obtido:   ' . var_export($actual, true) . "\n";
}

echo "migrateSplitStatements()\n";

check(
    'duas instruções simples',
    ['SELECT 1', 'SELECT 2'],
    migrateSplitStatements('SELECT 1; SELECT 2;')
);

check(
    'ponto e vírgula final é opcional',
    ['SELECT 1'],
    migrateSplitStatements('SELECT 1')
);

check(
    'comentário de linha com --',
    ['SELECT 1'],
    migrateSplitStatements("-- comentário; com ponto e vírgula\nSELECT 1;")
);

check(
    'comentário de linha com #',
    ['SELECT 1'],
    migrateSplitStatements("# outro; comentário\nSELECT 1;")
);

check(
    '-- colado é operador de subtração, não comentário',
    ['SELECT 1--2'],
    migrateSplitStatements('SELECT 1--2;')
);

check(
    'comentário de bloco é descartado',
    ['SELECT 1'],
    migrateSplitStatements('/* bloco; com ; pontos */ SELECT 1;')
);

check(
    'ponto e vírgula dentro de string não separa',
    ["INSERT INTO t VALUES ('a;b')"],
    migrateSplitStatements("INSERT INTO t VALUES ('a;b');")
);

check(
    'aspa dobrada dentro de string',
    ["SELECT 'it''s; here'"],
    migrateSplitStatements("SELECT 'it''s; here';")
);

check(
    'barra invertida escapa a aspa',
    ["SELECT 'a\\'; b'"],
    migrateSplitStatements("SELECT 'a\\'; b';")
);

check(
    'identificador entre crases',
    ['SELECT * FROM `groups`'],
    migrateSplitStatements('SELECT * FROM `groups`;')
);

check(
    'instruções vazias entre ; são descartadas',
    ['SELECT 1', 'SELECT 2'],
    migrateSplitStatements("SELECT 1;;\n;SELECT 2;")
);

echo "\nArquivos reais de db/migrations/\n";

$expectedStatements = [
    '001_initial.sql'       => 3,
    '002_groups.sql'        => 1,
    '003_add_group_id.sql'  => 5,
    '004_seed_groups.sql'   => 2,
    '005_login_throttle.sql' => 1,
];

foreach ($expectedStatements as $file => $count) {
    $path = __DIR__ . '/../migrations/' . $file;
    $sql = file_get_contents($path);

    if ($sql === false) {
        echo "  FALHA não consegui ler {$file}\n";
        $failures++;
        continue;
    }

    $statements = migrateSplitStatements($sql);
    check("{$file} rende {$count} instrução(ões)", $count, count($statements));

    foreach ($statements as $statement) {
        if (str_starts_with($statement, '--') || str_starts_with($statement, '/*') || str_starts_with($statement, '#')) {
            echo "  FALHA {$file}: sobrou comentário como instrução\n";
            $failures++;
        }
    }
}

echo "\nmigrateExpandPlaceholders()\n";

// PDO::quote() precisa de conexão; sqlite em memória serve para exercitar a
// substituição. O que está sob teste é a troca do {{NOME}}, não o dialeto.
$pdo = new PDO('sqlite::memory:');

$migration = [
    'filename' => 'teste.sql',
    'sql'      => "INSERT INTO g (u) VALUES ({{NEXT_PUBLIC_POWERBI_URL}});",
];

$config = ['file' => ['NEXT_PUBLIC_POWERBI_URL' => 'https://app.powerbi.com/view?r=abc'], 'sources' => []];
check(
    'placeholder vira literal citado',
    "INSERT INTO g (u) VALUES ('https://app.powerbi.com/view?r=abc');",
    migrateExpandPlaceholders($pdo, $config, $migration)
);

// $ e \ no valor seriam lidos por preg_replace como referência de captura.
$config = ['file' => ['NEXT_PUBLIC_POWERBI_URL' => 'a$1b\\c'], 'sources' => []];
check(
    'cifrão e barra invertida sobrevivem à substituição',
    "INSERT INTO g (u) VALUES (" . $pdo->quote('a$1b\\c') . ");",
    migrateExpandPlaceholders($pdo, $config, $migration)
);

check(
    'SQL sem placeholder passa intacto',
    'SELECT 1;',
    migrateExpandPlaceholders($pdo, $config, ['filename' => 'x.sql', 'sql' => 'SELECT 1;'])
);

echo "\nmigrateParseEnvFile()\n";

$tmp = tempnam(sys_get_temp_dir(), 'ecoleta_env_');
file_put_contents($tmp, <<<ENV
# comentário
DB_NAME=ecoleta
DB_PASS="com espaço"
DB_USER='aspas simples'
VAZIO=
SEM_IGUAL
  DB_HOST = localhost
ENV);

check(
    'chaves, aspas e comentários',
    [
        'DB_NAME' => 'ecoleta',
        'DB_PASS' => 'com espaço',
        'DB_USER' => 'aspas simples',
        'VAZIO'   => '',
        'DB_HOST' => 'localhost',
    ],
    migrateParseEnvFile($tmp)
);

unlink($tmp);

echo "\n";
echo $failures === 0
    ? "{$checks} verificações, todas passaram.\n"
    : "{$checks} verificações, {$failures} falha(s).\n";

exit($failures === 0 ? 0 : 1);
