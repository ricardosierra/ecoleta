<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/os/os_lib.php';

/**
 * O e-mail da OS: assunto, remetente, tempo limite do SMTP e o modo de teste.
 *
 * Nada aqui envia e-mail. O que se testa são as peças que `osSendMail()` monta,
 * antes do envio: o assunto codificado para o `mail()`, o remetente de cada
 * ramo e o objeto PHPMailer já configurado (sem chamar `send()`).
 */
final class OsMailTest extends TestCase
{
    /** Variáveis de ambiente que os testes mexem; restauradas no tearDown. */
    private const VARIAVEIS = [
        'MAIL_TRANSPORT', 'OS_MAIL_FROM', 'CONTACT_FROM_EMAIL', 'CONTACT_FROM_NAME',
        'SMTP_HOST', 'SMTP_USER', 'SMTP_PASS', 'SMTP_PORT', 'SMTP_SECURE',
    ];

    /** @var array<string,string|false> */
    private array $ambienteOriginal = [];

    protected function setUp(): void
    {
        foreach (self::VARIAVEIS as $nome) {
            $this->ambienteOriginal[$nome] = getenv($nome);
            putenv($nome);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->ambienteOriginal as $nome => $valor) {
            putenv($valor === false ? $nome : $nome . '=' . $valor);
        }
    }

    /** @return array<string,mixed> */
    private function os(string $cliente = 'Heineken'): array
    {
        return ['id' => 42, 'client_name' => $cliente];
    }

    // ── Assunto ──────────────────────────────────────────────────────────────

    public function testAssuntoDaOsETextoPuroELegivel(): void
    {
        $assunto = osEmailSubject($this->os());

        self::assertStringContainsString('Ordem de Serviço Nº 00042', $assunto);
        self::assertStringContainsString('Heineken', $assunto);
        self::assertStringNotContainsString('=?UTF-8?', $assunto, 'quem codifica é o envio, e só no ramo que precisa');
    }

    /**
     * Cada palavra codificada de um cabeçalho (RFC 2047) tem no máximo 75
     * caracteres. O assunto antes era UMA palavra só, que passava disso com um
     * nome de cliente longo, e servidores mais rigorosos recusam ou truncam.
     */
    public function testAssuntoLongoSaiEmVariasPalavrasCodificadasDeAte75Caracteres(): void
    {
        $assunto = osEmailSubject($this->os('Associação dos Moradores e Amigos do Condomínio Solar das Palmeiras de Rio Bonito'));

        $codificado = osMailEncodeSubject($assunto);

        preg_match_all('/=\?UTF-8\?B\?[^?]*\?=/', $codificado, $palavras);
        self::assertGreaterThan(1, count($palavras[0]), 'um assunto longo precisa ser dividido');
        foreach ($palavras[0] as $palavra) {
            self::assertLessThanOrEqual(75, strlen($palavra), $palavra);
        }
    }

    public function testAssuntoCodificadoVoltaIgualAoOriginal(): void
    {
        $assunto = osEmailSubject($this->os('Associação dos Moradores e Amigos do Condomínio Solar das Palmeiras de Rio Bonito'));

        self::assertSame($assunto, mb_decode_mimeheader(osMailEncodeSubject($assunto)));
    }

    public function testAssuntoCurtoComAcentoCabeEmUmaPalavra(): void
    {
        $assunto = osEmailSubject($this->os('Ação'));

        $codificado = osMailEncodeSubject($assunto);

        // O que é ASCII fica como está; só o trecho com acento vira palavra codificada.
        self::assertSame(1, preg_match_all('/=\?UTF-8\?B\?[^?]*\?=/', $codificado));
        self::assertStringStartsWith('Ordem de ', $codificado);
        self::assertSame($assunto, mb_decode_mimeheader($codificado));
    }

    public function testAssuntoSoAsciiNaoPrecisaDeCodificacao(): void
    {
        self::assertSame('Sua Fatura Mensal - Ecoleva', osMailEncodeSubject('Sua Fatura Mensal - Ecoleva'));
    }

    /** A quebra de linha do assunto é o caminho clássico de injeção de cabeçalho. */
    public function testQuebraDeLinhaNoAssuntoNaoVazaParaOCabecalho(): void
    {
        // Só ASCII: o mb_encode_mimeheader o deixaria passar com a quebra e tudo.
        self::assertSame('Ordem Bcc: alguem@exemplo.com', osMailEncodeSubject("Ordem\r\nBcc: alguem@exemplo.com"));

        // Com acento, vira palavras codificadas; ainda assim, sem quebra solta.
        $codificado = osMailEncodeSubject("Ordem Nº\nBcc: alguem@exemplo.com");
        self::assertDoesNotMatchRegularExpression('/[\r\n](?![ \t])/', $codificado);
        self::assertSame('Ordem Nº Bcc: alguem@exemplo.com', mb_decode_mimeheader($codificado));
    }

    // ── Remetente ────────────────────────────────────────────────────────────

    public function testRemetenteDoMailUsaOverrideValidoEDepoisOPadrao(): void
    {
        self::assertSame(OS_MAIL_FROM_DEFAULT, osMailFrom(false));

        putenv('OS_MAIL_FROM=os@ecolevaeco.com');
        self::assertSame('os@ecolevaeco.com', osMailFrom(false));

        putenv('OS_MAIL_FROM=isto nao e um e-mail');
        self::assertSame(OS_MAIL_FROM_DEFAULT, osMailFrom(false));
    }

    /**
     * `env.example.php` promete que OS_MAIL_FROM é o remetente do e-mail da OS,
     * mas só o ramo do `mail()` o lia: com SMTP configurado ele era ignorado.
     */
    public function testRemetenteComSmtpTambemObedeceOsMailFrom(): void
    {
        putenv('SMTP_USER=smtp@ecolevaeco.com');
        putenv('CONTACT_FROM_EMAIL=contato@ecolevaeco.com');
        putenv('OS_MAIL_FROM=os@ecolevaeco.com');

        self::assertSame('os@ecolevaeco.com', osMailFrom(true));
    }

    /** @return array<string, array{0:array<string,string>,1:string}> */
    public static function cadeiaDeRemetenteComSmtp(): array
    {
        return [
            'so o usuario do SMTP' => [['SMTP_USER' => 'smtp@ecolevaeco.com'], 'smtp@ecolevaeco.com'],
            'contato vence o usuario' => [['SMTP_USER' => 'smtp@ecolevaeco.com', 'CONTACT_FROM_EMAIL' => 'contato@ecolevaeco.com'], 'contato@ecolevaeco.com'],
            'override invalido e ignorado' => [['OS_MAIL_FROM' => 'lixo', 'CONTACT_FROM_EMAIL' => 'contato@ecolevaeco.com'], 'contato@ecolevaeco.com'],
            'nada configurado cai no padrao' => [[], OS_MAIL_FROM_DEFAULT],
        ];
    }

    /** @param array<string,string> $env */
    #[DataProvider('cadeiaDeRemetenteComSmtp')]
    public function testRemetenteComSmtpSegueACadeia(array $env, string $esperado): void
    {
        foreach ($env as $nome => $valor) {
            putenv($nome . '=' . $valor);
        }

        self::assertSame($esperado, osMailFrom(true));
    }

    // ── PHPMailer ────────────────────────────────────────────────────────────

    private function configurarSmtp(): void
    {
        putenv('SMTP_HOST=smtp.exemplo.com.br');
        putenv('SMTP_USER=smtp@ecolevaeco.com');
        putenv('SMTP_PASS=senha-de-teste');
        putenv('SMTP_PORT=587');
    }

    /**
     * O tempo limite padrão do PHPMailer é de 300 segundos: com o SMTP fora do ar,
     * o botão "E-mail" ficava cinco minutos pendurado e o PHP segurava o processo.
     */
    public function testSmtpTemTempoLimiteCurto(): void
    {
        $this->configurarSmtp();

        $mailer = osBuildSmtpMailer('cliente@exemplo.com', 'Assunto', '<p>oi</p>', 'oi', null);

        self::assertSame(OS_SMTP_TIMEOUT, $mailer->Timeout);
        self::assertLessThanOrEqual(30, $mailer->Timeout);
        self::assertGreaterThan(0, $mailer->Timeout);
    }

    public function testMailerSmtpLevaRemetenteDestinoAssuntoEResposta(): void
    {
        $this->configurarSmtp();
        putenv('OS_MAIL_FROM=os@ecolevaeco.com');
        putenv('CONTACT_FROM_NAME=Ecoleva Coleta');

        $mailer = osBuildSmtpMailer(
            'cliente@exemplo.com',
            'Ordem de Serviço Nº 00042 - Heineken',
            '<p>oi</p>',
            'oi',
            'operador@ecolevaeco.com'
        );

        self::assertSame('os@ecolevaeco.com', $mailer->From);
        self::assertSame('Ecoleva Coleta', $mailer->FromName);
        self::assertSame('smtp.exemplo.com.br', $mailer->Host);
        self::assertSame(587, $mailer->Port);
        self::assertSame('Ordem de Serviço Nº 00042 - Heineken', $mailer->Subject, 'o PHPMailer codifica sozinho: recebe texto puro');
        self::assertSame([['cliente@exemplo.com', '']], $mailer->getToAddresses());
        self::assertSame([['operador@ecolevaeco.com', '']], $mailer->getReplyToAddresses());
        self::assertSame('oi', $mailer->AltBody);
    }

    public function testMailerSmtpIgnoraRespostaInvalida(): void
    {
        $this->configurarSmtp();

        $mailer = osBuildSmtpMailer('cliente@exemplo.com', 'Assunto', '<p>oi</p>', 'oi', 'isto nao e e-mail');

        self::assertSame([], $mailer->getReplyToAddresses());
    }

    // ── Modo de teste ────────────────────────────────────────────────────────

    public function testModoDeLogEReconhecidoSemDiferenciarMaiusculas(): void
    {
        self::assertFalse(osMailIsLogOnly());

        putenv('MAIL_TRANSPORT=log');
        self::assertTrue(osMailIsLogOnly());

        putenv('MAIL_TRANSPORT=LOG');
        self::assertTrue(osMailIsLogOnly());

        putenv('MAIL_TRANSPORT=smtp');
        self::assertFalse(osMailIsLogOnly());
    }

    public function testModoDeLogNaoChamaOMtaEDevolveVerdadeiro(): void
    {
        putenv('MAIL_TRANSPORT=log');
        $logAnterior = ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'ecoleta_mail_log_'));

        try {
            self::assertTrue(osSendMail('cliente@exemplo.com', 'Assunto', '<p>oi</p>', 'oi'));
        } finally {
            if ($logAnterior !== false) {
                ini_set('error_log', $logAnterior);
            }
        }
    }
}
