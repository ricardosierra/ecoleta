<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/schema.php';

// Carrega as variáveis de ambiente geradas pelo deploy (arquivo fora do Git).
//
// `ECOLETA_ENV_FILE` no ambiente troca o arquivo, e o valor `none` manda não
// carregar nenhum. É o que a suíte de testes usa: sem isso, o env.php da máquina
// de quem edita o código — que o deploy gera com as credenciais DE PRODUÇÃO —
// entra nos testes, e um caso escrito para "sem chave configurada" passa a rodar
// com a chave real na mão. Já aconteceu: AsaasLibTest e BillingEndpointsTest
// falhavam só na máquina de quem tinha publicado, e passavam na CI.
$envFileOverride = trim((string) (getenv('ECOLETA_ENV_FILE') ?: ''));
$envFile = $envFileOverride !== '' ? $envFileOverride : __DIR__ . '/env.php';

if ($envFileOverride !== 'none' && file_exists($envFile)) {
    require_once $envFile;
} else {
    // Sem arquivo de ambiente só restam as variáveis do processo. Nenhum segredo
    // tem valor embutido: o que não estiver definido faz o recurso falhar fechado.
    // As quatro constantes de conexão precisam existir de qualquer jeito —
    // getDbConnection() as lê direto, e constante ausente é fatal no PHP 8.
    if ($envFileOverride !== 'none') {
        error_log('public/api/env.php ausente — usando apenas variáveis de ambiente do servidor.');
    }
    if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'ecoleta');
    if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
    if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');
}

/**
 * DSN do PDO.
 *
 * O padrão é o MySQL da hospedagem, montado a partir de DB_HOST/DB_NAME. Um
 * `DB_DSN` definido — constante em env.php ou variável de ambiente — vence e é
 * usado como está: é o escape para o que não cabe no molde padrão (socket Unix,
 * porta fora da 3306, outro driver). DB_USER e DB_PASS continuam valendo, e
 * drivers sem autenticação simplesmente os ignoram.
 *
 * A suíte de `tests/php/` usa esse mesmo caminho para apontar os endpoints a um
 * SQLite descartável, sem precisar de um MySQL de pé.
 */
function apiDatabaseDsn(): string
{
    $dsn = apiSecret('DB_DSN');
    if ($dsn !== '') {
        return $dsn;
    }

    return 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
}

/**
 * Abre a conexão com o banco — e só isso.
 *
 * Até a versão anterior esta função também rodava, em TODA requisição, a criação
 * das cinco tabelas, a inspeção das colunas de `users`, uma alteração condicional
 * dessa tabela e o seed dos grupos padrão. O guard `static` que evitava a
 * repetição só valia dentro do processo PHP, que morre no fim do request,
 * então o custo se repetia sempre: metadata lock a cada chamada e permissão de
 * DDL obrigatória para o usuário MySQL da aplicação.
 *
 * Agora o schema é responsabilidade de db/migrations/, aplicadas por
 * db/migrate.php via SSH/CLI antes do upload dos arquivos. Se o banco estiver
 * atrás do código, a API responde 503 e explica o motivo no log — falhar rápido
 * em vez de tentar se auto-consertar.
 *
 * @param bool $requireCurrentSchema Deixe `false` apenas em ferramentas de
 *                                   manutenção que precisam conectar em um banco
 *                                   sabidamente defasado.
 */
function getDbConnection(bool $requireCurrentSchema = true): PDO {
    $dsn = apiDatabaseDsn();
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $db = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log('Falha na conexão com o banco de dados: ' . $e->getMessage());
        apiJsonResponse(500, ['error' => 'Falha na conexão com o banco de dados.']);
    }

    if ($requireCurrentSchema) {
        // Uma leitura barata em schema_migrations, uma vez por requisição.
        apiRequireCurrentSchema($db);
    }

    return $db;
}

function logActivity(
    PDO $db,
    ?int $userId,
    string $action,
    ?string $description = null,
    ?int $performedById = null,
    ?string $performedByLogin = null,
    ?string $targetLogin = null
): void {
    try {
        $ip = apiClientIp();
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);

        $stmt = $db->prepare("
            INSERT INTO activity_logs 
            (user_id, target_login, action, description, performed_by_id, performed_by_login, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $targetLogin,
            $action,
            $description,
            $performedById,
            $performedByLogin,
            $ip,
            $ua
        ]);
    } catch (\Throwable $e) {
        error_log("Failed to write activity log: " . $e->getMessage());
    }
}
