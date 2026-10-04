<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A edição dos indicadores do site (site/indicadores.php) rodando o arquivo real.
 * A leitura pública sem sessão está em SitePublicReadTest; aqui vai a escrita,
 * que só o administrador faz e que mexe em colunas VARCHAR(50) e VARCHAR(100).
 */
final class IndicadoresEndpointTest extends TestCase
{
    private TestDatabase $db;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        // A sessão aponta para o id 1: o servidor reconsulta o usuário a cada requisição,
        // então ele precisa existir no banco, com o papel que a sessão diz.
        $this->db->seedUser('admin', 'senha-root-123', 'root');
        $this->db->pdo()
            ->prepare('INSERT INTO site_indicators (indicator_key, value, label, symbol_type, symbol_value) VALUES (?, ?, ?, ?, ?)')
            ->execute(['pessoas', '300 Mil', 'Pessoas impactadas', 'icon', 'ImpactPeopleIcon']);
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    private function sessaoAdmin(): array
    {
        return ['user_id' => 1, 'role' => 'root', 'login' => 'admin'];
    }

    /** @param array<string,mixed>|string $body */
    private function salva(array|string $body, ?array $session = null): EndpointResponse
    {
        return Endpoint::call('site/indicadores.php', [
            'dsn' => $this->db->dsn(),
            'session' => $session ?? $this->sessaoAdmin(),
            'body' => $body,
        ]);
    }

    /** @return array<string,mixed> */
    private function indicador(): array
    {
        return $this->db->rows('site_indicators')[0];
    }

    public function testAdminAtualizaValorERotuloEGravaOHistorico(): void
    {
        $res = $this->salva(['key' => 'pessoas', 'value' => '350 Mil', 'label' => 'Pessoas atendidas']);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame('350 Mil', $this->indicador()['value']);
        self::assertSame('Pessoas atendidas', $this->indicador()['label']);
        $historico = $this->db->rows('site_indicator_history');
        self::assertCount(1, $historico);
        self::assertSame('300 Mil', $historico[0]['old_value']);
        self::assertSame('350 Mil', $historico[0]['new_value']);
    }

    public function testValorZeroEUmNumeroValido(): void
    {
        // "!$value" tratava a string "0" como vazia e devolvia "obrigatórios".
        $res = $this->salva(['key' => 'pessoas', 'value' => '0', 'label' => 'Pessoas impactadas']);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame('0', $this->indicador()['value']);
        self::assertSame('0', $this->db->rows('site_indicator_history')[0]['new_value']);
    }

    public function testRotuloZeroTambemNaoEConsideradoVazio(): void
    {
        $res = $this->salva(['key' => 'pessoas', 'value' => '10', 'label' => '0']);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame('0', $this->indicador()['label']);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function camposObrigatoriosVazios(): array
    {
        return [
            'sem chave' => [['value' => '1', 'label' => 'x']],
            'sem valor' => [['key' => 'pessoas', 'label' => 'x']],
            'sem rotulo' => [['key' => 'pessoas', 'value' => '1']],
            'valor vazio' => [['key' => 'pessoas', 'value' => '', 'label' => 'x']],
            'valor so com espacos' => [['key' => 'pessoas', 'value' => '   ', 'label' => 'x']],
            'rotulo so com espacos' => [['key' => 'pessoas', 'value' => '1', 'label' => "  \t "]],
        ];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('camposObrigatoriosVazios')]
    public function testCampoObrigatorioVazioDevolve400(array $body): void
    {
        $res = $this->salva($body);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertSame('300 Mil', $this->indicador()['value']);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function camposQueNaoSaoTexto(): array
    {
        return [
            'valor em lista' => [['key' => 'pessoas', 'value' => ['1'], 'label' => 'x']],
            'rotulo em lista' => [['key' => 'pessoas', 'value' => '1', 'label' => ['x']]],
            'chave em lista' => [['key' => ['pessoas'], 'value' => '1', 'label' => 'x']],
            'valor em objeto' => [['key' => 'pessoas', 'value' => ['a' => 1], 'label' => 'x']],
            'valor numerico' => [['key' => 'pessoas', 'value' => 300, 'label' => 'x']],
            'valor booleano' => [['key' => 'pessoas', 'value' => true, 'label' => 'x']],
        ];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('camposQueNaoSaoTexto')]
    public function testCampoQueNaoETextoDevolve400EmVezDeErroFatal(array $body): void
    {
        // trim() num array lançava TypeError fora do try: página de erro do PHP
        // em vez do JSON que a tela sabe mostrar.
        $res = $this->salva($body);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertNotSame('', (string) $res->error());
        self::assertSame('300 Mil', $this->indicador()['value']);
    }

    public function testCorpoQueNaoEJsonDeObjetoDevolve400(): void
    {
        foreach (['"texto"', '5', 'isto nao e json', '[]'] as $corpo) {
            $res = $this->salva($corpo);

            self::assertNull($res->fatal, (string) $res->fatal);
            self::assertSame(400, $res->status, "corpo {$corpo}: " . $res->body);
        }
    }

    public function testValorNoLimiteDe50CaracteresPassaEAcimaDisso400(): void
    {
        $noLimite = str_repeat('9', 50);
        $res = $this->salva(['key' => 'pessoas', 'value' => $noLimite, 'label' => 'x']);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame($noLimite, $this->indicador()['value']);

        $res = $this->salva(['key' => 'pessoas', 'value' => str_repeat('9', 51), 'label' => 'x']);
        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertStringContainsString('50', (string) $res->error());
        self::assertSame($noLimite, $this->indicador()['value']);
    }

    public function testRotuloNoLimiteDe100CaracteresPassaEAcimaDisso400(): void
    {
        $noLimite = str_repeat('r', 100);
        $res = $this->salva(['key' => 'pessoas', 'value' => '1', 'label' => $noLimite]);
        self::assertSame(200, $res->status, $res->body);

        $res = $this->salva(['key' => 'pessoas', 'value' => '1', 'label' => str_repeat('r', 101)]);
        self::assertSame(400, $res->status, $res->body);
        self::assertStringContainsString('100', (string) $res->error());
        self::assertSame($noLimite, $this->indicador()['label']);
    }

    public function testOLimiteContaCaracteresENaoBytes(): void
    {
        // As colunas são utf8mb4: "₂" pesa 3 bytes e conta como 1 caractere.
        $res = $this->salva(['key' => 'pessoas', 'value' => str_repeat('₂', 50), 'label' => str_repeat('ç', 100)]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
    }

    public function testChaveMuitoLongaNaoVira500(): void
    {
        $res = $this->salva(['key' => str_repeat('k', 51), 'value' => '1', 'label' => 'x']);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
    }

    public function testChaveInexistenteDevolve404(): void
    {
        $res = $this->salva(['key' => 'nao-existe', 'value' => '1', 'label' => 'x']);

        self::assertSame(404, $res->status, $res->body);
    }

    public function testEscritaExigePapelAdmin(): void
    {
        $res = $this->salva(
            ['key' => 'pessoas', 'value' => '1', 'label' => 'x'],
            ['user_id' => 2, 'role' => 'user', 'login' => 'joao']
        );

        self::assertSame(403, $res->status, $res->body);
        self::assertSame('300 Mil', $this->indicador()['value']);
    }
}
