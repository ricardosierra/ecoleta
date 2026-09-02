<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * O CRUD de empresas parceiras (site/empresas.php) rodando o arquivo real:
 * listagem pública, escrita só para admin, upload multipart com tratamento e
 * a proteção contra o cadastro duplicado que já aconteceu em produção.
 */
final class EmpresasEndpointTest extends TestCase
{
    private TestDatabase $db;

    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        $this->uploadsDir = sys_get_temp_dir() . '/ecoleta_uploads_' . bin2hex(random_bytes(6));
        mkdir($this->uploadsDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
        foreach (glob($this->uploadsDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->uploadsDir);
    }

    private function sessaoAdmin(): array
    {
        return ['user_id' => 1, 'role' => 'root', 'login' => 'admin'];
    }

    private function seedEmpresa(string $name, string $logoUrl = '/logos/x.png', int $ativo = 1): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO site_clients (name, logo_url, is_active) VALUES (?, ?, ?)'
        );
        $stmt->execute([$name, $logoUrl, $ativo]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** PNG legítimo gerado pelo GD, para os cenários de upload. */
    private function criaPngTemporario(int $width = 800, int $height = 500): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 10, 90, 40));
        $path = $this->uploadsDir . '/envio-' . bin2hex(random_bytes(4)) . '.png';
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    public function testListagemEPublicaEDevolveAsEmpresas(): void
    {
        $this->seedEmpresa('Heineken');

        $res = Endpoint::call('site/empresas.php', [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame('Heineken', $res->json()['companies'][0]['name'] ?? null);
    }

    public function testEscritaExigePapelAdmin(): void
    {
        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => ['user_id' => 2, 'role' => 'user', 'login' => 'joao'],
            'body' => ['action' => 'create', 'name' => 'Nova', 'logo_url' => '/logos/n.png'],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(403, $res->status, $res->body);
        self::assertSame(0, $this->db->count('site_clients'));
    }

    public function testCreateComCaminhoJsonContinuaFuncionando(): void
    {
        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'create', 'name' => 'Vibra', 'logo_url' => '/logos/vibra.png'],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        $rows = $this->db->rows('site_clients');
        self::assertCount(1, $rows);
        self::assertSame('/logos/vibra.png', $rows[0]['logo_url']);
    }

    public function testCreateRecusaNomeJaCadastrado(): void
    {
        $this->seedEmpresa('Heineken');

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'create', 'name' => 'Heineken', 'logo_url' => '/logos/h2.png'],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(409, $res->status, $res->body);
        self::assertSame(1, $this->db->count('site_clients'));
    }

    public function testCreateRecusaCaminhoDeLogoInvalido(): void
    {
        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'create', 'name' => 'Estranha', 'logo_url' => 'javascript:alert(1)'],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertSame(0, $this->db->count('site_clients'));
    }

    public function testCreateMultipartProcessaOUploadEGravaAUrl(): void
    {
        $envio = $this->criaPngTemporario(800, 500);

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'post' => ['action' => 'create', 'name' => 'Café & Cia'],
            'files' => [
                'logo' => [
                    'name' => 'logo-original.png',
                    'type' => 'image/png',
                    'tmp_name' => $envio,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($envio),
                ],
            ],
            'env' => ['ECOLETA_UPLOADS_DIR' => $this->uploadsDir],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);

        $json = $res->json();
        self::assertMatchesRegularExpression(
            '#^/uploads/logos/cafe-cia-[0-9a-f]{6}\.png$#',
            (string) ($json['logo_url'] ?? '')
        );

        $rows = $this->db->rows('site_clients');
        self::assertCount(1, $rows);
        self::assertSame($json['logo_url'], $rows[0]['logo_url']);

        // O arquivo tratado existe e foi reduzido para caber em 600×360.
        $salvo = $this->uploadsDir . '/' . basename((string) $json['logo_url']);
        self::assertFileExists($salvo);
        $info = getimagesize($salvo);
        self::assertNotFalse($info);
        self::assertLessThanOrEqual(600, $info[0]);
        self::assertLessThanOrEqual(360, $info[1]);
        self::assertSame(IMAGETYPE_PNG, $info[2]);
    }

    public function testCreateMultipartRecusaArquivoQueNaoEImagem(): void
    {
        $falso = $this->uploadsDir . '/falso.png';
        file_put_contents($falso, '<?php echo "payload";');

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'post' => ['action' => 'create', 'name' => 'Maliciosa'],
            'files' => [
                'logo' => [
                    'name' => 'falso.png',
                    'type' => 'image/png',
                    'tmp_name' => $falso,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($falso),
                ],
            ],
            'env' => ['ECOLETA_UPLOADS_DIR' => $this->uploadsDir],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertSame(0, $this->db->count('site_clients'));
    }

    public function testToggleAtualizaEDevolveONovoStatus(): void
    {
        $id = $this->seedEmpresa('Heineken', '/logos/h.png', 1);

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'toggle_active', 'id' => $id, 'is_active' => 0],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame(0, (int) $res->json()['is_active']);
        self::assertSame(0, (int) $this->db->rows('site_clients')[0]['is_active']);
    }

    public function testToggleDeEmpresaInexistenteDevolve404(): void
    {
        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'toggle_active', 'id' => 999, 'is_active' => 0],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(404, $res->status, $res->body);
    }

    public function testDeleteRemoveALinhaEOArquivoEnviado(): void
    {
        $arquivo = $this->uploadsDir . '/empresa-abc123.png';
        copy($this->criaPngTemporario(50, 50), $arquivo);
        $id = $this->seedEmpresa('Enviada', '/uploads/logos/empresa-abc123.png');

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'delete', 'id' => $id],
            'env' => ['ECOLETA_UPLOADS_DIR' => $this->uploadsDir],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame(0, $this->db->count('site_clients'));
        self::assertFileDoesNotExist($arquivo);
    }

    public function testDeleteNaoTocaEmLogoVersionadaDoSite(): void
    {
        $id = $this->seedEmpresa('Estática', '/logos/heineken.png');

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'delete', 'id' => $id],
            'env' => ['ECOLETA_UPLOADS_DIR' => $this->uploadsDir],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame(0, $this->db->count('site_clients'));
    }
    public function testCreateMultipartComArrayEmLogoNaoCausaFatalErrorEDevolve400(): void
    {
        $envio = $this->criaPngTemporario(100, 100);

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'post' => ['action' => 'create', 'name' => 'Array Logo Test'],
            'files' => [
                'logo' => [
                    'name' => ['logo.png'],
                    'type' => ['image/png'],
                    'tmp_name' => [$envio],
                    'error' => [UPLOAD_ERR_OK],
                    'size' => [filesize($envio)],
                ],
            ],
            'env' => ['ECOLETA_UPLOADS_DIR' => $this->uploadsDir],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertSame(0, $this->db->count('site_clients'));
    }

    public function testFalhaDeBancoNaoIntegridadeDevolve500ENaoMascaraComoNomeDuplicado(): void
    {
        $this->db->pdo()->exec(
            "CREATE TRIGGER aborta_insert BEFORE INSERT ON site_clients BEGIN SELECT funcao_inexistente(); END;"
        );

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'create', 'name' => 'Empresa Banco Falha', 'logo_url' => '/logos/teste.png'],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(500, $res->status, $res->body);
        $json = $res->json();
        self::assertNotSame('Já existe uma empresa com esse nome.', $json['error'] ?? '');
        self::assertSame('Erro interno ao cadastrar empresa.', $json['error'] ?? '');
    }

    public function testFalhaDeIntegridade23000NoInsertDevolve409ComMensagemDeNomeDuplicado(): void
    {
        $this->db->pdo()->exec(
            "CREATE TRIGGER corrida_duplicado BEFORE INSERT ON site_clients BEGIN SELECT RAISE(ABORT, \"UNIQUE constraint failed: site_clients.name\"); END;"
        );

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'create', 'name' => 'Empresa Corrida', 'logo_url' => '/logos/teste.png'],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(409, $res->status, $res->body);
        $json = $res->json();
        self::assertSame('Já existe uma empresa com esse nome.', $json['error'] ?? '');
    }
}
