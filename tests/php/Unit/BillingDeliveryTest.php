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
}
