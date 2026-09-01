<?php
declare(strict_types=1);

/**
 * Banco descartável para a suíte: um arquivo SQLite por teste.
 *
 * Por que SQLite e não um MySQL de teste: o backend roda em hospedagem
 * compartilhada e a suíte precisa rodar na máquina de quem edita o código, sem
 * subir serviço nenhum. As consultas que os endpoints fazem no caminho testado
 * são portáveis (SELECT/INSERT/UPDATE com placeholders, e SQLite aceita as
 * crases de `groups`).
 *
 * O que NÃO é portável é o rate limit — `NOW()`, `TIMESTAMPDIFF` e
 * `ON DUPLICATE KEY UPDATE` só existem no MySQL. Isso é de propósito e está
 * coberto: as quatro funções de rate_limit.php falham abertas, então sob SQLite
 * o login continua funcionando com o throttle desligado, que é exatamente o
 * comportamento esperado quando a tabela de throttle está indisponível em
 * produção. Ver EndpointLoginTest::testLoginFuncionaComOThrottleIndisponivel().
 *
 * O schema abaixo é uma tradução de db/migrations/*.sql. SchemaMirrorTest
 * compara as colunas das duas versões e falha quando uma migration nova não é
 * refletida aqui.
 */
final class TestDatabase
{
    /**
     * Versão de schema que este espelho reproduz. Precisa acompanhar
     * ECOLETA_SCHEMA_VERSION — SchemaMirrorTest garante isso.
     */
    public const MIRRORED_VERSION = 12;

    private string $path;

    private ?PDO $pdo = null;

    public function __construct()
    {
        $file = tempnam(sys_get_temp_dir(), 'ecoleta_test_db_');
        if ($file === false) {
            throw new RuntimeException('Não consegui criar o arquivo do banco de teste.');
        }

        $this->path = $file;
        $this->migrate();
    }

    /** DSN para passar aos endpoints via a variável de ambiente DB_DSN. */
    public function dsn(): string
    {
        return 'sqlite:' . $this->path;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->dsn(), null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        return $this->pdo;
    }

    public function destroy(): void
    {
        $this->pdo = null;
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /**
     * Insere um usuário e devolve o id.
     *
     * A senha entra pelo mesmo `password_hash()` que a aplicação usa, com o
     * custo padrão do servidor: um hash fixo colado no teste passaria a ser
     * verificado com um custo diferente do de produção.
     */
    public function seedUser(
        string $login,
        string $password,
        string $role = 'user',
        ?string $email = null,
        ?int $groupId = null
    ): int {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO users (login, password_hash, email, role, group_id, force_password_change)
             VALUES (?, ?, ?, ?, ?, 0)'
        );
        $stmt->execute([
            $login,
            password_hash($password, PASSWORD_DEFAULT),
            $email ?? ($login . '@exemplo.com.br'),
            $role,
            $groupId,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function seedGroup(string $name, ?string $powerbiUrl = null): int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO `groups` (name, powerbi_url) VALUES (?, ?)');
        $stmt->execute([$name, $powerbiUrl]);

        return (int) $this->pdo()->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $table, string $orderBy = 'id'): array
    {
        $stmt = $this->pdo()->query('SELECT * FROM `' . $table . '` ORDER BY ' . $orderBy);

        return $stmt ? $stmt->fetchAll() : [];
    }

    public function count(string $table): int
    {
        $stmt = $this->pdo()->query('SELECT COUNT(*) AS total FROM `' . $table . '`');
        $row = $stmt ? $stmt->fetch() : null;

        return (int) ($row['total'] ?? 0);
    }

    /** Derruba uma tabela — usado para exercitar os caminhos de falha. */
    public function dropTable(string $table): void
    {
        $this->pdo()->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }

    /** @return list<string> */
    public function columnsOf(string $table): array
    {
        $stmt = $this->pdo()->query('PRAGMA table_info(`' . $table . '`)');
        $columns = [];
        foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
            $columns[] = (string) $row['name'];
        }
        sort($columns);

        return $columns;
    }

    private function migrate(): void
    {
        $pdo = $this->pdo();

        // 001_initial.sql
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            login TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            email TEXT NULL,
            role TEXT NOT NULL DEFAULT \'user\',
            group_id INTEGER NULL,
            force_password_change INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE access_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            ip_address TEXT NOT NULL,
            user_agent TEXT NULL,
            logged_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE activity_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            target_login TEXT NULL,
            action TEXT NOT NULL,
            description TEXT NULL,
            performed_by_id INTEGER NULL,
            performed_by_login TEXT NULL,
            ip_address TEXT NOT NULL,
            user_agent TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        // 002_groups.sql
        $pdo->exec('CREATE TABLE `groups` (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            powerbi_url TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        // 005_login_throttle.sql — criada para o erro sob SQLite ser o de
        // dialeto, e não o de tabela ausente.
        $pdo->exec('CREATE TABLE login_throttle (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL UNIQUE,
            scope TEXT NOT NULL,
            failures INTEGER NOT NULL DEFAULT 0,
            first_failure_at TEXT NOT NULL,
            last_failure_at TEXT NOT NULL,
            blocked_until TEXT NULL
        )');

        // 006_create_clients.sql + 011_client_billing_fields.sql
        $pdo->exec('CREATE TABLE clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NULL,
            whatsapp TEXT NULL,
            document TEXT NULL UNIQUE,
            monthly_value REAL NOT NULL DEFAULT 0,
            due_day INTEGER NOT NULL DEFAULT 10,
            status TEXT NOT NULL DEFAULT \'active\',
            asaas_customer_id TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        // 007_create_invoices.sql
        $pdo->exec('CREATE TABLE invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            asaas_payment_id TEXT NOT NULL UNIQUE,
            value REAL NOT NULL,
            due_date TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'PENDING\',
            invoice_url TEXT NULL,
            pix_qrcode_text TEXT NULL,
            pix_qrcode_url TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        // 008_create_service_orders.sql
        $pdo->exec('CREATE TABLE service_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            weight TEXT NULL,
            collection_date TEXT NULL,
            bags_count INTEGER NULL,
            containers_count INTEGER NULL,
            responsible TEXT NULL,
            signature_text TEXT NOT NULL DEFAULT \'Responsável Técnica - ECOLEVA\',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        // 009_create_site_content.sql — 004 e 010 são só seed, sem DDL novo.
        $pdo->exec('CREATE TABLE site_clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            logo_url TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE site_indicators (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            indicator_key TEXT NOT NULL UNIQUE,
            value TEXT NOT NULL,
            label TEXT NOT NULL,
            symbol_type TEXT NOT NULL DEFAULT \'text\',
            symbol_value TEXT NOT NULL,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE site_indicator_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            indicator_key TEXT NOT NULL,
            old_value TEXT NOT NULL,
            new_value TEXT NOT NULL,
            changed_by_id INTEGER NULL,
            changed_by_login TEXT NULL,
            ip_address TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        // Registro que public/api/schema.php lê a cada requisição. Sem ele todo
        // endpoint responderia 503 antes de chegar na regra sob teste.
        $pdo->exec('CREATE TABLE schema_migrations (
            version INTEGER PRIMARY KEY,
            filename TEXT NOT NULL,
            checksum TEXT NOT NULL,
            applied_at TEXT DEFAULT CURRENT_TIMESTAMP,
            duration_ms INTEGER NOT NULL DEFAULT 0
        )');

        $stmt = $pdo->prepare('INSERT INTO schema_migrations (version, filename, checksum) VALUES (?, ?, ?)');
        for ($version = 1; $version <= self::MIRRORED_VERSION; $version++) {
            $stmt->execute([$version, sprintf('%03d_teste.sql', $version), str_repeat('0', 64)]);
        }
    }
}
