<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MigrateEndpointTest extends TestCase
{
    private TestDatabase $db;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    /**
     * Número da próxima migration que o banco de teste ainda não conhece. Derivado do
     * espelho do schema: um número fixo (019) passou a colidir com a migration real
     * assim que ela existiu, e voltaria a colidir a cada migration nova.
     */
    private function proximaMigration(): string
    {
        return sprintf('%03d', TestDatabase::MIRRORED_VERSION + 1);
    }

    public function testRecusaSemToken(): void
    {
        $res = Endpoint::call('migrate.php', [
            'dsn' => $this->db->dsn(),
            'env' => ['CRON_SECRET' => 'segredo-forte-123456'],
        ]);

        self::assertSame(403, $res->status);
        self::assertFalse($res->json()['ok'] ?? true);
    }

    public function testRecusaComTokenInvalido(): void
    {
        $res = Endpoint::call('migrate.php', [
            'dsn' => $this->db->dsn(),
            'env' => ['CRON_SECRET' => 'segredo-forte-123456'],
            'server' => ['HTTP_X_CRON_SECRET' => 'segredo-errado'],
        ]);

        self::assertSame(403, $res->status);
    }

    public function testExecutaComTokenValidoViaHeader(): void
    {
        $res = Endpoint::call('migrate.php', [
            'dsn' => $this->db->dsn(),
            'env' => ['CRON_SECRET' => 'segredo-forte-123456'],
            'server' => ['HTTP_X_DEPLOY_TOKEN' => 'segredo-forte-123456'],
        ]);

        self::assertSame(200, $res->status, (string) $res->body);
        $json = $res->json();
        self::assertTrue($json['ok'] ?? false);
        self::assertArrayHasKey('current_version', $json);
    }

    /**
     * O segredo na query string vai para o log de acesso do servidor (e para o
     * histórico e o Referer de quem clicou). Só o cabeçalho é aceito: um segredo
     * certo em `?secret=` é recusado, e nenhuma migration roda.
     */
    public function testRecusaSegredoCertoNaQueryString(): void
    {
        $res = Endpoint::call('migrate.php', [
            'dsn' => $this->db->dsn(),
            'env' => ['CRON_SECRET' => 'segredo-forte-123456'],
            'query' => ['secret' => 'segredo-forte-123456'],
        ]);

        self::assertSame(403, $res->status, (string) $res->body);
        self::assertFalse($res->json()['ok'] ?? true);
    }

    public function testRecusaSegredoNaQueryMesmoComMigrationPendente(): void
    {
        $dir = sys_get_temp_dir() . '/ecoleta_mig_query_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/' . $this->proximaMigration() . '_via_query.sql', 'CREATE TABLE via_query (id INTEGER PRIMARY KEY);');

        try {
            $res = Endpoint::call('migrate.php', [
                'dsn' => $this->db->dsn(),
                'env' => ['CRON_SECRET' => 'segredo-forte-123456', 'ECOLETA_MIGRATIONS_DIR' => $dir],
                'query' => ['secret' => 'segredo-forte-123456'],
            ]);

            self::assertSame(403, $res->status, (string) $res->body);
            $tabelas = $this->db->pdo()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='via_query'")->fetchAll();
            self::assertCount(0, $tabelas, 'a migration rodou com o segredo na query string');
        } finally {
            @unlink($dir . '/' . $this->proximaMigration() . '_via_query.sql');
            @rmdir($dir);
        }
    }

    /** O cabeçalho X-Cron-Secret também continua valendo, como no deploy e no cron. */
    public function testExecutaComTokenValidoViaCabecalhoCronSecret(): void
    {
        $res = Endpoint::call('migrate.php', [
            'dsn' => $this->db->dsn(),
            'env' => ['CRON_SECRET' => 'segredo-forte-123456'],
            'server' => ['HTTP_X_CRON_SECRET' => 'segredo-forte-123456'],
        ]);

        self::assertSame(200, $res->status, (string) $res->body);
        self::assertTrue($res->json()['ok'] ?? false);
    }

    public function testExecutaNovaMigrationSeguraComSucesso(): void
    {
        $dir = sys_get_temp_dir() . '/ecoleta_mig_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/' . $this->proximaMigration() . '_teste_ping.sql', 'CREATE TABLE teste_ping (id INTEGER PRIMARY KEY, msg TEXT);');

        try {
            $res = Endpoint::call('migrate.php', [
                'dsn' => $this->db->dsn(),
                'env' => [
                    'CRON_SECRET' => 'segredo-forte-123456',
                    'ECOLETA_MIGRATIONS_DIR' => $dir,
                ],
                'server' => ['HTTP_X_DEPLOY_TOKEN' => 'segredo-forte-123456'],
            ]);

            self::assertSame(200, $res->status, (string) $res->body);
            $json = $res->json();
            self::assertTrue($json['ok'] ?? false);
            self::assertSame(1, $json['applied_count']);

            // Verifica que a tabela teste_ping foi criada no banco
            $tables = $this->db->pdo()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='teste_ping'")->fetchAll();
            self::assertCount(1, $tables);
        } finally {
            @unlink($dir . '/' . $this->proximaMigration() . '_teste_ping.sql');
            @rmdir($dir);
        }
    }

    public function testBloqueiaMigrationQueTentaAlterarSenhaDeUsuario(): void
    {
        $userId = $this->db->seedUser('alvo', 'SenhaOriginal123');
        $hashOriginal = $this->db->pdo()->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetchColumn();

        $dir = sys_get_temp_dir() . '/ecoleta_mig_unsafe_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/' . $this->proximaMigration() . '_reset_unsafe.sql', "UPDATE users SET password_hash = 'hackeado' WHERE id = {$userId};");

        try {
            $res = Endpoint::call('migrate.php', [
                'dsn' => $this->db->dsn(),
                'env' => [
                    'CRON_SECRET' => 'segredo-forte-123456',
                    'ECOLETA_MIGRATIONS_DIR' => $dir,
                ],
                'server' => ['HTTP_X_DEPLOY_TOKEN' => 'segredo-forte-123456'],
            ]);

            self::assertSame(500, $res->status, (string) $res->body);
            $json = $res->json();
            self::assertFalse($json['ok'] ?? true);
            self::assertStringContainsString('estritamente proibido', $json['error'] ?? '');

            // Garante que a senha do usuário NÃO foi alterada
            $hashAtual = $this->db->pdo()->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetchColumn();
            self::assertSame($hashOriginal, $hashAtual);
        } finally {
            @unlink($dir . '/' . $this->proximaMigration() . '_reset_unsafe.sql');
            @rmdir($dir);
        }
    }
}
