<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/schema.php';

/**
 * Guarda contra a suíte e o banco de verdade se distanciarem.
 *
 * tests/php/Support/TestDatabase.php traduz db/migrations/*.sql para SQLite.
 * Tradução à mão apodrece: uma coluna nova entra na migration, a suíte segue
 * verde no espelho antigo e o teste deixa de dizer alguma coisa sobre produção.
 * Estes testes falham quando isso acontece — e o recado da falha é "atualize o
 * espelho", não "conserte a migration".
 */
final class SchemaMirrorTest extends TestCase
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

    public function testEspelhoAcompanhaAVersaoExigidaPeloCodigo(): void
    {
        self::assertSame(
            ECOLETA_SCHEMA_VERSION,
            TestDatabase::MIRRORED_VERSION,
            'db/migrations/ ganhou uma migration nova: reflita-a em tests/php/Support/TestDatabase.php e suba MIRRORED_VERSION'
        );
    }

    public function testVersaoExigidaBateComOsArquivosEmDisco(): void
    {
        $arquivos = glob(ECOLETA_ROOT . '/db/migrations/*.sql') ?: [];

        self::assertCount(
            ECOLETA_SCHEMA_VERSION,
            $arquivos,
            'ECOLETA_SCHEMA_VERSION precisa ser o maior número de migration em db/migrations/'
        );
    }

    /**
     * Toda coluna que as migrations declaram existe no espelho. A direção
     * inversa não é exigida: o espelho tem schema_migrations preenchida na mão,
     * que em produção é escrita por db/migrate.php e não por uma migration.
     */
    public function testTodaColunaDasMigrationsExisteNoEspelho(): void
    {
        foreach (self::colunasDasMigrations() as $tabela => $colunas) {
            $espelho = $this->db->columnsOf($tabela);

            self::assertNotEmpty($espelho, "a tabela `{$tabela}` não existe no espelho de teste");

            foreach ($colunas as $coluna) {
                self::assertContains(
                    $coluna,
                    $espelho,
                    "`{$tabela}`.`{$coluna}` está nas migrations mas não no espelho de tests/php/Support/TestDatabase.php"
                );
            }
        }
    }

    /**
     * Lê db/migrations/*.sql e devolve [tabela => [colunas]].
     *
     * @return array<string, list<string>>
     */
    private static function colunasDasMigrations(): array
    {
        $sql = '';
        foreach (glob(ECOLETA_ROOT . '/db/migrations/*.sql') ?: [] as $arquivo) {
            $sql .= (string) file_get_contents($arquivo) . "\n";
        }

        $tabelas = [];

        // CREATE TABLE [IF NOT EXISTS] `nome` ( ... );
        preg_match_all(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*?)\n\)/is',
            $sql,
            $criacoes,
            PREG_SET_ORDER
        );

        foreach ($criacoes as [, $tabela, $corpo]) {
            $colunas = [];
            foreach (preg_split('/,\s*\n/', $corpo) ?: [] as $linha) {
                $linha = trim($linha);
                if ($linha === '' || preg_match('/^(PRIMARY|UNIQUE|INDEX|KEY|CONSTRAINT|FOREIGN)\b/i', $linha)) {
                    continue;
                }
                if (preg_match('/^`?(\w+)`?\s+/', $linha, $m) === 1) {
                    $colunas[] = $m[1];
                }
            }
            $tabelas[$tabela] = $colunas;
        }

        // ALTER TABLE ... ADD COLUMN, inclusive o que 003 monta dentro de uma string.
        preg_match_all('/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+COLUMN\s+`?(\w+)`?/i', $sql, $alteracoes, PREG_SET_ORDER);
        foreach ($alteracoes as [, $tabela, $coluna]) {
            $tabelas[$tabela][] = $coluna;
        }

        return $tabelas;
    }
}
