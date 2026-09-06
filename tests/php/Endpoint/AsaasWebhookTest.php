<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * O webhook do Asaas — a porta pela qual uma fatura vira "paga".
 *
 * Até esta versão o endpoint não autenticava nada: um POST de três linhas
 * (`{"event":"PAYMENT_RECEIVED","payment":{"id":"..."}}`) dava baixa em
 * qualquer cobrança para quem soubesse o id. O contrato agora é o token que o
 * próprio painel do Asaas envia no cabeçalho `asaas-access-token`.
 */
final class AsaasWebhookTest extends TestCase
{
    private const TOKEN = 'token-do-webhook-asaas-para-teste';

    private TestDatabase $db;

    private int $clientId;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        $this->clientId = $this->db->seedClient('Cliente Mensal', 250.0);
        $this->seedInvoice('pay_abc123', 'PENDING');
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    private function seedInvoice(string $paymentId, string $status): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices (client_id, asaas_payment_id, value, due_date, status) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$this->clientId, $paymentId, 250.0, '2026-10-10', $status]);
    }

    private function statusDaFatura(string $paymentId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM invoices WHERE asaas_payment_id = ?');
        $stmt->execute([$paymentId]);
        $valor = $stmt->fetchColumn();

        return is_string($valor) ? $valor : null;
    }

    /** @param array<string,mixed> $options */
    private function chamar(array $body, array $options = []): EndpointResponse
    {
        return Endpoint::call('webhooks/asaas.php', array_merge([
            'method' => 'POST',
            'dsn' => $this->db->dsn(),
            'body' => $body,
            'env' => ['ASAAS_WEBHOOK_TOKEN' => self::TOKEN],
            'server' => ['HTTP_ASAAS_ACCESS_TOKEN' => self::TOKEN],
        ], $options));
    }

    public function testEventoSemTokenNaoDaBaixaNaFatura(): void
    {
        $res = $this->chamar(
            ['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_abc123']],
            ['server' => []]
        );

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(401, $res->status, $res->body);
        self::assertSame('PENDING', $this->statusDaFatura('pay_abc123'));
    }

    public function testEventoComTokenErradoNaoDaBaixaNaFatura(): void
    {
        $res = $this->chamar(
            ['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_abc123']],
            ['server' => ['HTTP_ASAAS_ACCESS_TOKEN' => 'token-errado']]
        );

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(401, $res->status, $res->body);
        self::assertSame('PENDING', $this->statusDaFatura('pay_abc123'));
    }

    public function testServidorSemTokenConfiguradoRecusaTudo(): void
    {
        $res = $this->chamar(
            ['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_abc123']],
            ['env' => ['ASAAS_WEBHOOK_TOKEN' => '']]
        );

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(503, $res->status, $res->body);
        self::assertSame('PENDING', $this->statusDaFatura('pay_abc123'));
    }

    public function testPagamentoRecebidoMarcaAFaturaComoPaga(): void
    {
        $res = $this->chamar(['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_abc123']]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame('RECEIVED', $this->statusDaFatura('pay_abc123'));
    }

    public function testPagamentoConfirmadoTambemMarcaComoPaga(): void
    {
        $res = $this->chamar(['event' => 'PAYMENT_CONFIRMED', 'payment' => ['id' => 'pay_abc123']]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame('RECEIVED', $this->statusDaFatura('pay_abc123'));
    }

    public function testVencimentoMarcaComoOverdue(): void
    {
        $res = $this->chamar(['event' => 'PAYMENT_OVERDUE', 'payment' => ['id' => 'pay_abc123']]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame('OVERDUE', $this->statusDaFatura('pay_abc123'));
    }

    public function testEstornoRetiraAFaturaDaCobranca(): void
    {
        $this->chamar(['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_abc123']]);
        self::assertSame('RECEIVED', $this->statusDaFatura('pay_abc123'));

        $res = $this->chamar(['event' => 'PAYMENT_REFUNDED', 'payment' => ['id' => 'pay_abc123']]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame('REFUNDED', $this->statusDaFatura('pay_abc123'));
    }

    public function testEventoDesconhecidoNaoMudaNada(): void
    {
        $res = $this->chamar(['event' => 'PAYMENT_UPDATED', 'payment' => ['id' => 'pay_abc123']]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame('PENDING', $this->statusDaFatura('pay_abc123'));
    }

    public function testPagamentoDeOutraOrigemNaoDerrubaOWebhook(): void
    {
        $res = $this->chamar(['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_de_outro_sistema']]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame(0, (int) ($res->json()['updated'] ?? -1));
        self::assertSame('PENDING', $this->statusDaFatura('pay_abc123'));
    }

    public function testCorpoInvalidoResponde400(): void
    {
        $res = $this->chamar([], ['body' => 'isto não é json']);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
    }

    public function testMetodoGetNaoEAceito(): void
    {
        $res = $this->chamar([], ['method' => 'GET', 'body' => null]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(405, $res->status, $res->body);
    }
}
