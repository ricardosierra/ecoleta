<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * As partes puras do faturamento — decisão de data e o documento do e-mail.
 *
 * Este arquivo existe por causa de um defeito que a suíte anterior não pegava:
 * o botão "Visualizar Boleto" interpolava `{$faturaUrl}`, uma variável nunca
 * atribuída, e TODO e-mail de fatura saiu com `href=""`. Nada executava o cron,
 * e os testes de data reimplementavam a regra dentro do próprio teste — então
 * continuariam verdes com o cron quebrado.
 */
final class BillingLibTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ECOLETA_API_DIR . '/billing_lib.php';
    }

    // ── Quando cobrar ───────────────────────────────────────────────────────

    /** @return array<string, array{0:string, 1:bool}> */
    public static function diasDaJanelaDoMesSeguinte(): array
    {
        return [
            'mês de 31 dias, dia 29' => ['2026-01-29', false],
            'mês de 31 dias, dia 30' => ['2026-01-30', true],
            'mês de 31 dias, dia 31' => ['2026-01-31', true],
            'mês de 30 dias, dia 29' => ['2026-04-29', false],
            'mês de 30 dias, dia 30' => ['2026-04-30', true],
            'fevereiro comum, dia 27' => ['2026-02-27', false],
            'fevereiro comum, dia 28' => ['2026-02-28', true],
            'fevereiro bissexto, dia 28' => ['2028-02-28', false],
            'fevereiro bissexto, dia 29' => ['2028-02-29', true],
            'dia comum' => ['2026-06-15', false],
            'primeiro dia do mês' => ['2026-10-01', false],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('diasDaJanelaDoMesSeguinte')]
    public function testJanelaDoMesSeguinteAbreNoDia30OuNoUltimoDiaDoMes(string $data, bool $esperado): void
    {
        self::assertSame($esperado, billingNextCycleIsOpen(new DateTimeImmutable($data)), $data);
    }

    public function testJanelaDoMesSeguinteNaoFechaAntesDoFimDoMes(): void
    {
        // O que torna o cron diário capaz de recuperar uma execução perdida: depois que a
        // janela abre ela fica aberta até o último dia, em qualquer mês do ano.
        foreach (range(1, 12) as $mes) {
            $primeiro = new DateTimeImmutable(sprintf('2026-%02d-01', $mes));
            $totalDias = (int) $primeiro->format('t');

            $abriu = false;
            for ($dia = 1; $dia <= $totalDias; $dia++) {
                $aberta = billingNextCycleIsOpen($primeiro->setDate(2026, $mes, $dia));
                if ($abriu) {
                    self::assertTrue($aberta, sprintf('mês %d fechou de novo no dia %d', $mes, $dia));
                }
                $abriu = $abriu || $aberta;
            }

            self::assertTrue($abriu, sprintf('mês %d nunca abriu a janela do mês seguinte', $mes));
            self::assertTrue(billingNextCycleIsOpen($primeiro->setDate(2026, $mes, $totalDias)), sprintf('mês %d fechado no último dia', $mes));
        }
    }

    public function testClienteCadastradoNoDia3ComVencimentoNoDia5RecebeAFaturaDoMesCorrente(): void
    {
        // O caso da cliente: antes, a primeira fatura só saía em 30/10, para 05/11.
        self::assertSame(['2026-10-05'], billingDueDatesToIssue(new DateTimeImmutable('2026-10-03'), 5));
    }

    public function testVencimentoDeHojeAindaEEmitido(): void
    {
        self::assertSame(['2026-10-05'], billingDueDatesToIssue(new DateTimeImmutable('2026-10-05'), 5));
    }

    public function testVencimentoQueJaPassouNaoEEmitidoRetroativamente(): void
    {
        // Cadastrado no dia 6 com vencimento no dia 5: o Asaas recusa data passada, e
        // cobrar o mês que passou é decisão de quem opera, pela tela de Faturas.
        self::assertSame([], billingDueDatesToIssue(new DateTimeImmutable('2026-10-06'), 5));
    }

    public function testNoDia30EmiteOMesSeguinteEOCorrenteQuandoAindaVale(): void
    {
        // Vencimento já passado no mês corrente: só o do mês seguinte.
        self::assertSame(['2026-10-05'], billingDueDatesToIssue(new DateTimeImmutable('2026-09-30'), 5));
        // Vencimento ainda por vir no mês corrente: os dois, em ordem.
        self::assertSame(['2026-09-30', '2026-10-31'], billingDueDatesToIssue(new DateTimeImmutable('2026-09-30'), 31));
    }

    public function testExecucaoPerdidaNoDia30ERecuperadaNosDiasSeguintes(): void
    {
        // Cron fora do ar em 30/09: em 01/10 o vencimento de 05/10 ainda é emitido.
        self::assertSame(['2026-10-05'], billingDueDatesToIssue(new DateTimeImmutable('2026-10-01'), 5));
        // E, no mesmo mês, em 31/10 a emissão de novembro continua aberta.
        self::assertSame(['2026-11-05'], billingDueDatesToIssue(new DateTimeImmutable('2026-10-31'), 5));
    }

    public function testVencimentoEncolheEmMesCurtoNoMesCorrente(): void
    {
        self::assertSame(['2026-02-28'], billingDueDatesToIssue(new DateTimeImmutable('2026-02-10'), 31));
    }

    public function testNuncaDevolveDataPassadaNemRepetida(): void
    {
        $inicio = new DateTimeImmutable('2026-01-01');
        for ($offset = 0; $offset < 730; $offset++) {
            $hoje = $inicio->modify("+{$offset} days");
            for ($dueDay = 1; $dueDay <= 31; $dueDay++) {
                $datas = billingDueDatesToIssue($hoje, $dueDay);
                self::assertLessThanOrEqual(2, count($datas), $hoje->format('Y-m-d') . " dia {$dueDay}");
                self::assertSame($datas, array_values(array_unique($datas)));
                foreach ($datas as $data) {
                    self::assertGreaterThanOrEqual($hoje->format('Y-m-d'), $data, $hoje->format('Y-m-d') . " dia {$dueDay}");
                }
            }
        }
    }

    public function testDiasDeLembrete(): void
    {
        self::assertTrue(billingShouldRemind(new DateTimeImmutable('2026-09-03')));
        self::assertTrue(billingShouldRemind(new DateTimeImmutable('2026-09-07')));
        self::assertFalse(billingShouldRemind(new DateTimeImmutable('2026-09-04')));
        self::assertFalse(billingShouldRemind(new DateTimeImmutable('2026-09-30')));
    }

    public function testVencimentoCaiNoMesSeguinteNoDiaDoCadastro(): void
    {
        self::assertSame('2026-10-10', billingDueDate(new DateTimeImmutable('2026-09-30'), 10));
        self::assertSame('2026-03-05', billingDueDate(new DateTimeImmutable('2026-02-28'), 5));
    }

    public function testVencimentoEncolheQuandoODiaNaoExisteNoMes(): void
    {
        // Cliente com due_day 31 cobrado em um mês de 30 dias.
        self::assertSame('2026-11-30', billingDueDate(new DateTimeImmutable('2026-10-30'), 31));
        // E em fevereiro.
        self::assertSame('2026-02-28', billingDueDate(new DateTimeImmutable('2026-01-30'), 31));
    }

    public function testVencimentoNuncaSaiDoIntervaloValido(): void
    {
        self::assertSame('2026-10-01', billingDueDate(new DateTimeImmutable('2026-09-30'), 0));
        self::assertSame('2026-10-01', billingDueDate(new DateTimeImmutable('2026-09-30'), -5));
    }

    // ── O documento do e-mail ───────────────────────────────────────────────

    public function testEmailDaFaturaTrazOBotaoDoBoletoApontandoParaAFatura(): void
    {
        $email = billingNewInvoiceEmail(
            'Cliente Exemplo',
            250.5,
            '2026-10-10',
            '00020126580014br.gov.bcb.pix',
            'https://www.asaas.com/i/abc123'
        );

        self::assertStringContainsString('Visualizar Boleto', $email['html']);
        self::assertStringContainsString('href="https://www.asaas.com/i/abc123"', $email['html']);
        self::assertStringNotContainsString('href=""', $email['html']);
        self::assertStringNotContainsString('faturaUrl', $email['html']);
        self::assertStringContainsString('https://www.asaas.com/i/abc123', $email['text']);
    }

    public function testEmailDaFaturaTrazValorVencimentoEPix(): void
    {
        $email = billingNewInvoiceEmail(
            'Cliente Exemplo',
            250.5,
            '2026-10-10',
            '00020126580014br.gov.bcb.pix',
            'https://www.asaas.com/i/abc123'
        );

        self::assertStringContainsString('R$ 250,50', $email['html']);
        self::assertStringContainsString('10/10/2026', $email['html']);
        self::assertStringContainsString('00020126580014br.gov.bcb.pix', $email['html']);
        self::assertStringContainsString('Sua Fatura Mensal', $email['html']);
        self::assertSame('Sua Fatura Mensal - Ecoleva', $email['subject']);
    }

    public function testLembreteUsaOMesmoDocumentoComOutroTitulo(): void
    {
        $email = billingReminderEmail(
            'Cliente Exemplo',
            100.0,
            '2026-09-10',
            'pix-payload',
            'https://www.asaas.com/i/xyz'
        );

        self::assertStringContainsString('Lembrete de Vencimento', $email['html']);
        self::assertStringContainsString('href="https://www.asaas.com/i/xyz"', $email['html']);
        self::assertStringContainsString('R$ 100,00', $email['html']);
        self::assertSame('Lembrete de Fatura - Ecoleva', $email['subject']);
    }

    public function testSemLinkDeFaturaOBotaoDoBoletoNaoAparece(): void
    {
        $email = billingReminderEmail('Cliente Exemplo', 100.0, '2026-09-10', 'pix-payload', '');

        self::assertStringNotContainsString('Visualizar Boleto', $email['html']);
        self::assertStringNotContainsString('href=""', $email['html']);
    }

    public function testLinkDeFaturaInvalidoNaoViraBotao(): void
    {
        $email = billingReminderEmail('Cliente', 10.0, '2026-09-10', 'pix', 'javascript:alert(1)');

        self::assertStringNotContainsString('Visualizar Boleto', $email['html']);
        self::assertStringNotContainsString('javascript:', $email['html']);
    }

    public function testSemPixOQrCodeNaoAparece(): void
    {
        $email = billingReminderEmail('Cliente', 10.0, '2026-09-10', '', 'https://www.asaas.com/i/x');

        self::assertStringNotContainsString('Pague via Pix', $email['html']);
        self::assertStringNotContainsString('qrserver.com', $email['html']);
    }

    public function testNomeDoClienteEEscapadoNoHtml(): void
    {
        // O nome vem de um campo livre do dashboard e cai dentro do HTML do
        // e-mail; sem escapar, um "<" no cadastro quebra o documento.
        $email = billingNewInvoiceEmail(
            'Alves & Cia <script>alert(1)</script>',
            10.0,
            '2026-10-10',
            'pix',
            'https://www.asaas.com/i/x'
        );

        self::assertStringNotContainsString('<script>', $email['html']);
        self::assertStringContainsString('&amp;', $email['html']);
    }

    public function testPixEEscapadoNoHtml(): void
    {
        $email = billingNewInvoiceEmail('Cliente', 10.0, '2026-10-10', 'pix"><script>x</script>', 'https://www.asaas.com/i/x');

        self::assertStringNotContainsString('<script>', $email['html']);
    }
}
