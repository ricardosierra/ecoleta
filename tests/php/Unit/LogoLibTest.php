<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/site/logo_lib.php';

/**
 * O tratamento de imagem das logos, exercitado com arquivos gerados na hora
 * pelo próprio GD — o mesmo motor que o servidor usa.
 */
final class LogoLibTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('extensão gd indisponível neste PHP');
        }

        $this->workDir = sys_get_temp_dir() . '/ecoleta_logo_' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);
    }

    private function criaPng(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 20, 120, 60));
        $path = $this->workDir . '/entrada.png';
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function criaJpeg(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 200, 40, 40));
        $path = $this->workDir . '/entrada.jpg';
        imagejpeg($img, $path);
        imagedestroy($img);

        return $path;
    }

    public function testImagemGrandeEncolheParaCaberNoLimite(): void
    {
        $origem = $this->criaPng(1200, 900);

        $res = ecoletaLogoProcess($origem, $this->workDir, 'Empresa Grande');

        self::assertTrue($res['ok'], $res['error'] ?? '');
        $info = getimagesize($this->workDir . '/' . $res['filename']);
        self::assertNotFalse($info);
        self::assertLessThanOrEqual(ECOLETA_LOGO_MAX_WIDTH, $info[0]);
        self::assertLessThanOrEqual(ECOLETA_LOGO_MAX_HEIGHT, $info[1]);
        // 1200×900 (4:3) limitado por altura: 360 de altura → 480 de largura.
        self::assertSame(480, $info[0]);
        self::assertSame(360, $info[1]);
    }

    public function testImagemPequenaNuncaEAmpliada(): void
    {
        $origem = $this->criaPng(180, 90);

        $res = ecoletaLogoProcess($origem, $this->workDir, 'Logo Pequena');

        self::assertTrue($res['ok']);
        $info = getimagesize($this->workDir . '/' . $res['filename']);
        self::assertSame([180, 90], [$info[0], $info[1]]);
    }

    public function testJpegEReencodadoNoFormatoDeSaidaDoServidor(): void
    {
        $origem = $this->criaJpeg(300, 200);

        $res = ecoletaLogoProcess($origem, $this->workDir, 'Empresa JPEG');

        self::assertTrue($res['ok']);
        // WebP onde o GD sabe escrever, PNG onde não sabe — nos dois casos o
        // que chega ao disco é um arquivo novo, não o JPEG enviado.
        $extensao = ecoletaLogoOutputExtension();
        self::assertStringEndsWith('.' . $extensao, $res['filename']);
        $info = getimagesize($this->workDir . '/' . $res['filename']);
        self::assertSame($extensao === 'webp' ? IMAGETYPE_WEBP : IMAGETYPE_PNG, $info[2]);
    }

    public function testSaiEmWebpQuandoOServidorSabeEscreverWebp(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD deste PHP não escreve WebP');
        }

        $res = ecoletaLogoProcess($this->criaPng(400, 200), $this->workDir, 'Empresa WebP');

        self::assertTrue($res['ok'], $res['error'] ?? '');
        self::assertStringEndsWith('.webp', $res['filename']);
        self::assertSame(IMAGETYPE_WEBP, getimagesize($this->workDir . '/' . $res['filename'])[2]);
    }

    public function testNomeDeArquivoSaiDoNomeDaEmpresaSemAcentosNemEspacos(): void
    {
        $origem = $this->criaPng(100, 100);

        $res = ecoletaLogoProcess($origem, $this->workDir, 'Café & Cia Ltda.');

        self::assertTrue($res['ok']);
        self::assertMatchesRegularExpression(
            '/^cafe-cia-ltda-[0-9a-f]{6}\.' . preg_quote(ecoletaLogoOutputExtension(), '/') . '$/',
            $res['filename']
        );
    }

    public function testArquivoQueNaoEImagemERecusadoNaValidacao(): void
    {
        $path = $this->workDir . '/nao-imagem.png';
        file_put_contents($path, '<?php echo "payload"; ?>');

        $erro = ecoletaLogoValidateUpload([
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
            'tmp_name' => $path,
        ]);

        self::assertSame('O arquivo enviado não é uma imagem válida.', $erro);
    }

    public function testUploadAcimaDoLimiteDeTamanhoERecusado(): void
    {
        $erro = ecoletaLogoValidateUpload([
            'error' => UPLOAD_ERR_OK,
            'size' => ECOLETA_LOGO_MAX_BYTES + 1,
            'tmp_name' => $this->criaPng(10, 10),
        ]);

        self::assertSame('Imagem muito grande — o limite é 4 MB.', $erro);
    }

    /** Canvas truecolor com alfa, todo transparente, com um retângulo opaco. */
    private function criaPngComRetangulo(int $width, int $height, ?array $bg, int $x, int $y, int $w, int $h): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $fundo = $bg === null
            ? imagecolorallocatealpha($img, 0, 0, 0, 127)
            : imagecolorallocate($img, $bg[0], $bg[1], $bg[2]);
        imagefill($img, 0, 0, (int) $fundo);
        imagefilledrectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, (int) imagecolorallocate($img, 200, 30, 30));
        $path = $this->workDir . '/retangulo.png';
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    /** Abre o arquivo gravado sem supor o formato — pode ser WebP ou PNG. */
    private function abreSaida(array $res): GdImage
    {
        $conteudo = file_get_contents($this->workDir . '/' . $res['filename']);
        self::assertNotFalse($conteudo);
        $img = imagecreatefromstring($conteudo);
        self::assertInstanceOf(GdImage::class, $img);

        return $img;
    }

    private function dimensoes(array $res): array
    {
        self::assertTrue($res['ok'], $res['error'] ?? '');
        $info = getimagesize($this->workDir . '/' . $res['filename']);
        self::assertNotFalse($info);

        return [$info[0], $info[1]];
    }

    public function testMargensTransparentesSaoAparadas(): void
    {
        $origem = $this->criaPngComRetangulo(400, 300, null, 50, 100, 100, 50);

        $res = ecoletaLogoProcess($origem, $this->workDir, 'Transparente');

        self::assertSame([100, 50], $this->dimensoes($res));
    }

    public function testMargensBrancasSaoAparadas(): void
    {
        $origem = $this->criaPngComRetangulo(300, 200, [255, 255, 255], 20, 30, 60, 40);

        $res = ecoletaLogoProcess($origem, $this->workDir, 'Fundo Branco');

        self::assertSame([60, 40], $this->dimensoes($res));
    }

    public function testFundoQuaseBrancoTambemConta(): void
    {
        $origem = $this->criaPngComRetangulo(300, 200, [247, 247, 247], 20, 30, 60, 40);

        $res = ecoletaLogoProcess($origem, $this->workDir, 'Fundo Cinza Claro');

        self::assertSame([60, 40], $this->dimensoes($res));
    }

    public function testFundoColoridoFicaComoEsta(): void
    {
        // Caixa verde é parte da marca (caso Vibra): nada é aparado.
        $origem = $this->criaPngComRetangulo(300, 200, [96, 229, 50], 20, 30, 60, 40);

        $res = ecoletaLogoProcess($origem, $this->workDir, 'Fundo Verde');

        self::assertSame([300, 200], $this->dimensoes($res));
    }

    public function testImagemSemConteudoNaoEAparada(): void
    {
        // Tudo transparente: nada a recortar, sai inteira em vez de dar erro.
        $img = imagecreatetruecolor(200, 100);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 0, 0, 0, 127));
        $path = $this->workDir . '/vazia.png';
        imagepng($img, $path);
        imagedestroy($img);

        $res = ecoletaLogoProcess($path, $this->workDir, 'Vazia');

        self::assertSame([200, 100], $this->dimensoes($res));
    }

    public function testPngDePaletaComCorTransparenteViraAlfa(): void
    {
        // Magenta é a cor transparente da paleta. Sem converter para alfa, o
        // GD reamostraria como magenta sólido — e nada seria aparado.
        $img = imagecreate(200, 100);
        $fundo = imagecolorallocate($img, 255, 0, 255);
        imagecolortransparent($img, (int) $fundo);
        imagefilledrectangle($img, 75, 40, 124, 59, (int) imagecolorallocate($img, 20, 20, 20));
        $path = $this->workDir . '/paleta.png';
        imagepng($img, $path);
        imagedestroy($img);

        $res = ecoletaLogoProcess($path, $this->workDir, 'Paleta');

        self::assertSame([50, 20], $this->dimensoes($res));

        // A imagem final tem alfa de verdade: canto transparente, miolo opaco.
        $saida = $this->abreSaida($res);
        self::assertTrue(imageistruecolor($saida));
        self::assertSame(0, (imagecolorat($saida, 25, 10) >> 24) & 0x7F);
    }

    public function testPngRgbComCorTransparenteViraAlfa(): void
    {
        // PNG RGB com chunk tRNS (fundo preto marcado como transparente): é o
        // formato das logos versionadas do site. imagecopyresampled ignora a
        // marca em imagem truecolor e devolveria um bloco preto.
        $img = imagecreatetruecolor(200, 100);
        $preto = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, (int) $preto);
        imagecolortransparent($img, (int) $preto);
        imagefilledrectangle($img, 75, 40, 124, 59, (int) imagecolorallocate($img, 200, 30, 30));
        $path = $this->workDir . '/trns.png';
        imagepng($img, $path);
        imagedestroy($img);

        $recarregada = imagecreatefrompng($path);
        if (imagecolortransparent($recarregada) < 0) {
            self::markTestSkipped('este GD não preserva tRNS em PNG truecolor');
        }

        $res = ecoletaLogoProcess($path, $this->workDir, 'tRNS');

        self::assertSame([50, 20], $this->dimensoes($res));
        $saida = $this->abreSaida($res);
        self::assertSame(0, (imagecolorat($saida, 25, 10) >> 24) & 0x7F);
    }

    public function testDeleteSoAlcancaArquivosDeDentroDeUploads(): void
    {
        $uploadsDir = $this->workDir . '/uploads';
        mkdir($uploadsDir, 0700, true);
        putenv('ECOLETA_UPLOADS_DIR=' . $uploadsDir);

        try {
            // Arquivo FORA do diretório de uploads: nenhum logo_url o alcança.
            $protegido = $this->criaPng(10, 10);
            ecoletaLogoDeleteByUrl('/logos/heineken.png');
            ecoletaLogoDeleteByUrl('https://exemplo.com/uploads/logos/x.png');
            ecoletaLogoDeleteByUrl('/uploads/logos/../entrada.png');
            self::assertFileExists($protegido);

            $enviado = $uploadsDir . '/empresa-abc123.png';
            copy($protegido, $enviado);
            ecoletaLogoDeleteByUrl('/uploads/logos/empresa-abc123.png');
            self::assertFileDoesNotExist($enviado);
        } finally {
            putenv('ECOLETA_UPLOADS_DIR');
            @rmdir($uploadsDir);
        }
    }
}
