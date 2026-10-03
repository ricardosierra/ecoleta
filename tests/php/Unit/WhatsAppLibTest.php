<?php
declare(strict_types=1);

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
}
