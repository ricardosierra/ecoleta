<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return array{due_date:string,value:float} */
    private function dadosDaFatura(string $paymentId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT due_date, value FROM invoices WHERE asaas_payment_id = ?');
        $stmt->execute([$paymentId]);
        $linha = $stmt->fetch();

        return ['due_date' => (string) $linha['due_date'], 'value' => (float) $linha['value']];
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
        $res = $this->chamar(['event' => 'PAYMENT_CHARGEBACK_DISPUTE', 'payment' => ['id' => 'pay_abc123']]);

        self::assertSame(200, $res->status, $res->body);
        self::assertTrue((bool) ($res->json()['ignored'] ?? false), $res->body);
        self::assertSame('PENDING', $this->statusDaFatura('pay_abc123'));
    }

    // ── Transições: a ordem dos eventos não pode desfazer o que já foi pago ──

    /**
     * O Asaas não garante a ordem de entrega. PAYMENT_RECEIVED seguido de um
     * PAYMENT_OVERDUE atrasado devolvia a fatura paga para OVERDUE, e o cron
     * passava a lembrar quem já tinha pago.
     */
    public function testVencimentoAtrasadoDepoisDoPagamentoNaoReabreAFaturaPaga(): void
    {
        $this->chamar(['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_abc123']]);
        self::assertSame('RECEIVED', $this->statusDaFatura('pay_abc123'));

        $res = $this->chamar(['event' => 'PAYMENT_OVERDUE', 'payment' => ['id' => 'pay_abc123']]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame('RECEIVED', $this->statusDaFatura('pay_abc123'));
        self::assertSame(0, (int) ($res->json()['updated'] ?? -1));
        self::assertTrue((bool) ($res->json()['ignored'] ?? false), 'o evento recusado precisa ficar visível na resposta');
        self::assertStringContainsString('PAYMENT_OVERDUE ignorado', $res->errorLog);
    }

    /** @return array<string, array{0:string}> */
    public static function statusEncerrados(): array
    {
        return [
            'paga' => ['RECEIVED'],
            'confirmada' => ['CONFIRMED'],
            'estornada' => ['REFUNDED'],
            'contestada' => ['CHARGEBACK_REQUESTED'],
            // Fatura cancelada também não ressuscita: voltaria para a régua de lembretes.
            'cancelada' => ['DELETED'],
        ];
    }

    #[DataProvider('statusEncerrados')]
    public function testEventoDeAtrasoNaoMudaStatusEncerrado(string $statusAtual): void
    {
        $this->seedInvoice('pay_encerrada', $statusAtual);

        $res = $this->chamar(['event' => 'PAYMENT_OVERDUE', 'payment' => ['id' => 'pay_encerrada']]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame($statusAtual, $this->statusDaFatura('pay_encerrada'));
    }

    public function testVencimentoRepetidoNaFaturaJaVencidaContinuaVencida(): void
    {
        $this->seedInvoice('pay_vencida', 'OVERDUE');

        $res = $this->chamar(['event' => 'PAYMENT_OVERDUE', 'payment' => ['id' => 'pay_vencida']]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame('OVERDUE', $this->statusDaFatura('pay_vencida'));
    }

    public function testPagamentoDeFaturaVencidaMarcaComoPaga(): void
    {
        $this->seedInvoice('pay_vencida', 'OVERDUE');

        $res = $this->chamar(['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_vencida']]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame('RECEIVED', $this->statusDaFatura('pay_vencida'));
    }

    // ── PAYMENT_RESTORED e PAYMENT_RECEIVED_IN_CASH_UNDONE ───────────────────

    public function testCobrancaRestauradaVoltaParaPendente(): void
    {
        $this->seedInvoice('pay_cancelada', 'DELETED');

        $res = $this->chamar(['event' => 'PAYMENT_RESTORED', 'payment' => ['id' => 'pay_cancelada']]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame('PENDING', $this->statusDaFatura('pay_cancelada'));
    }

    public function testRestauracaoSoValeParaFaturaCancelada(): void
    {
        $this->seedInvoice('pay_paga', 'RECEIVED');

        $res = $this->chamar(['event' => 'PAYMENT_RESTORED', 'payment' => ['id' => 'pay_paga']]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame('RECEIVED', $this->statusDaFatura('pay_paga'));
    }

    public function testRecebimentoEmDinheiroDesfeitoVoltaParaPendente(): void
    {
        $this->seedInvoice('pay_dinheiro', 'RECEIVED');

        $res = $this->chamar(['event' => 'PAYMENT_RECEIVED_IN_CASH_UNDONE', 'payment' => ['id' => 'pay_dinheiro']]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame('PENDING', $this->statusDaFatura('pay_dinheiro'));
    }

    /** @return array<string, array{0:string}> */
    public static function statusQueNaoSaoRecebimento(): array
    {
        return [
            'pendente' => ['PENDING'],
            'vencida' => ['OVERDUE'],
            'estornada' => ['REFUNDED'],
            'cancelada' => ['DELETED'],
        ];
    }

    #[DataProvider('statusQueNaoSaoRecebimento')]
    public function testDesfazerRecebimentoSoValeParaFaturaPaga(string $statusAtual): void
    {
        $this->seedInvoice('pay_outra', $statusAtual);

        $res = $this->chamar(['event' => 'PAYMENT_RECEIVED_IN_CASH_UNDONE', 'payment' => ['id' => 'pay_outra']]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame($statusAtual, $this->statusDaFatura('pay_outra'));
    }

    // ── PAYMENT_UPDATED: vencimento e valor ──────────────────────────────────

    public function testCobrancaAtualizadaSincronizaVencimentoEValorDaFaturaPendente(): void
    {
        $res = $this->chamar([
            'event' => 'PAYMENT_UPDATED',
            'payment' => ['id' => 'pay_abc123', 'dueDate' => '2026-10-25', 'value' => 300.5],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame(['due_date' => '2026-10-25', 'value' => 300.5], $this->dadosDaFatura('pay_abc123'));
        self::assertSame('PENDING', $this->statusDaFatura('pay_abc123'), 'a atualização não mexe no status');
    }

    public function testCobrancaAtualizadaSincronizaFaturaVencida(): void
    {
        $this->seedInvoice('pay_vencida', 'OVERDUE');

        $res = $this->chamar([
            'event' => 'PAYMENT_UPDATED',
            'payment' => ['id' => 'pay_vencida', 'dueDate' => '2026-11-05', 'value' => 260],
        ]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame(['due_date' => '2026-11-05', 'value' => 260.0], $this->dadosDaFatura('pay_vencida'));
        self::assertSame('OVERDUE', $this->statusDaFatura('pay_vencida'));
    }

    #[DataProvider('statusEncerrados')]
    public function testCobrancaAtualizadaNaoMexeEmFaturaEncerrada(string $status): void
    {
        $this->seedInvoice('pay_encerrada', $status);

        $res = $this->chamar([
            'event' => 'PAYMENT_UPDATED',
            'payment' => ['id' => 'pay_encerrada', 'dueDate' => '2030-01-01', 'value' => 999],
        ]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame(['due_date' => '2026-10-10', 'value' => 250.0], $this->dadosDaFatura('pay_encerrada'));
        self::assertSame($status, $this->statusDaFatura('pay_encerrada'));
    }

    /**
     * Vale os dois campos ou nenhum: o Asaas manda o objeto de cobrança inteiro, e
     * um payload com só metade dele indica que algo está fora do contrato.
     *
     * @return array<string, array{0:array<string,mixed>}>
     */
    public static function atualizacoesInvalidas(): array
    {
        return [
            'data lixo' => [['dueDate' => 'amanha', 'value' => 300]],
            'data impossivel' => [['dueDate' => '2026-02-31', 'value' => 300]],
            'sem data' => [['value' => 300]],
            'valor negativo' => [['dueDate' => '2026-10-25', 'value' => -3]],
            'valor zero' => [['dueDate' => '2026-10-25', 'value' => 0]],
            'valor texto' => [['dueDate' => '2026-10-25', 'value' => 'muito']],
            'valor que nao cabe na coluna' => [['dueDate' => '2026-10-25', 'value' => 1e12]],
            'sem valor' => [['dueDate' => '2026-10-25']],
            'sem nada' => [[]],
        ];
    }

    /** @param array<string,mixed> $campos */
    #[DataProvider('atualizacoesInvalidas')]
    public function testCobrancaAtualizadaIgnoraPayloadInvalido(array $campos): void
    {
        $res = $this->chamar(['event' => 'PAYMENT_UPDATED', 'payment' => ['id' => 'pay_abc123'] + $campos]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame(['due_date' => '2026-10-10', 'value' => 250.0], $this->dadosDaFatura('pay_abc123'));
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
