<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * O cron de faturamento rodando o arquivo de verdade.
 *
 * Aqui fica só o que depende de executar o endpoint: a porta de entrada. A
 * regra de data e o documento do e-mail moram em `billing_lib.php` e são
 * exercitados por BillingLibTest — antes eram reimplementados dentro do próprio
 * teste, que por isso não notou que o e-mail saía com o botão do boleto vazio.
 */
final class BillingCronTest extends TestCase
{
    private const SECRET = 'segredo-de-cron-para-teste-1234567890';

    private TestDatabase $db;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    /** @param array<string,mixed> $options */
    private function chamar(array $options = []): EndpointResponse
    {
        return Endpoint::call('cron/billing.php', array_merge([
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'env' => ['CRON_SECRET' => self::SECRET, 'MAIL_TRANSPORT' => 'log'],
        ], $options));
    }

    public function testSemSegredoConfiguradoOCronNaoRoda(): void
    {
        // Falha fechado: um servidor sem CRON_SECRET não deve executar cobrança
        // nenhuma. A versão anterior caía em 'ecoleva_cron_secret', embutido no
        // código e publicado no repositório.
        $res = $this->chamar([
            'env' => ['CRON_SECRET' => '', 'MAIL_TRANSPORT' => 'log'],
            'query' => ['secret' => 'ecoleva_cron_secret'],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(503, $res->status, $res->body);
    }

    public function testSegredoAntigoEmbutidoNoCodigoNaoAbreMais(): void
    {
        $res = $this->chamar(['query' => ['secret' => 'ecoleva_cron_secret']]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(403, $res->status, $res->body);
    }

    public function testSemSegredoNaRequisicaoResponde403(): void
    {
        $res = $this->chamar(['query' => []]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(403, $res->status, $res->body);
        self::assertStringContainsString('Acesso negado', $res->body);
    }

    public function testSegredoErradoResponde403(): void
    {
        $res = $this->chamar(['query' => ['secret' => 'invalido']]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(403, $res->status, $res->body);
    }

    public function testSegredoCorretoNoCabecalhoRoda(): void
    {
        $res = $this->chamar(['server' => ['HTTP_X_CRON_SECRET' => self::SECRET]]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertStringContainsString('Cron rodou com sucesso', $res->body);
    }

    public function testSegredoCorretoNaQueryStringContinuaAceito(): void
    {
        // Compatibilidade com um cron já agendado como ?secret=...
        $res = $this->chamar(['query' => ['secret' => self::SECRET]]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertStringContainsString('Cron rodou com sucesso', $res->body);
    }

    public function testSemClienteCobravelNaoGeraFatura(): void
    {
        // Cliente inativo e cliente sem Asaas ficam de fora da emissão.
        $this->db->seedClient('Inativo', 100.0, 10, 'inactive', null, 'cus_1');
        $this->db->seedClient('Sem Asaas', 100.0, 10, 'active', null, null);

        $res = $this->chamar(['server' => ['HTTP_X_CRON_SECRET' => self::SECRET]]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame(0, $this->db->count('invoices'));
    }
}
