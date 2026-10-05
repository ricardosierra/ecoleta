<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/security_alerts.php';

/**
 * E-mail de alerta de segurança: public/api/security_alerts.php.
 *
 * O documento é montado por funções puras justamente para a suíte poder ler o
 * HTML: quem envia (osSendMail) em modo `log` só registra o destinatário, e foi
 * assim que o login sem escape passou despercebido.
 */
final class SecurityAlertsTest extends TestCase
{
    private const LOGIN_MALICIOSO = '<a href="https://evil.example/x">clique</a>';

    /**
     * O login entra no assunto, no título e no texto do evento. Só a tabela de
     * detalhes escapava: o <title> e o <h2> recebiam o `<a href>` cru, e o
     * alerta que chega no Gmail do administrador passava a ter um link do
     * atacante.
     */
    public function testLoginComHtmlNaoChegaCruAoDocumento(): void
    {
        $login = self::LOGIN_MALICIOSO;

        $documento = apiSecurityAlertDocument(
            "Hash de Senha Alterado: {$login}",
            "A senha do usuário '{$login}' foi alterada",
            ['Usuário' => $login],
            '03/10/2026 12:00:00',
            '203.0.113.10',
            'EcoletaTestes/1.0'
        );

        self::assertStringNotContainsString('<a href', $documento['html']);
        self::assertStringNotContainsString('evil.example/x">', $documento['html']);
        self::assertStringContainsString('&lt;a href=&quot;https://evil.example/x&quot;&gt;', $documento['html']);
    }

    public function testUserAgentEIpTambemSaemEscapados(): void
    {
        $documento = apiSecurityAlertDocument(
            'Assunto',
            'Evento',
            [],
            '03/10/2026 12:00:00',
            '203.0.113.10',
            '<script>alert(1)</script>'
        );

        self::assertStringNotContainsString('<script>', $documento['html']);
    }

    /** O texto puro não é HTML: aspas e sinais ficam como o usuário digitou. */
    public function testVersaoEmTextoPuroNaoEscapaNada(): void
    {
        $documento = apiSecurityAlertDocument(
            'Assunto',
            "Senha de 'joao' alterada",
            ['Usuário' => 'joao & cia'],
            '03/10/2026 12:00:00',
            '203.0.113.10',
            'EcoletaTestes/1.0'
        );

        self::assertStringContainsString("Senha de 'joao' alterada", $documento['text']);
        self::assertStringContainsString('Usuário: joao & cia', $documento['text']);
    }

    /** Detalhe vazio ou nulo não vira linha em branco no e-mail. */
    public function testDetalheVazioEhOmitido(): void
    {
        $documento = apiSecurityAlertDocument(
            'Assunto',
            'Evento',
            ['Preenchido' => 'sim', 'Vazio' => '', 'Nulo' => null],
            '03/10/2026 12:00:00',
            '203.0.113.10',
            'EcoletaTestes/1.0'
        );

        self::assertStringContainsString('Preenchido', $documento['html']);
        self::assertStringNotContainsString('Vazio', $documento['html']);
        self::assertStringNotContainsString('Nulo', $documento['html']);
    }

    /**
     * O assunto vai direto para o cabeçalho do e-mail. Quebra de linha ali é
     * injeção de cabeçalho (Bcc, por exemplo).
     */
    public function testAssuntoNaoCarregaQuebraDeLinha(): void
    {
        $assunto = apiSecurityAlertSubject("Hash alterado: joao\r\nBcc: alguem@evil.example");

        self::assertStringNotContainsString("\r", $assunto);
        self::assertStringNotContainsString("\n", $assunto);
        self::assertStringStartsWith('[ALERTA ECOLEVA] Hash alterado: joao', $assunto);
    }

    // --- destinatário --------------------------------------------------------

    protected function setUp(): void
    {
        putenv('SECURITY_ALERT_EMAIL');
    }

    protected function tearDown(): void
    {
        putenv('SECURITY_ALERT_EMAIL');
    }

    public function testDestinatarioPadraoContinuaOMesmo(): void
    {
        self::assertSame('sierra.csi@gmail.com', apiSecurityAlertRecipient());
    }

    public function testDestinatarioPodeSerConfigurado(): void
    {
        putenv('SECURITY_ALERT_EMAIL=seguranca@ecolevaeco.com');

        self::assertSame('seguranca@ecolevaeco.com', apiSecurityAlertRecipient());
    }

    /** Valor torto na configuração não pode silenciar o alerta: volta ao padrão. */
    public function testDestinatarioInvalidoVoltaAoPadrao(): void
    {
        putenv('SECURITY_ALERT_EMAIL=isto-nao-e-email');

        self::assertSame('sierra.csi@gmail.com', apiSecurityAlertRecipient());
    }
}
