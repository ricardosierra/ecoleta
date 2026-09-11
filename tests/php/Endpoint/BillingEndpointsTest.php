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

    private int $adminId;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        $grupoId = $this->db->seedGroup('Coleta');
        $this->userId = $this->db->seedUser('joao', 'senha-user-123', 'user', null, $grupoId);
        $this->adminId = $this->db->seedUser('maria', 'senha-admin-123', 'master');
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    /** Sessão de conta `user` — a que NÃO pode ver clientes nem faturas. */
    private function sessaoLogada(): array
    {
        return ['user_id' => $this->userId, 'role' => 'user', 'login' => 'joao'];
    }

    private function sessaoAdmin(): array
    {
        return ['user_id' => $this->adminId, 'role' => 'master', 'login' => 'maria'];
    }

    /**
     * Listagens de administrador.
     *
     * As três telas do módulo financeiro (clientes, faturas, OS) desenham
     * "Acesso negado." para quem não é `root`/`master`, e desde esta versão o
     * servidor cobra o mesmo — antes o backend aceitava qualquer sessão, então
     * uma conta `user` lia a carteira inteira e o histórico de cobranças por
     * fetch direto. `os/index.php` já exigia administrador desde a migration
     * 014, quando a resposta passou a carregar o link com token de cada OS;
     * quem cobre as duas pontas dele é ServiceOrderShareTest.
     *
     * @return array<string, array{0:string}>
     */
    public static function endpointsDeListagem(): array
    {
        return [
            'clientes' => ['clients/index.php'],
            'faturas' => ['invoices/index.php'],
        ];
    }

    public function testListagemDeOrdensDeServicoRecusaSessaoAusente(): void
    {
        $res = Endpoint::call('os/index.php', [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(403, $res->status, $res->body);
    }

    #[DataProvider('endpointsDeListagem')]
    public function testListagemRespondeSemFatalParaAdministrador(string $script): void
    {
        $res = Endpoint::call($script, [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
        ]);

        self::assertNull($res->fatal, "fatal de PHP em {$script}: {$res->fatal}");
        self::assertSame(200, $res->status, $res->body);
        self::assertTrue((bool) ($res->json()['ok'] ?? false), $res->body);
    }

    #[DataProvider('endpointsDeListagem')]
    public function testListagemRecusaContaComumMesmoLogada(string $script): void
    {
        $res = Endpoint::call($script, [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoLogada(),
        ]);

        self::assertNull($res->fatal, "fatal de PHP em {$script}: {$res->fatal}");
        self::assertSame(403, $res->status, $res->body);
    }

    #[DataProvider('endpointsDeListagem')]
    public function testListagemExigeSessao(string $script): void
    {
        $res = Endpoint::call($script, [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
        ]);

        self::assertNull($res->fatal, "fatal de PHP em {$script}: {$res->fatal}");
        self::assertSame(403, $res->status, $res->body);
    }

    public function testCadastroDeClienteRecusaContaComum(): void
    {
        $res = Endpoint::call('clients/index.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoLogada(),
            'body' => ['name' => 'Cliente Novo', 'monthly_value' => 100],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(403, $res->status, $res->body);
        self::assertSame(0, $this->db->count('clients'));
    }

    public function testEdicaoDeClienteRecusaContaComum(): void
    {
        $clientId = $this->db->seedClient('Cliente Mensal', 250.0);

        $res = Endpoint::call('clients/edit.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoLogada(),
            'body' => ['client_id' => $clientId, 'monthly_value' => 0, 'status' => 'inactive'],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(403, $res->status, $res->body);

        $rows = $this->db->rows('clients');
        self::assertSame(250.0, (float) $rows[0]['monthly_value']);
        self::assertSame('active', $rows[0]['status']);
    }

    public function testCriacaoDeClienteRecusaDiaDeVencimentoInvalido(): void
    {
        $res = Endpoint::call('clients/index.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['name' => 'Cliente Novo', 'monthly_value' => 100, 'due_day' => 40],
        ]);

        self::assertNull($res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertSame(0, $this->db->count('clients'));
    }

    public function testCadastroRecusaCobrancaMensalSemDocumento(): void
    {
        // O Asaas aceita o cliente sem CPF/CNPJ e recusa a COBRANÇA — recusa que
        // só apareceria no dia 30, dentro do try/catch do cron, no error_log.
        $res = Endpoint::call('clients/index.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['name' => 'Cliente Sem Documento', 'monthly_value' => 250, 'document' => ''],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertStringContainsString('CPF ou CNPJ', (string) ($res->json()['error'] ?? ''));
        self::assertSame(0, $this->db->count('clients'));
    }

    public function testCadastroSemCobrancaMensalNaoExigeDocumento(): void
    {
        // Cliente só de OS, sem mensalidade, não precisa de documento: nada vai
        // ao Asaas gerar cobrança para ele.
        $res = Endpoint::call('clients/index.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['name' => 'Cliente Avulso', 'monthly_value' => 0, 'document' => ''],
        ]);

        // Sem chave do Asaas a suíte para no cadastro remoto — o que importa é
        // que a recusa NÃO foi a do documento.
        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertStringNotContainsString('CPF ou CNPJ', (string) ($res->json()['error'] ?? ''));
    }

    public function testEdicaoRecusaLigarCobrancaEmClienteSemDocumento(): void
    {
        $clientId = $this->db->seedClient('Cliente Sem Documento');

        $res = Endpoint::call('clients/edit.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['client_id' => $clientId, 'monthly_value' => 300],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertSame(0.0, (float) $this->db->rows('clients')[0]['monthly_value']);
    }

    public function testEdicaoAtualizaVencimentoEStatus(): void
    {
        $clientId = $this->db->seedClient('Cliente Mensal', 250.0);

        $res = Endpoint::call('clients/edit.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
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
            'session' => $this->sessaoAdmin(),
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
            'session' => $this->sessaoAdmin(),
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
            'session' => $this->sessaoAdmin(),
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
            'session' => $this->sessaoAdmin(),
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
            'session' => $this->sessaoAdmin(),
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
            'session' => $this->sessaoAdmin(),
            'body' => ['client_id' => $clientId, 'whatsapp' => '5511888888888'],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(500, $res->status, $res->body);
        self::assertStringContainsString('Erro ao atualizar no Asaas: ASAAS_API_KEY não configurada.', (string) ($res->json()['error'] ?? ''));
    }

    public function testConsultaClientePorIdRetornaDadosDoCliente(): void
    {
        $clientId = $this->db->seedClient('Cliente Especifico', 350.0, 15, 'active', '5511999999999', null, '12345678901');

        $res = Endpoint::call('clients/index.php', [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'query' => ['id' => $clientId],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        $json = $res->json();
        self::assertTrue($json['ok'] ?? false);
        self::assertSame($clientId, (int) ($json['client']['id'] ?? 0));
        self::assertSame('Cliente Especifico', $json['client']['name'] ?? '');
        self::assertSame(350.0, (float) ($json['client']['monthly_value'] ?? 0));
        self::assertSame(15, (int) ($json['client']['due_day'] ?? 0));
    }

    public function testConsultaClientePorIdInexistenteRetorna404(): void
    {
        $res = Endpoint::call('clients/index.php', [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'query' => ['id' => 9999],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(404, $res->status, $res->body);
        self::assertFalse($res->json()['ok'] ?? true);
    }

    public function testCancelarFaturaRecusaContaComum(): void
    {
        $clientId = $this->db->seedClient('Cliente Cancelamento');
        $invoiceId = $this->db->seedInvoice($clientId, 'pay_test1');

        $res = Endpoint::call('invoices/index.php', [
            'method' => 'POST',
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoLogada(),
            'body' => ['action' => 'cancel', 'id' => $invoiceId],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(403, $res->status, $res->body);
    }

    public function testCancelarFaturaInexistenteRetorna404(): void
    {
        $res = Endpoint::call('invoices/index.php', [
            'method' => 'POST',
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'cancel', 'id' => 99999],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(404, $res->status, $res->body);
        self::assertStringContainsString('Fatura não encontrada.', (string) ($res->json()['error'] ?? ''));
    }

    public function testCancelarFaturaJaPagaRetorna400(): void
    {
        $clientId = $this->db->seedClient('Cliente Pago');
        $invoiceId = $this->db->seedInvoice($clientId, 'pay_pago', 100.0, '2026-10-10', 'RECEIVED');

        $res = Endpoint::call('invoices/index.php', [
            'method' => 'POST',
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'cancel', 'id' => $invoiceId],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertStringContainsString('Não é possível cancelar uma fatura já paga.', (string) ($res->json()['error'] ?? ''));
    }

    public function testCancelarFaturaPendenteAtualizaStatusParaDeleted(): void
    {
        $clientId = $this->db->seedClient('Cliente Cancel');
        // Invoice sem asaas_payment_id simula fatura sem ID de gateway para teste puro de status
        $invoiceId = $this->db->seedInvoice($clientId, '', 100.0, '2026-10-10', 'PENDING');

        $res = Endpoint::call('invoices/index.php', [
            'method' => 'POST',
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'cancel', 'id' => $invoiceId],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertTrue($res->json()['ok'] ?? false);

        $row = $this->db->rows('invoices', 'id')[0] ?? [];
        self::assertSame('DELETED', $row['status'] ?? '');
    }

    public function testCancelarFaturaComAsaasIdSemChaveRetorna502(): void
    {
        $clientId = $this->db->seedClient('Cliente Asaas');
        $invoiceId = $this->db->seedInvoice($clientId, 'pay_test_chave', 100.0, '2026-10-10', 'PENDING');

        $res = Endpoint::call('invoices/index.php', [
            'method' => 'POST',
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'cancel', 'id' => $invoiceId],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(502, $res->status, $res->body);
        self::assertStringContainsString('ASAAS_API_KEY não configurada.', (string) ($res->json()['error'] ?? ''));
    }
}

