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

    public function testJpegEReencodadoComoPng(): void
    {
        $origem = $this->criaJpeg(300, 200);

        $res = ecoletaLogoProcess($origem, $this->workDir, 'Empresa JPEG');

        self::assertTrue($res['ok']);
        self::assertStringEndsWith('.png', $res['filename']);
        $info = getimagesize($this->workDir . '/' . $res['filename']);
        self::assertSame(IMAGETYPE_PNG, $info[2]);
    }

    public function testNomeDeArquivoSaiDoNomeDaEmpresaSemAcentosNemEspacos(): void
    {
        $origem = $this->criaPng(100, 100);

        $res = ecoletaLogoProcess($origem, $this->workDir, 'Café & Cia Ltda.');

        self::assertTrue($res['ok']);
        self::assertMatchesRegularExpression('/^cafe-cia-ltda-[0-9a-f]{6}\.png$/', $res['filename']);
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
