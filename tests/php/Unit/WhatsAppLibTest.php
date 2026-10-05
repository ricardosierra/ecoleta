<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/whatsapp_lib.php';

/**
 * O cliente da Cloud API (`whatsapp_lib.php`) naquilo que dá para provar sem rede.
 */
final class WhatsAppLibTest extends TestCase
{
    /**
     * Upload de mídia sem prazo deixava a requisição do painel pendurada quando a
     * Meta aceitava a conexão e não respondia. Sem rede na suíte, o que se prova
     * é que as opções do cURL carregam os dois limites.
     */
    public function testUploadDeMidiaTemPrazoDeConexaoEDeResposta(): void
    {
        $opcoes = waUploadMediaCurlOptions('/tmp/qr.png', 'image/png', 'token-de-teste');

        self::assertGreaterThan(0, $opcoes[CURLOPT_TIMEOUT] ?? 0);
        self::assertGreaterThan(0, $opcoes[CURLOPT_CONNECTTIMEOUT] ?? 0);
        self::assertLessThanOrEqual(60, $opcoes[CURLOPT_TIMEOUT]);
    }

    public function testUploadDeMidiaMandaOTipoEONomeDoArquivo(): void
    {
        $opcoes = waUploadMediaCurlOptions('/tmp/pix_abc123', 'image/png', 'token-de-teste', 'qrcode-pix.png');

        $campos = $opcoes[CURLOPT_POSTFIELDS];
        self::assertSame('image/png', $campos['type']);
        self::assertSame('whatsapp', $campos['messaging_product']);
        self::assertSame('qrcode-pix.png', $campos['file']->getPostFilename());
        self::assertSame('image/png', $campos['file']->getMimeType());
        self::assertContains('Authorization: Bearer token-de-teste', $opcoes[CURLOPT_HTTPHEADER]);
    }

    public function testUploadDeMidiaSemNomeUsaOBasenameDoArquivo(): void
    {
        $opcoes = waUploadMediaCurlOptions('/tmp/pix_abc123', 'image/png', 'token-de-teste');

        self::assertSame('pix_abc123', $opcoes[CURLOPT_POSTFIELDS]['file']->getPostFilename());
    }

    // ── tipo de mídia, data URI e formatos de áudio ─────────────────────

    public function testDataUriSimplesSeparaTipoEConteudo(): void
    {
        self::assertSame(
            ['mime' => 'audio/ogg', 'data' => 'QUJD'],
            waParseDataUri('data:audio/ogg;base64,QUJD')
        );
    }

    /** O Chrome grava "data:audio/webm;codecs=opus;base64,...": o tipo traz parâmetros. */
    public function testDataUriComParametrosDoNavegador(): void
    {
        self::assertSame(
            ['mime' => 'audio/webm;codecs=opus', 'data' => 'QUJD'],
            waParseDataUri('data:audio/webm;codecs=opus;base64,QUJD')
        );
    }

    public function testDataUriSemOTipoNemQuebra(): void
    {
        self::assertSame(['mime' => '', 'data' => 'QUJD'], waParseDataUri('data:;base64,QUJD'));
    }

    public function testQuemNaoEDataUriVoltaNulo(): void
    {
        self::assertNull(waParseDataUri('QUJD'));
        self::assertNull(waParseDataUri('data:audio/ogg,QUJD'));
        self::assertNull(waParseDataUri('data:audio/ogg;base64'));
        // Vírgula longe demais: é base64 que por acaso começa com "data:".
        self::assertNull(waParseDataUri('data:' . str_repeat('A', 300) . ',QUJD'));
    }

    /** O conteúdo pode ter megabytes: o parse não pode depender de regex sobre ele. */
    public function testDataUriGrandeContinuaRapidoECorreto(): void
    {
        $conteudo = str_repeat('QUJD', 3_000_000);
        $uri = waParseDataUri('data:audio/mp4;base64,' . $conteudo);

        self::assertSame('audio/mp4', $uri['mime'] ?? null);
        self::assertSame(strlen($conteudo), strlen($uri['data'] ?? ''));
    }

    /** @return array<string, array{0:string}> */
    public static function audiosAceitosPelaMeta(): array
    {
        return [
            'ogg com opus' => ['audio/ogg;codecs=opus'],
            'ogg com opus e espaço' => ['audio/ogg; codecs=opus'],
            'ogg simples' => ['audio/ogg'],
            'ogg como application' => ['application/ogg'],
            'mp3' => ['audio/mpeg'],
            'mp3 pelo nome comum' => ['audio/mp3'],
            'aac' => ['audio/aac'],
            'amr' => ['audio/amr'],
            'mp4' => ['audio/mp4'],
            'm4a' => ['audio/x-m4a'],
            'maiúsculas' => ['AUDIO/MPEG'],
        ];
    }

    #[DataProvider('audiosAceitosPelaMeta')]
    public function testAudioAceitoNaoTemErro(string $mime): void
    {
        self::assertNull(waAudioFormatError($mime), $mime);
    }

    /** @return array<string, array{0:string}> */
    public static function audiosQueAMetaRecusa(): array
    {
        return [
            'webm do Chrome' => ['audio/webm;codecs=opus'],
            'webm simples' => ['audio/webm'],
            'webm de vídeo' => ['video/webm'],
            'wav' => ['audio/wav'],
            'flac' => ['audio/flac'],
            'sem tipo' => [''],
        ];
    }

    #[DataProvider('audiosQueAMetaRecusa')]
    public function testAudioRecusadoExplicaOsFormatosAceitos(string $mime): void
    {
        $erro = waAudioFormatError($mime);

        self::assertNotNull($erro, $mime);
        foreach (['ogg', 'opus', 'mp3', 'aac', 'amr', 'mp4'] as $formato) {
            self::assertStringContainsString($formato, $erro);
        }
        self::assertStringNotContainsString('corrompido', $erro);
    }

    public function testTipoDeUploadSemParametrosEComOggComOpus(): void
    {
        self::assertSame('audio/ogg; codecs=opus', waMediaUploadMime('audio/ogg'));
        self::assertSame('audio/ogg; codecs=opus', waMediaUploadMime('audio/ogg;codecs=opus'));
        self::assertSame('audio/ogg; codecs=opus', waMediaUploadMime('application/ogg'));
        self::assertSame('audio/mpeg', waMediaUploadMime('audio/mp3'));
        self::assertSame('audio/mp4', waMediaUploadMime('audio/x-m4a'));
        self::assertSame('image/png', waMediaUploadMime('image/png'));
        self::assertSame('text/plain', waMediaUploadMime('text/plain; charset=utf-8'));
    }

    // ── lista de templates com o status real ────────────────────────────

    /** @return array<string,mixed> */
    private static function templateDaMeta(string $nome, string $status, string $texto = 'Olá {{1}}, tudo bem?'): array
    {
        return [
            'name' => $nome,
            'status' => $status,
            'language' => 'pt_BR',
            'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => $texto]],
        ];
    }

    private static function respostaDaMeta(array ...$templates): string
    {
        return (string) json_encode(['data' => $templates]);
    }

    public function testListaMostraOStatusRealDeCadaTemplate(): void
    {
        $r = waParseTemplatesResponse(200, self::respostaDaMeta(
            self::templateDaMeta('em_analise', 'PENDING'),
            self::templateDaMeta('aprovado_ok', 'APPROVED'),
            self::templateDaMeta('reprovado', 'REJECTED')
        ));

        self::assertNull($r['error']);
        self::assertSame(
            ['aprovado_ok' => 'APPROVED', 'em_analise' => 'PENDING', 'reprovado' => 'REJECTED'],
            array_column($r['templates'], 'status', 'name')
        );
        // Aprovados primeiro: são os que a tela deixa enviar.
        self::assertSame('aprovado_ok', $r['templates'][0]['name']);
    }

    /** Só pendentes: antes a lista "vazia" caía num substituto que se dizia aprovado. */
    public function testSoTemplatesPendentesNaoViramAprovados(): void
    {
        $r = waParseTemplatesResponse(200, self::respostaDaMeta(
            self::templateDaMeta('nova_cobranca', 'PENDING', 'Fatura de {{1}}: R$ {{2}}')
        ));

        self::assertNull($r['error']);
        self::assertCount(1, $r['templates']);
        self::assertSame('PENDING', $r['templates'][0]['status']);
        self::assertSame(2, $r['templates'][0]['params_count']);
        self::assertSame('Fatura de {{1}}: R$ {{2}}', $r['templates'][0]['body_text']);
    }

    public function testStatusAusenteNaoViraAprovado(): void
    {
        $semStatus = self::templateDaMeta('sem_status', '');
        unset($semStatus['status']);

        $r = waParseTemplatesResponse(200, self::respostaDaMeta($semStatus));

        self::assertSame('UNKNOWN', $r['templates'][0]['status']);
    }

    public function testContaVariaveisSemRepetir(): void
    {
        $r = waParseTemplatesResponse(200, self::respostaDaMeta(
            self::templateDaMeta('repete', 'APPROVED', '{{1}} e de novo {{1}} com {{2}}')
        ));

        self::assertSame(2, $r['templates'][0]['params_count']);
    }

    public function testContaSemTemplatesNaoEhErro(): void
    {
        $r = waParseTemplatesResponse(200, self::respostaDaMeta());

        self::assertNull($r['error']);
        self::assertSame([], $r['templates']);
    }

    public function testTokenVencidoViraMensagemEmPortugues(): void
    {
        $r = waParseTemplatesResponse(401, (string) json_encode([
            'error' => ['message' => 'Error validating access token: Session has expired', 'code' => 190],
        ]));

        self::assertSame([], $r['templates']);
        self::assertStringContainsString('token', $r['error']);
        self::assertStringContainsString('permanente', $r['error']);
        self::assertStringContainsString('WHATSAPP_ACCESS_TOKEN', $r['error']);
        // O texto original da Meta fica à parte, para quem for investigar.
        self::assertSame('Error validating access token: Session has expired', $r['error_detail']);
    }

    public function testContaInexistenteApontaParaOIdentificador(): void
    {
        $r = waParseTemplatesResponse(400, (string) json_encode([
            'error' => ['message' => 'Unsupported get request.', 'code' => 100],
        ]));

        self::assertStringContainsString('WHATSAPP_BUSINESS_ACCOUNT_ID', $r['error']);
    }

    public function testOutroErroDaMetaTrazOStatusHttpEOCodigo(): void
    {
        $r = waParseTemplatesResponse(500, (string) json_encode([
            'error' => ['message' => 'An unknown error occurred', 'code' => 1],
        ]));

        self::assertStringContainsString('HTTP 500', $r['error']);
        self::assertStringContainsString('código 1', $r['error']);
        self::assertSame('An unknown error occurred', $r['error_detail']);
        self::assertSame([], $r['templates']);
    }

    public function testFalhaDeRedeViraMensagemEmPortugues(): void
    {
        $r = waParseTemplatesResponse(0, false, 'Could not resolve host');

        self::assertStringContainsString('Não foi possível falar com a Meta', $r['error']);
        self::assertSame('Could not resolve host', $r['error_detail']);
    }

    public function testRespostaSemListaEhFormatoInesperado(): void
    {
        $r = waParseTemplatesResponse(200, '<html>manutenção</html>');

        self::assertStringContainsString('formato inesperado', $r['error']);
        self::assertSame([], $r['templates']);
    }

    /** Sem nome configurado no servidor, nenhum template é inventado. */
    public function testSemNomeConfiguradoNaoHaTemplateLocal(): void
    {
        putenv('WHATSAPP_OS_TEMPLATE');
        putenv('WHATSAPP_BILLING_TEMPLATE');

        self::assertSame([], waConfiguredTemplates());
        self::assertSame([], waKnownTemplates());
    }

    public function testTemplatesConfiguradosSaemComoNaoVerificadosESemTextoInventado(): void
    {
        putenv('WHATSAPP_OS_TEMPLATE=meu_os');
        putenv('WHATSAPP_BILLING_TEMPLATE=minha_cobranca');

        try {
            $lista = waConfiguredTemplates();
        } finally {
            putenv('WHATSAPP_OS_TEMPLATE');
            putenv('WHATSAPP_BILLING_TEMPLATE');
        }

        self::assertSame(['meu_os', 'minha_cobranca'], array_column($lista, 'name'));
        foreach ($lista as $item) {
            self::assertSame('UNVERIFIED', $item['status']);
            self::assertSame('', $item['body_text']);
        }
        self::assertSame(['client_name', 'os_number', 'os_link'], $lista[0]['param_kinds']);
        self::assertSame(['client_name', 'amount', 'due_date', 'invoice_link'], $lista[1]['param_kinds']);
        self::assertCount(4, $lista[1]['param_labels']);
    }

    /** Template que a Meta devolve e o código conhece ganha rótulos; o desconhecido não. */
    public function testRotuloSoParaTemplateQueOCodigoConhece(): void
    {
        putenv('WHATSAPP_OS_TEMPLATE=meu_os');

        try {
            $r = waParseTemplatesResponse(200, self::respostaDaMeta(
                self::templateDaMeta('meu_os', 'APPROVED', 'Olá {{1}}, OS {{2}}: {{3}}'),
                self::templateDaMeta('promocao', 'APPROVED', 'Oi {{1}}, veja {{2}}'),
                // O nome é o configurado, mas o número de variáveis não bate: sem rótulo.
                self::templateDaMeta('meu_os', 'PENDING', 'Só {{1}}')
            ));
        } finally {
            putenv('WHATSAPP_OS_TEMPLATE');
        }

        [$conhecido, $desconhecido, $divergente] = $r['templates'];
        self::assertSame(['Nome do Cliente', 'Número da OS', 'Link da OS'], $conhecido['param_labels']);
        self::assertSame(['client_name', 'os_number', 'os_link'], $conhecido['param_kinds']);
        self::assertArrayNotHasKey('param_labels', $desconhecido);
        self::assertArrayNotHasKey('param_kinds', $desconhecido);
        self::assertArrayNotHasKey('param_labels', $divergente);
    }
}
