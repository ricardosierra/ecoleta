<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/whatsapp/bot.php';

/**
 * O robô que responde com fatura e Pix.
 *
 * Cada resposta é uma mensagem de cobrança com código Pix, mandada para quem
 * escreveu para o número da empresa. Os casos aqui travam as duas coisas que
 * mais dão problema: responder quando não devia (emoji, "ok", a foto do
 * comprovante, a mesma pergunta duas vezes) e não responder quando devia
 * (cliente com fatura vencida, número com ou sem o nono dígito).
 *
 * Nada toca em rede: o Pix, o envio e o upload entram por `$deps`.
 */
final class WhatsAppBotTest extends TestCase
{
    private const PIX = '00020126PIXCOPIAECOLA';

    private TestDatabase $db;

    /** @var list<array<string,mixed>> payloads que o robô mandou para a Meta */
    private array $enviados = [];

    /** @var list<array{path:string,mime:string,existia:bool}> */
    private array $uploads = [];

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        $this->enviados = [];
        $this->uploads = [];
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    /** @param array<string,callable> $extra */
    private function deps(array $extra = []): array
    {
        return array_merge([
            'pix' => static fn (string $paymentId): array => [
                'payload' => self::PIX,
                'encodedImage' => 'iVBORw0KGgo=',
            ],
            'send' => function (array $payload): array {
                $this->enviados[] = $payload;

                return ['messages' => [['id' => 'wamid.BOT' . count($this->enviados)]]];
            },
            'upload' => function (string $path, string $mime): string {
                $this->uploads[] = ['path' => $path, 'mime' => $mime, 'existia' => is_file($path)];

                return 'media-123';
            },
        ], $extra);
    }

    private function cliente(string $nome = 'Heineken', ?string $whatsapp = '5521999887766'): int
    {
        return $this->db->seedClient($nome, 120.5, 10, 'active', $whatsapp);
    }

    private function fatura(int $clientId, string $status = 'PENDING', string $vencimento = '2026-10-10', string $id = 'pay_1'): int
    {
        return $this->db->seedInvoice($clientId, $id, 120.5, $vencimento, $status);
    }

    /** @param array<string,callable> $extra */
    private function responder(string $texto, string $from = '5521999887766', string $tipo = 'text', array $extra = []): void
    {
        waAutoReplyWithPix($this->db->pdo(), $from, $texto, $tipo, $this->deps($extra));
    }

    /** @return list<string> corpos das mensagens de texto que saíram */
    private function textosEnviados(): array
    {
        $textos = [];
        foreach ($this->enviados as $payload) {
            if (($payload['type'] ?? '') === 'text') {
                $textos[] = (string) $payload['text']['body'];
            }
        }

        return $textos;
    }

    /** @return list<array<string,mixed>> */
    private function saidasGravadas(): array
    {
        return array_values(array_filter(
            $this->db->rows('whatsapp_messages'),
            static fn (array $m): bool => $m['direction'] === 'outgoing'
        ));
    }

    // ── Quando o robô entende que é pedido de fatura ────────────────────────

    /** @return array<string, array{0:string}> */
    public static function pedidosDeFatura(): array
    {
        return [
            'fatura' => ['Preciso da fatura'],
            'maiúsculas' => ['BOLETO'],
            'pix' => ['me manda o pix?'],
            'qr' => ['qr'],
            'qr code' => ['pode mandar o QR Code'],
            'qrcode junto' => ['qrcode por favor'],
            'segunda via' => ['segunda via'],
            'segunda via com acento' => ['Segunda via do boleto'],
            '2a via' => ['2a via'],
            '2ª via' => ['Preciso da 2ª via'],
            'pagar' => ['como faço para pagar?'],
            'pagamento' => ['qual a forma de pagamento'],
            'cobrança com acento' => ['Recebi uma cobrança'],
            'cobranca sem acento' => ['cobranca'],
            'vencimento' => ['qual o vencimento?'],
            'plural' => ['quero os boletos'],
        ];
    }

    #[DataProvider('pedidosDeFatura')]
    public function testEntendeOPedidoDeFatura(string $texto): void
    {
        self::assertTrue(waBotWantsInvoice('text', $texto), $texto);
    }

    /** @return array<string, array{0:string}> */
    public static function conversaQueNaoEPedidoDeFatura(): array
    {
        return [
            'ok' => ['ok'],
            'obrigado' => ['obrigado'],
            'bom dia' => ['Bom dia'],
            'emoji' => ['👍'],
            'só pontuação' => ['...'],
            'vazio' => [''],
            'palavra que só começa igual' => ['o pixel está torto'],
            'comprovante' => ['segue o comprovante do pix'],
            'já paguei' => ['já paguei a fatura'],
            'quitado' => ['boleto quitado ontem'],
            'rótulo de imagem' => ['[imagem]'],
            'rótulo de áudio' => ['[áudio]'],
            'rótulo de documento' => ['[documento]'],
        ];
    }

    #[DataProvider('conversaQueNaoEPedidoDeFatura')]
    public function testNaoEntendeComoPedidoDeFatura(string $texto): void
    {
        self::assertFalse(waBotWantsInvoice('text', $texto), $texto);
    }

    /** @return array<string, array{0:string}> */
    public static function tiposQueNaoSaoTexto(): array
    {
        return [
            'imagem' => ['image'],
            'áudio' => ['audio'],
            'documento' => ['document'],
            'vídeo' => ['video'],
            'figurinha' => ['sticker'],
            'reação' => ['reaction'],
            'botão' => ['button'],
            'sem tipo' => [''],
        ];
    }

    /** Legenda de foto ("fatura") é legenda de mídia, não pedido: só texto puro conta. */
    #[DataProvider('tiposQueNaoSaoTexto')]
    public function testMidiaNuncaEPedidoDeFatura(string $tipo): void
    {
        self::assertFalse(waBotWantsInvoice($tipo, 'segunda via do boleto'));
    }

    public function testSoQrCodePedeAImagem(): void
    {
        self::assertTrue(waBotWantsQrImage('qr code'));
        self::assertTrue(waBotWantsQrImage('QRcode'));
        self::assertTrue(waBotWantsQrImage('manda o QR'));
        self::assertFalse(waBotWantsQrImage('segunda via do boleto'));
        self::assertFalse(waBotWantsQrImage('manda o pix'));
        self::assertFalse(waBotWantsQrImage('foto do boleto'));
    }

    // ── W3: o robô só responde a texto com intenção clara ───────────────────

    public function testTextoPedindoAFaturaRecebeAvisoECodigoPix(): void
    {
        $clienteId = $this->cliente();
        $this->fatura($clienteId);

        $this->responder('Boa tarde, preciso da segunda via do boleto');

        $textos = $this->textosEnviados();
        self::assertCount(2, $textos);
        self::assertStringContainsString('Pix Copia e Cola:', $textos[0]);
        self::assertStringContainsString(self::PIX, $textos[0]);
        self::assertSame(self::PIX, $textos[1]);
        // Sem pedir QR, não sobe imagem.
        self::assertSame([], $this->uploads);
        self::assertCount(2, $this->saidasGravadas());
    }

    /**
     * O webhook grava "[imagem]" como corpo da foto. A regex antiga casava com
     * "imagem" e mandava o QR de novo para quem acabava de mandar o comprovante.
     */
    public function testFotoDoComprovanteNaoRecebeOQrDeNovo(): void
    {
        $this->fatura($this->cliente());

        $this->responder('[imagem]', '5521999887766', 'image');

        self::assertSame([], $this->enviados);
        self::assertSame([], $this->uploads);
        self::assertSame([], $this->saidasGravadas());
    }

    public function testRotuloEntreColchetesMesmoComoTextoNaoDisparaOBot(): void
    {
        $this->fatura($this->cliente());

        $this->responder('[imagem]', '5521999887766', 'text');

        self::assertSame([], $this->enviados);
    }

    public function testFotoComLegendaTambemNaoDisparaOBot(): void
    {
        $this->fatura($this->cliente());

        $this->responder('segunda via do boleto', '5521999887766', 'image');

        self::assertSame([], $this->enviados);
    }

    public function testConversaSemIntencaoNaoRecebeFatura(): void
    {
        $this->fatura($this->cliente());

        foreach (['ok', 'obrigado', '👍', 'bom dia'] as $texto) {
            $this->responder($texto);
        }

        self::assertSame([], $this->enviados);
    }

    public function testNoMaximoUmaRespostaAutomaticaPorJanelaDeSeisHoras(): void
    {
        $this->fatura($this->cliente());

        $this->responder('segunda via do boleto');
        $primeira = count($this->enviados);
        self::assertGreaterThan(0, $primeira);

        $this->responder('pix');
        $this->responder('qr code');
        $this->responder('fatura');

        self::assertCount($primeira, $this->enviados, 'o robô respondeu de novo dentro da janela');
        self::assertSame([], $this->uploads);
    }

    public function testRespondeDeNovoDepoisDaJanelaDeSeisHoras(): void
    {
        $this->fatura($this->cliente());
        $this->responder('segunda via do boleto');
        $primeira = count($this->enviados);

        // Envelhece as respostas gravadas para 7 horas atrás.
        $antes = gmdate('Y-m-d H:i:s', time() - 7 * 3600);
        $this->db->pdo()->prepare('UPDATE whatsapp_messages SET message_at = ?')->execute([$antes]);

        $this->responder('segunda via do boleto');

        self::assertCount($primeira * 2, $this->enviados);
    }

    /** Resposta de atendente (com usuário gravado) não conta como automática. */
    public function testRespostaDeAtendenteNaoImpedeORobo(): void
    {
        $this->fatura($this->cliente());
        $pdo = $this->db->pdo();
        $conversaId = (int) waEnsureConversation($pdo, '5521999887766');
        waRecordMessage($pdo, $conversaId, [
            'direction' => 'outgoing',
            'type' => 'text',
            'body' => 'Posso ajudar? Mensagem automática da Ecoleva',
            'sent_by_user_id' => 7,
        ]);

        $this->responder('segunda via do boleto');

        self::assertNotEmpty($this->enviados);
    }

    public function testRespostaDeOutraConversaNaoImpedeORobo(): void
    {
        $a = $this->cliente('A', '5521999887766');
        $this->fatura($a, 'PENDING', '2026-10-10', 'pay_a');
        $b = $this->cliente('B', '5521988887777');
        $this->fatura($b, 'PENDING', '2026-10-10', 'pay_b');

        $this->responder('boleto', '5521999887766');
        $this->enviados = [];
        $this->responder('boleto', '5521988887777');

        self::assertNotEmpty($this->enviados);
    }

    // ── W4: achar a fatura certa ────────────────────────────────────────────

    public function testClienteComFaturaVencidaTambemRecebe(): void
    {
        $this->fatura($this->cliente(), 'OVERDUE', '2026-08-10');

        $this->responder('segunda via do boleto');

        $textos = $this->textosEnviados();
        self::assertCount(2, $textos);
        self::assertStringContainsString('venceu em *10/08/2026*', $textos[0]);
    }

    public function testFaturaPagaOuCanceladaNaoRecebe(): void
    {
        $clienteId = $this->cliente();
        $this->fatura($clienteId, 'RECEIVED', '2026-08-10', 'pay_a');
        $this->fatura($clienteId, 'DELETED', '2026-09-10', 'pay_b');

        $this->responder('segunda via do boleto');

        self::assertSame([], $this->enviados);
    }

    public function testComVariasFaturasAbertasVaiAMaisAntiga(): void
    {
        $clienteId = $this->cliente();
        $this->fatura($clienteId, 'PENDING', '2026-10-10', 'pay_nova');
        $this->fatura($clienteId, 'OVERDUE', '2026-08-10', 'pay_velha');
        $pedidas = [];

        $this->responder('boleto', '5521999887766', 'text', [
            'pix' => static function (string $paymentId) use (&$pedidas): array {
                $pedidas[] = $paymentId;

                return ['payload' => self::PIX, 'encodedImage' => ''];
            },
        ]);

        self::assertSame(['pay_velha'], $pedidas);
    }

    /** A saudação usa o nome do cliente: a tabela de faturas não tem esse campo. */
    public function testSaudacaoTrazONomeDoCliente(): void
    {
        $this->fatura($this->cliente('Heineken Brasil'));

        $this->responder('boleto');

        $texto = $this->textosEnviados()[0];
        self::assertStringStartsWith('Olá, Heineken Brasil.', $texto);
        self::assertStringContainsString('R$ 120,50', $texto);
        self::assertStringContainsString('vence em *10/10/2026*', $texto);
    }

    /** O cadastro pode ter máscara ou DDI solto: o que vale é o número normalizado. */
    public function testCadastroComMascaraCasaPeloNumeroNormalizado(): void
    {
        $this->fatura($this->cliente('Heineken', '+55 (21) 99988-7766'));

        $this->responder('boleto');

        self::assertNotEmpty($this->enviados);
    }

    /** A Meta manda wa_id de 12 dígitos (sem o 9) para contas antigas; o cadastro tem 13. */
    public function testWaIdSemONonoDigitoCasaComCadastroComNono(): void
    {
        $this->fatura($this->cliente('Heineken', '5521999887766'));

        $this->responder('boleto', '552199887766');

        self::assertNotEmpty($this->enviados);
    }

    /** O caminho inverso: cadastro antigo de 12 dígitos, wa_id novo de 13. */
    public function testWaIdComNonoDigitoCasaComCadastroSemNono(): void
    {
        $this->fatura($this->cliente('Heineken', '552199887766'));

        $this->responder('boleto', '5521999887766');

        self::assertNotEmpty($this->enviados);
    }

    /** O LIKE pelos 10 últimos dígitos casava DDD 21 com DDD 31: a cauda "1999887766" é a mesma. */
    public function testMesmaCaudaDeDezDigitosEmOutroDddNaoCasa(): void
    {
        $this->fatura($this->cliente('Cliente de Minas', '5531999887766'));

        $this->responder('boleto', '5521999887766');

        self::assertSame([], $this->enviados, 'o robô mandou a fatura de um cliente de outro DDD');
    }

    public function testNaoTrocaODonoDaConversa(): void
    {
        $pdo = $this->db->pdo();
        $dono = $this->cliente('Associação', '5521999887766');
        $outro = $this->cliente('Condomínio', '5521999887766');
        $conversaId = (int) waEnsureConversation($pdo, '5521999887766', ['client_id' => $dono]);
        $this->fatura($outro);

        $this->responder('boleto');

        $linha = $this->db->rows('whatsapp_conversations')[0];
        self::assertSame($conversaId, (int) $linha['id']);
        self::assertSame($dono, (int) $linha['client_id']);
    }

    public function testPixIndisponivelNaoQuebraNemResponde(): void
    {
        $this->fatura($this->cliente());

        $this->responder('boleto', '5521999887766', 'text', [
            'pix' => static function (string $paymentId): array {
                throw new RuntimeException('Pix indisponível');
            },
        ]);

        self::assertSame([], $this->enviados);
    }

    // ── QR Code como imagem ─────────────────────────────────────────────────

    public function testQrCodePedidoMandaImagemAvisoECodigo(): void
    {
        $this->fatura($this->cliente());

        $this->responder('manda o qr code');

        self::assertCount(1, $this->uploads);
        self::assertSame('image/png', $this->uploads[0]['mime']);
        self::assertTrue($this->uploads[0]['existia']);

        $tipos = array_column($this->enviados, 'type');
        self::assertSame(['image', 'text', 'text'], $tipos);
        self::assertSame('media-123', $this->enviados[0]['image']['id']);
        self::assertCount(3, $this->saidasGravadas());
        // Pedido de QR já entregue: o aviso não repete a dica de como pedir.
        self::assertStringNotContainsString('basta responder', $this->textosEnviados()[0]);
    }

    /** O PNG do Pix ia ficar em /tmp para sempre quando o upload falhava. */
    public function testFalhaNoUploadNaoDeixaOPngNoDisco(): void
    {
        $this->fatura($this->cliente());
        $caminho = '';

        $this->responder('manda o qr code', '5521999887766', 'text', [
            'upload' => static function (string $path, string $mime) use (&$caminho): string {
                $caminho = $path;
                self::assertFileExists($path);

                throw new RuntimeException('Meta fora do ar');
            },
        ]);

        self::assertNotSame('', $caminho, 'o upload nem foi tentado');
        self::assertFileDoesNotExist($caminho);
    }

    /** Sem a imagem, o cliente ainda recebe o aviso e o código copia e cola. */
    public function testFalhaNoUploadAindaEntregaOTextoComOPix(): void
    {
        $this->fatura($this->cliente());

        $this->responder('manda o qr code', '5521999887766', 'text', [
            'upload' => static function (string $path, string $mime): string {
                throw new RuntimeException('Meta fora do ar');
            },
        ]);

        $textos = $this->textosEnviados();
        self::assertCount(2, $textos);
        self::assertSame(self::PIX, $textos[1]);
    }

    public function testBase64InvalidoNaoQuebraNemSobeLixo(): void
    {
        $this->fatura($this->cliente());

        $this->responder('qr code', '5521999887766', 'text', [
            'pix' => static fn (string $id): array => ['payload' => self::PIX, 'encodedImage' => '%%%não é base64%%%'],
        ]);

        self::assertSame([], $this->uploads);
        self::assertCount(2, $this->textosEnviados());
    }
}
