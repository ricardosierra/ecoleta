<?php
declare(strict_types=1);

/**
 * Runner HTTP seguro de migrations para execução automática durante o deploy.
 *
 * Exige segredo (CRON_SECRET) via cabeçalho X-Cron-Secret ou X-Deploy-Token.
 * Aplica apenas as migrations pendentes de forma idempotente e registra cada
 * uma em schema_migrations.
 *
 * REGRA INVIOLÁVEL: Nenhuma migration pode resetar senhas de usuários existentes.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

apiSendJsonHeaders();

// ── 1. Autenticação ──────────────────────────────────────────────────────────
$cronSecret = apiSecret('CRON_SECRET');
if ($cronSecret === '') {
    error_log('api/migrate.php: CRON_SECRET não configurado — execução recusada.');
    apiJsonResponse(503, ['ok' => false, 'error' => 'CRON_SECRET não configurado no servidor.']);
}

$sentSecret = trim((string) (apiRequestHeader('X-Deploy-Token') ?: apiRequestHeader('X-Cron-Secret')));
if ($sentSecret === '') {
    $sentSecret = trim((string) ($_GET['secret'] ?? ''));
}

// Em CLI no servidor ou contexto de teste autorizado
if (PHP_SAPI === 'cli' && getenv('ECOLETA_TEST_CONTEXT') === false) {
    $sentSecret = $cronSecret;
}

if ($sentSecret === '' || !hash_equals($cronSecret, $sentSecret)) {
    error_log('api/migrate.php: tentativa com segredo inválido de ' . apiClientIp());
    apiJsonResponse(403, ['ok' => false, 'error' => 'Acesso negado.']);
}

// ── 2. Conexão ao banco e ledger ─────────────────────────────────────────────
// Sem o portão de schema: com o banco atrás do código, todo endpoint responde
// 503 — inclusive este, que é justamente quem põe o banco em dia. O segredo
// conferido acima continua sendo a única porta de entrada.
$db = getDbConnection(false);

$isMysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

if ($isMysql) {
    $db->exec('
        CREATE TABLE IF NOT EXISTS schema_migrations (
            version INT UNSIGNED NOT NULL,
            filename VARCHAR(191) NOT NULL,
            checksum CHAR(64) NOT NULL,
            statements INT UNSIGNED NOT NULL DEFAULT 0,
            execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
} else {
    $db->exec('
        CREATE TABLE IF NOT EXISTS schema_migrations (
            version INTEGER NOT NULL PRIMARY KEY,
            filename TEXT NOT NULL,
            checksum TEXT NOT NULL,
            statements INTEGER NOT NULL DEFAULT 0,
            execution_ms INTEGER NOT NULL DEFAULT 0,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');
}

$appliedRows = $db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll();
$appliedVersions = [];
foreach ($appliedRows as $r) {
    $appliedVersions[(int) $r['version']] = true;
}

// ── 3. Descoberta de arquivos .sql ───────────────────────────────────────────
$customDir = getenv('ECOLETA_MIGRATIONS_DIR');
$migrationsDir = ($customDir !== false && is_dir($customDir)) ? $customDir : (__DIR__ . '/migrations');
if (!is_dir($migrationsDir)) {
    // Fallback para desenvolvimento local
    $migrationsDir = dirname(__DIR__, 2) . '/db/migrations';
}

if (!is_dir($migrationsDir)) {
    apiJsonResponse(500, ['ok' => false, 'error' => 'Diretório de migrations não encontrado.']);
}

$files = glob($migrationsDir . '/*.sql') ?: [];
$availableMigrations = [];

foreach ($files as $path) {
    $filename = basename($path);
    if (preg_match('/^(\d{3,})_([A-Za-z0-9_\-]+)\.sql$/', $filename, $matches)) {
        $version = (int) $matches[1];
        $availableMigrations[$version] = [
            'version'  => $version,
            'filename' => $filename,
            'path'     => $path,
        ];
    }
}

ksort($availableMigrations);

// ── 4. Funções auxiliares de parse ───────────────────────────────────────────
function apiMigrateSplitStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $length = strlen($sql);
    $i = 0;

    while ($i < $length) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        // Comentário de bloco
        if ($char === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $length : $end + 2;
            continue;
        }

        // Comentário de linha (-- ou #)
        $isDashComment = $char === '-' && $next === '-' && (
            $i + 2 >= $length || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t"
            || $sql[$i + 2] === "\n" || $sql[$i + 2] === "\r"
        );
        if ($char === '#' || $isDashComment) {
            $i += strcspn($sql, "\n", $i);
            continue;
        }

        // Literais entre aspas ou crases
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

// ── 5. Execução das migrations pendentes ──────────────────────────────────────
$appliedNow = [];

foreach ($availableMigrations as $version => $mig) {
    if (isset($appliedVersions[$version])) {
        continue;
    }

    $rawSql = (string) file_get_contents($mig['path']);
    $checksum = hash('sha256', $rawSql);

    // Substituição de placeholder (ex.: Power BI)
    $powerbiUrl = apiSecret('NEXT_PUBLIC_POWERBI_URL');
    if ($powerbiUrl !== '') {
        $rawSql = str_replace('{{NEXT_PUBLIC_POWERBI_URL}}', $db->quote($powerbiUrl), $rawSql);
    }

    // Regra de segurança estrita: proibir qualquer alteração não autorizada de senhas
    if (preg_match('/UPDATE\s+`?users`?\s+SET\s+[^;]*password_hash\s*=\s*/i', $rawSql)) {
        error_log("api/migrate.php: tentativa de reset de senha detectada na migration {$mig['filename']} — execução abortada.");
        apiJsonResponse(500, [
            'ok' => false,
            'error' => "A migration {$mig['filename']} tenta alterar senhas de usuários. Isso é estritamente proibido.",
        ]);
    }

    $statements = apiMigrateSplitStatements($rawSql);
    if (empty($statements)) {
        continue;
    }

    $startedAt = microtime(true);

    foreach ($statements as $idx => $stmt) {
        try {
            $db->exec($stmt);
        } catch (Throwable $e) {
            error_log(sprintf(
                'api/migrate.php: erro na migration %s (instrução %d/%d): %s',
                $mig['filename'],
                $idx + 1,
                count($statements),
                $e->getMessage()
            ));

            apiJsonResponse(500, [
                'ok' => false,
                'error' => "Falha na migration {$mig['filename']} (instrução " . ($idx + 1) . "): " . $e->getMessage(),
                'applied_before_failure' => $appliedNow,
            ]);
        }
    }

    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

    $ins = $db->prepare('
        INSERT INTO schema_migrations (version, filename, checksum, statements, execution_ms)
        VALUES (?, ?, ?, ?, ?)
    ');
    $ins->execute([
        $version,
        $mig['filename'],
        $checksum,
        count($statements),
        $durationMs,
    ]);

    $appliedNow[] = [
        'version' => $version,
        'filename' => $mig['filename'],
        'statements' => count($statements),
        'duration_ms' => $durationMs,
    ];
}

$finalVersionStmt = $db->query('SELECT MAX(version) AS v FROM schema_migrations');
$finalVersion = (int) ($finalVersionStmt->fetchColumn() ?: 0);

apiJsonResponse(200, [
    'ok' => true,
    'message' => count($appliedNow) > 0 ? 'Migrations aplicadas com sucesso.' : 'Nenhuma migration pendente.',
    'applied_count' => count($appliedNow),
    'applied' => $appliedNow,
    'current_version' => $finalVersion,
]);
