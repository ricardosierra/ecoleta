<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once ECOLETA_API_DIR . '/billing_delivery.php';

final class BillingDeliveryTest extends TestCase
{
    private TestDatabase $db;
    protected function setUp(): void { $this->db = new TestDatabase(); }
    protected function tearDown(): void { $this->db->destroy(); }
    private function client(): array {
        $id = $this->db->seedClient('Teste', 25, 10, 'active', 'teste@example.com', 'cus_test');
        return ['id'=>$id, 'name'=>'Teste', 'document'=>'12345678909', 'status'=>'active', 'asaas_customer_id'=>'cus_test', 'email'=>'teste@example.com', 'whatsapp'=>''];
    }
    public function testPixFailurePreservesInvoiceAndRetryDoesNotChargeAgain(): void {
        $client=$this->client(); $calls=[];
        $request=function($path,$method,$body) use (&$calls) {
            $calls[]=$path;
            if (str_contains($path,'pixQrCode')) throw new RuntimeException('Pix indisponível');
            if ($path==='/payments') return ['id'=>'pay_test','invoiceUrl'=>'https://example.com/invoice'];
            return ['data'=>[]];
        };
        $first=billingIssueInvoice($this->db->pdo(),$client,25,'2026-10-10',$request);
        $second=billingIssueInvoice($this->db->pdo(),$client,25,'2026-10-10',$request);
        self::assertTrue($first['created']); self::assertFalse($second['created']);
        self::assertSame(1,$this->db->count('invoices')); self::assertCount(3,$calls);
        self::assertNull($first['pix_qrcode_url']);
    }
    public function testInterruptedPaymentIsRecoveredByExternalReference(): void {
        $client=$this->client();
        $request=function($path,$method,$body) {
            self::assertSame('GET',$method);
            if (str_contains($path,'pixQrCode')) return ['payload'=>'pix-test','encodedImage'=>str_repeat('a',10000)];
            self::assertStringContainsString('externalReference=',$path);
            return ['data'=>[['id'=>'pay_existing','invoiceUrl'=>'https://example.com/invoice','value'=>32.5,'dueDate'=>'2026-10-10']]];
        };
        $invoice=billingIssueInvoice($this->db->pdo(),$client,25,'2026-10-10',$request);
        self::assertSame('pay_existing',$invoice['asaas_payment_id']);
        self::assertSame(32.5,(float)$invoice['value']);
        self::assertSame('pix-test',$invoice['pix_qrcode_text']);
        self::assertNull($invoice['pix_qrcode_url']);
    }
    public function testFailedEmailIsRetriedAndSuccessfulEmailIsNotRepeated(): void {
        $client=$this->client();
        $invoice=['id'=>42,'status'=>'PENDING','value'=>25,'due_date'=>'2026-10-10','invoice_url'=>'https://example.com/invoice'];
        $attempts=0;
        $mail=function() use (&$attempts) { return ++$attempts>1; };
        $first=billingDeliverInvoice($this->db->pdo(),$invoice,$client,'new',$mail);
        $second=billingDeliverInvoice($this->db->pdo(),$invoice,$client,'new',$mail);
        $third=billingDeliverInvoice($this->db->pdo(),$invoice,$client,'new',$mail);
        self::assertSame('failed',$first['email']); self::assertNotEmpty($first['errors']);
        self::assertSame('sent',$second['email']); self::assertSame('already_sent',$third['email']);
        self::assertSame(2,$attempts);
    }
    public function testCanceledInvoiceIsNotSent(): void {
        $client=$this->client();
        $result=billingDeliverInvoice($this->db->pdo(),['status'=>'DELETED'],$client,'new',function(){self::fail('Canceled invoice sent');});
        self::assertSame('skipped',$result['email']);
    }
    public function testTextInsideWindowIncludesInvoiceDetails(): void {
        $payload=billingWhatsAppPayload('5521999999999','Cliente',['value'=>5,'due_date'=>'2026-09-10','invoice_url'=>'https://example.com'],'Fatura teste',true);
        self::assertSame('text',$payload['type']); self::assertSame('Fatura teste',$payload['text']['body']);
    }

    // ── O ciclo diário do faturamento automático ─────────────────────────────

    /** Cliente pronto para ser faturado: ativo, com documento, Asaas e e-mail. */
    private function cycleClient(string $name, float $value, int $dueDay, ?string $email = 'cliente@example.com', ?string $document = '11144477735', string $status = 'active', ?string $asaasId = 'cus_ok'): int
    {
        return $this->db->seedClient($name, $value, $dueDay, $status, null, $asaasId, $email, $document);
    }

    /** Asaas de mentira: cada cobrança criada recebe um id novo e o Pix volta preenchido. */
    private function fakeAsaas(array &$calls): callable
    {
        $created = 0;
        return function (string $path, string $method, array $body) use (&$calls, &$created) {
            $calls[] = $method . ' ' . strtok($path, '?');
            if ($method === 'GET' && str_contains($path, 'pixQrCode')) return ['payload' => 'pix-copia-e-cola'];
            if ($method === 'GET') return ['data' => []];
            $created++;
            return ['id' => 'pay_cycle_' . $created, 'invoiceUrl' => 'https://example.com/i/' . $created, 'value' => $body['value'], 'dueDate' => $body['dueDate'], 'status' => 'PENDING'];
        };
    }

    private function recordingMail(array &$sent): callable
    {
        return function (string $to, string $subject) use (&$sent): bool {
            $sent[] = $subject;
            return true;
        };
    }

    private function neverWhatsapp(): callable
    {
        return function () { self::fail('Cliente sem WhatsApp não deve acionar a Meta.'); };
    }

    /** Fixa o created_at da fatura: o relógio da suíte é simulado, o do banco é o real. */
    private function stampInvoices(string $createdAt): void
    {
        $this->db->pdo()->prepare('UPDATE invoices SET created_at = ?')->execute([$createdAt]);
    }

    private function runCycle(string $day, callable $request, callable $mail): array
    {
        return billingRunCycle($this->db->pdo(), new DateTimeImmutable($day), $request, $mail, $this->neverWhatsapp());
    }

    public function testClienteCadastradoNoDia3RecebeAFaturaDoDia5ESoUma(): void
    {
        // O relato da cliente: cadastrou Café Preto (R$ 170, dia 5) e o boleto não chegou.
        // Dia 3 também é dia de lembrete, e a fatura recém-emitida não pode sair duas vezes.
        $this->cycleClient('Café Preto', 170.0, 5);
        $calls = []; $sent = [];

        $first = $this->runCycle('2026-10-03', $this->fakeAsaas($calls), $this->recordingMail($sent));
        $this->stampInvoices('2026-10-03 03:00:00');
        $second = $this->runCycle('2026-10-03', $this->fakeAsaas($calls), $this->recordingMail($sent));

        self::assertSame(1, $first['generated']);
        self::assertSame(0, $first['reminders']);
        self::assertSame([], $first['errors']);
        $invoices = $this->db->rows('invoices');
        self::assertCount(1, $invoices);
        self::assertSame('2026-10-05', $invoices[0]['due_date']);
        self::assertSame('pix-copia-e-cola', $invoices[0]['pix_qrcode_text']);
        self::assertSame(['Sua Fatura Mensal - Ecoleva'], $sent, 'a segunda execução do dia não pode reenviar');
        self::assertSame(0, $second['generated']);
        self::assertCount(1, array_keys(array_filter($calls, fn (string $c) => str_starts_with($c, 'POST'))));
    }

    public function testNoDia30EmiteOMesSeguinteEEmDiaDeLembreteRelembraOQueJaExistia(): void
    {
        $this->cycleClient('Mensal', 600.0, 5);
        $calls = []; $sent = [];

        $this->runCycle('2026-09-30', $this->fakeAsaas($calls), $this->recordingMail($sent));
        $this->stampInvoices('2026-09-30 11:00:00');
        $reminder = $this->runCycle('2026-10-03', $this->fakeAsaas($calls), $this->recordingMail($sent));

        $invoices = $this->db->rows('invoices');
        self::assertCount(1, $invoices);
        self::assertSame('2026-10-05', $invoices[0]['due_date']);
        self::assertSame(1, $reminder['reminders']);
        self::assertSame(['Sua Fatura Mensal - Ecoleva', 'Lembrete de Fatura - Ecoleva'], $sent);
    }

    public function testExecucaoPerdidaNoDia30ERecuperadaNoDiaSeguinte(): void
    {
        $this->cycleClient('Mensal', 600.0, 5);
        $calls = []; $sent = [];

        // O cron não rodou em 30/09. Em 01/10 o vencimento de 05/10 ainda é emitido.
        $result = $this->runCycle('2026-10-01', $this->fakeAsaas($calls), $this->recordingMail($sent));

        self::assertSame(1, $result['generated']);
        self::assertSame('2026-10-05', $this->db->rows('invoices')[0]['due_date']);
    }

    public function testMesSeguinteEmitidoNoDia30EsoNoDia30(): void
    {
        $this->cycleClient('Mensal', 600.0, 5);
        $calls = []; $sent = [];

        $this->runCycle('2026-10-29', $this->fakeAsaas($calls), $this->recordingMail($sent));
        self::assertSame(0, $this->db->count('invoices'), 'dia 29: o vencimento de 05/10 já passou e novembro ainda não abriu');

        $this->runCycle('2026-10-30', $this->fakeAsaas($calls), $this->recordingMail($sent));
        $this->runCycle('2026-10-31', $this->fakeAsaas($calls), $this->recordingMail($sent));

        $invoices = $this->db->rows('invoices');
        self::assertCount(1, $invoices, 'dia 30 e dia 31 não podem emitir duas vezes');
        self::assertSame('2026-11-05', $invoices[0]['due_date']);
    }

    public function testFaturaAvulsaDoMesImpedeACobrancaDuplicada(): void
    {
        // A operadora gerou à mão uma fatura para 04/10; o vencimento cadastrado é dia 5.
        $clientId = $this->cycleClient('Avulsa', 170.0, 5);
        $this->db->seedInvoice($clientId, 'pay_avulsa', 170.0, '2026-10-04');
        $calls = []; $sent = [];

        $result = $this->runCycle('2026-10-02', $this->fakeAsaas($calls), $this->recordingMail($sent));

        self::assertSame(0, $result['generated']);
        self::assertSame(1, $this->db->count('invoices'));
        self::assertSame([], array_filter($calls, fn (string $c) => str_starts_with($c, 'POST')));
    }

    public function testTrocaDeDiaDeVencimentoNoMeioDoMesNaoCobraDuasVezes(): void
    {
        $clientId = $this->cycleClient('Mudou o dia', 170.0, 20);
        $this->db->seedInvoice($clientId, 'pay_dia5', 170.0, '2026-10-05');
        $calls = []; $sent = [];

        $this->runCycle('2026-10-03', $this->fakeAsaas($calls), $this->recordingMail($sent));

        self::assertSame(1, $this->db->count('invoices'));
    }

    public function testFaturaCanceladaDePropositoNaoEhRecriadaPeloCiclo(): void
    {
        $clientId = $this->cycleClient('Cancelada', 170.0, 5);
        $this->db->seedInvoice($clientId, 'pay_cancelada', 170.0, '2026-10-05', 'DELETED');
        $calls = []; $sent = [];

        $result = $this->runCycle('2026-10-03', $this->fakeAsaas($calls), $this->recordingMail($sent));

        self::assertSame(0, $result['generated']);
        self::assertSame(1, $this->db->count('invoices'));
        self::assertSame([], $sent);
    }

    public function testClienteInativoEClienteSemValorFicamDeFora(): void
    {
        $this->cycleClient('Inativo', 100.0, 5, 'a@example.com', '52998224725', 'inactive');
        $this->cycleClient('Sem mensalidade', 0.0, 5, 'b@example.com', '39053344705');
        $calls = []; $sent = [];

        $result = $this->runCycle('2026-10-03', $this->fakeAsaas($calls), $this->recordingMail($sent));

        self::assertSame(0, $result['clients']);
        self::assertSame(0, $this->db->count('invoices'));
    }

    public function testCadastroIncompletoViraErroUmaVezEOsOutrosClientesSeguem(): void
    {
        // Dia 31 de vencimento no dia 30 tem DOIS vencimentos-alvo; o erro de cadastro
        // aparece uma vez só, e o cliente seguinte é faturado normalmente.
        $semDocumento = $this->cycleClient('Sem documento', 100.0, 31, 'x@example.com', null);
        $completo = $this->cycleClient('Completo', 100.0, 31);
        $calls = []; $sent = [];

        $result = $this->runCycle('2026-09-30', $this->fakeAsaas($calls), $this->recordingMail($sent));

        self::assertCount(1, $result['errors']);
        self::assertSame($semDocumento, $result['errors'][0]['client_id']);
        self::assertStringContainsString('CPF/CNPJ', $result['errors'][0]['error']);
        self::assertSame(2, $result['generated']);
        $dueDates = array_column($this->db->rows('invoices', 'due_date'), 'due_date');
        self::assertSame(['2026-09-30', '2026-10-31'], $dueDates);
        self::assertSame($completo, (int) $this->db->rows('invoices')[0]['client_id']);
    }

    public function testValorAbaixoDoMinimoDoAsaasApareceNoRetornoDoCiclo(): void
    {
        $this->cycleClient('Valor baixo', 3.0, 5);
        $calls = []; $sent = [];

        $result = $this->runCycle('2026-10-03', $this->fakeAsaas($calls), $this->recordingMail($sent));

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('R$ 5,00', $result['errors'][0]['error']);
    }

    public function testFalhaDoAsaasEhRepetidaNaProximaExecucao(): void
    {
        $this->cycleClient('Asaas fora', 170.0, 5);
        $sent = [];
        $fora = function (): array { throw new RuntimeException('Erro na comunicação com o Asaas.'); };

        $first = $this->runCycle('2026-10-02', $fora, $this->recordingMail($sent));
        self::assertSame(0, $first['generated']);
        self::assertCount(1, $first['errors']);
        self::assertSame(0, $this->db->count('invoices'));

        $calls = [];
        $second = $this->runCycle('2026-10-03', $this->fakeAsaas($calls), $this->recordingMail($sent));
        self::assertSame(1, $second['generated']);
        self::assertSame([], $second['errors']);
    }

    public function testClienteSemEmailNemWhatsappGeraAvisoEmVezDeSilencio(): void
    {
        $this->cycleClient('Sem contato', 170.0, 5, null);
        $calls = []; $sent = [];

        $result = $this->runCycle('2026-10-03', $this->fakeAsaas($calls), $this->recordingMail($sent));

        self::assertSame(1, $result['generated']);
        self::assertSame([], $sent);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('ninguém foi avisado', $result['errors'][0]['delivery']['errors']['destino']);
    }

    public function testExecucaoFicaRegistradaEPodeSerLida(): void
    {
        $pdo = $this->db->pdo();
        self::assertNull(billingLastRun($pdo), 'sem execução registrada, a tela precisa saber que nunca rodou');

        billingRecordRun($pdo, ['generated' => 2, 'reminders' => 1, 'errors' => [['client_id' => 9]]]);
        $run = billingLastRun($pdo);

        self::assertSame(2, $run['generated']);
        self::assertSame(1, $run['reminders']);
        self::assertSame(1, $run['errors']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $run['at']);
        self::assertEqualsWithDelta(time(), strtotime($run['at']), 5);
    }

    // ── Cancelar e gerar de novo ─────────────────────────────────────────────

    public function testFaturaCanceladaPodeSerRecriadaParaOMesmoVencimento(): void
    {
        $client = $this->client();
        $calls = [];
        $request = $this->fakeAsaas($calls);

        $first = billingIssueInvoice($this->db->pdo(), $client, 25, '2026-10-10', $request);
        $this->db->pdo()->exec("UPDATE invoices SET status = 'DELETED' WHERE id = " . (int) $first['id']);
        $second = billingIssueInvoice($this->db->pdo(), $client, 25, '2026-10-10', $request);

        self::assertTrue($second['created']);
        self::assertNotSame($first['asaas_payment_id'], $second['asaas_payment_id']);
        self::assertSame('PENDING', $second['status']);
        self::assertSame(2, $this->db->count('invoices'));
    }

    public function testCobrancaCanceladaQueVoltaPelaReferenciaNaoEhReaproveitada(): void
    {
        $client = $this->client();
        $calls1 = [];
        $first = billingIssueInvoice($this->db->pdo(), $client, 25, '2026-10-10', $this->fakeAsaas($calls1));
        $this->db->pdo()->exec("UPDATE invoices SET status = 'DELETED' WHERE id = " . (int) $first['id']);

        // O Asaas devolve a cobrança removida pela mesma referência externa.
        $request = function (string $path, string $method, array $body) use ($first) {
            if ($method === 'GET' && str_contains($path, 'externalReference')) {
                return ['data' => [['id' => $first['asaas_payment_id'], 'invoiceUrl' => 'https://example.com/morta', 'deleted' => true]]];
            }
            if ($method === 'GET') return ['payload' => 'pix'];
            return ['id' => 'pay_nova', 'invoiceUrl' => 'https://example.com/nova', 'value' => $body['value'], 'dueDate' => $body['dueDate'], 'status' => 'PENDING'];
        };
        $second = billingIssueInvoice($this->db->pdo(), $client, 25, '2026-10-10', $request);

        self::assertSame('pay_nova', $second['asaas_payment_id']);
    }

    public function testBuscaDaFaturaDoMesPreferePendenteACanceladaMesmoComOutraData(): void
    {
        $clientId = $this->cycleClient('Busca', 100.0, 5);
        $this->db->seedInvoice($clientId, 'pay_morta', 100.0, '2026-10-05', 'DELETED');
        $viva = $this->db->seedInvoice($clientId, 'pay_viva', 100.0, '2026-10-20');

        $found = billingFindMonthInvoice($this->db->pdo(), $clientId, '2026-10-05');

        self::assertSame($viva, (int) $found['id']);
        self::assertNull(billingFindMonthInvoice($this->db->pdo(), $clientId, '2026-11-05'));
    }
}
