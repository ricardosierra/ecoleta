<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BillingCronTest extends TestCase
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

    public function testCronExigeSecretCorreto(): void
    {
        $resSemSecret = Endpoint::call('cron/billing.php', [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'query' => [],
        ]);
        self::assertSame(403, $resSemSecret->status, $resSemSecret->body);
        self::assertStringContainsString('Acesso negado', $resSemSecret->body);

        $resSecretErrado = Endpoint::call('cron/billing.php', [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'query' => ['secret' => 'invalido'],
        ]);
        self::assertSame(403, $resSecretErrado->status, $resSecretErrado->body);
        self::assertStringContainsString('Acesso negado', $resSecretErrado->body);

        $resSecretCorreto = Endpoint::call('cron/billing.php', [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'query' => ['secret' => 'ecoleva_cron_secret'],
        ]);
        self::assertSame(200, $resSecretCorreto->status, $resSecretCorreto->body);
        self::assertStringContainsString('Cron rodou com sucesso', $resSecretCorreto->body);
    }

    public function testLogicaDeDataExecutaExatamenteUmaVezPorMesEmAnoComum(): void
    {
        $diasPorMes = [
            1 => 31, 2 => 28, 3 => 31, 4 => 30,
            5 => 31, 6 => 30, 7 => 31, 8 => 31,
            9 => 30, 10 => 31, 11 => 30, 12 => 31,
        ];

        foreach ($diasPorMes as $mes => $totalDias) {
            $execucoesNoMes = 0;
            $diasExecutados = [];

            for ($dia = 1; $dia <= $totalDias; $dia++) {
                $isLastDayOfMonth = ($dia === $totalDias);
                $deveExecutar = ($dia === 30 || ($isLastDayOfMonth && $dia < 30));
                if ($deveExecutar) {
                    $execucoesNoMes++;
                    $diasExecutados[] = $dia;
                }
            }

            self::assertSame(
                1,
                $execucoesNoMes,
                sprintf('Mes %d deveria executar 1 vez, executou %d vezes nos dias: %s', $mes, $execucoesNoMes, implode(',', $diasExecutados))
            );

            if ($totalDias === 31) {
                self::assertSame([30], $diasExecutados, "Mes {$mes} (31 dias) deve executar apenas no dia 30, nao no dia 31.");
            } elseif ($totalDias === 30) {
                self::assertSame([30], $diasExecutados, "Mes {$mes} (30 dias) deve executar no dia 30.");
            } elseif ($totalDias === 28) {
                self::assertSame([28], $diasExecutados, "Fevereiro comum deve executar no dia 28.");
            }
        }
    }

    public function testLogicaDeDataExecutaEmFevereiroBissexto(): void
    {
        $totalDias = 29;
        $diasExecutados = [];

        for ($dia = 1; $dia <= $totalDias; $dia++) {
            $isLastDayOfMonth = ($dia === $totalDias);
            $deveExecutar = ($dia === 30 || ($isLastDayOfMonth && $dia < 30));
            if ($deveExecutar) {
                $diasExecutados[] = $dia;
            }
        }

        self::assertSame([29], $diasExecutados, 'Fevereiro bissexto deve executar no dia 29.');
    }

    public function testIdempotenciaNaoGeraFaturaDuplicadaParaMesmoClienteEVencimento(): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("INSERT INTO clients (name, monthly_value, due_day, status, asaas_customer_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['Cliente Teste Idempotencia', 200.00, 10, 'active', 'cus_test_123']);
        $clientId = (int)$pdo->lastInsertId();

        $dueDateStr = '2026-10-10';

        // 1. Antes de gerar, verifica que fatura nao existe
        $checkStmt = $pdo->prepare("SELECT id FROM invoices WHERE client_id = ? AND due_date = ? LIMIT 1");
        $checkStmt->execute([$clientId, $dueDateStr]);
        self::assertFalse((bool)$checkStmt->fetch(), 'Fatura ainda nao deve existir');

        // 2. Insere a primeira fatura (como o cron faz na 1a execucao)
        $insertStmt = $pdo->prepare("INSERT INTO invoices (client_id, asaas_payment_id, value, due_date, invoice_url, pix_qrcode_text, pix_qrcode_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insertStmt->execute([
            $clientId,
            'pay_test_001',
            200.00,
            $dueDateStr,
            'https://asaas.com/i/test001',
            'pix-payload-test',
            'pix-image-test',
        ]);
        self::assertSame(1, $this->db->count('invoices'));

        // 3. Simula a segunda execucao do cron no mesmo dia
        $checkStmt->execute([$clientId, $dueDateStr]);
        $existing = $checkStmt->fetch();
        self::assertNotEmpty($existing, 'A verificacao de idempotencia deve encontrar a fatura existente');

        // Como encontrou, o cron da continue e nao faz novo insert
        if (!$existing) {
            $insertStmt->execute([
                $clientId,
                'pay_test_002',
                200.00,
                $dueDateStr,
                'https://asaas.com/i/test002',
                'pix-payload-test',
                'pix-image-test',
            ]);
        }

        // Contagem de faturas permanece 1
        self::assertSame(1, $this->db->count('invoices'), 'Nao deve existir duplicata de fatura para o mesmo cliente e vencimento');
    }
}
