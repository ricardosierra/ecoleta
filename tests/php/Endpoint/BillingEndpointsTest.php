<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Os módulos de clientes, OS e faturas rodando o caminho de verdade.
 *
 * Esta cobertura nasceu de um fatal em produção: os três módulos chamavam
 * apiRequireUser(), que nunca existiu em authz.php, e todo request morria em
 * 500 vazio — a suíte seguia verde porque nenhum teste executava os arquivos.
 * O contrato mínimo daqui é esse: o endpoint responde JSON, sem fatal de PHP.
 */
final class BillingEndpointsTest extends TestCase
{
    private TestDatabase $db;

    private int $userId;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        $grupoId = $this->db->seedGroup('Coleta');
        $this->userId = $this->db->seedUser('joao', 'senha-user-123', 'user', null, $grupoId);
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    private function sessaoLogada(): array
    {
        return ['user_id' => $this->userId, 'role' => 'user', 'login' => 'joao'];
    }

    /** @return array<string, array{0:string}> */
    public static function endpointsDeListagem(): array
    {
        return [
            'clientes' => ['clients/index.php'],
            'ordens de serviço' => ['os/index.php'],
            'faturas' => ['invoices/index.php'],
        ];
    }

    #[DataProvider('endpointsDeListagem')]
    public function testListagemRespondeSemFatalParaSessaoLogada(string $script): void
    {
        $res = Endpoint::call($script, [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoLogada(),
        ]);

        self::assertNull($res->fatal, "fatal de PHP em {$script}: {$res->fatal}");
        self::assertSame(200, $res->status, $res->body);
        self::assertTrue((bool) ($res->json()['ok'] ?? false), $res->body);
    }

    #[DataProvider('endpointsDeListagem')]
    public function testListagemExigeSessao(string $script): void
    {
        $res = Endpoint::call($script, [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
        ]);

        self::assertNull($res->fatal, "fatal de PHP em {$script}: {$res->fatal}");
        self::assertSame(401, $res->status, $res->body);
    }

    public function testCriacaoDeClienteRecusaDiaDeVencimentoInvalido(): void
    {
        $res = Endpoint::call('clients/index.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoLogada(),
            'body' => ['name' => 'Cliente Novo', 'monthly_value' => 100, 'due_day' => 40],
        ]);

        self::assertNull($res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertSame(0, $this->db->count('clients'));
    }

    public function testEdicaoAtualizaVencimentoEStatus(): void
    {
        $clientId = $this->db->seedClient('Cliente Mensal', 250.0);

        $res = Endpoint::call('clients/edit.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoLogada(),
            'body' => ['client_id' => $clientId, 'due_day' => 5, 'status' => 'inactive'],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);

        $rows = $this->db->rows('clients');
        self::assertSame(5, (int) $rows[0]['due_day']);
        self::assertSame('inactive', $rows[0]['status']);
        self::assertSame(250.0, (float) $rows[0]['monthly_value'], 'campo não enviado deve ficar como está');
    }

    public function testEdicaoDeClienteInexistenteResponde404(): void
    {
        $res = Endpoint::call('clients/edit.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoLogada(),
            'body' => ['client_id' => 999],
        ]);

        self::assertNull($res->fatal);
        self::assertSame(404, $res->status, $res->body);
    }

    public function testEdicaoRecusaStatusDesconhecido(): void
    {
        $clientId = $this->db->seedClient('Cliente Mensal');

        $res = Endpoint::call('clients/edit.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoLogada(),
            'body' => ['client_id' => $clientId, 'status' => 'pausado'],
        ]);

        self::assertNull($res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertSame('active', $this->db->rows('clients')[0]['status']);
    }

    public function testEdicaoAtualizaWhatsappLocalmenteQuandoClienteNaoPossuiAsaasId(): void
    {
        $clientId = $this->db->seedClient('Cliente Local', 150.0, 10, 'active', '5511999999999', null);

        $res = Endpoint::call('clients/edit.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoLogada(),
            'body' => ['client_id' => $clientId, 'whatsapp' => '5511888888888'],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame('5511888888888', $res->json()['client']['whatsapp'] ?? null);

        $rows = $this->db->rows('clients');
        self::assertSame('5511888888888', $rows[0]['whatsapp']);
    }

    public function testEdicaoNaoChamaAsaasSeWhatsappNaoFoiAlterado(): void
    {
        $clientId = $this->db->seedClient('Cliente Asaas', 150.0, 10, 'active', '5511999999999', 'cus_test123');

        $res = Endpoint::call('clients/edit.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoLogada(),
            'body' => ['client_id' => $clientId, 'whatsapp' => '5511999999999'],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame('5511999999999', $res->json()['client']['whatsapp'] ?? null);
    }

    public function testEdicaoNaoChamaAsaasSeWhatsappNaoFoiEnviado(): void
    {
        $clientId = $this->db->seedClient('Cliente Asaas', 150.0, 10, 'active', '5511999999999', 'cus_test123');

        $res = Endpoint::call('clients/edit.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoLogada(),
            'body' => ['client_id' => $clientId, 'due_day' => 20],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame(20, (int) ($res->json()['client']['due_day'] ?? 0));
        self::assertSame('5511999999999', $res->json()['client']['whatsapp'] ?? null);
    }

    public function testEdicaoDisparaAtualizacaoNoAsaasQuandoWhatsappMudaEClientePossuiAsaasId(): void
    {
        $clientId = $this->db->seedClient('Cliente Asaas', 150.0, 10, 'active', '5511999999999', 'cus_test123');

        $res = Endpoint::call('clients/edit.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoLogada(),
            'body' => ['client_id' => $clientId, 'whatsapp' => '5511888888888'],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(500, $res->status, $res->body);
        self::assertStringContainsString('Erro ao atualizar no Asaas: ASAAS_API_KEY não configurada.', (string) ($res->json()['error'] ?? ''));
    }
}
