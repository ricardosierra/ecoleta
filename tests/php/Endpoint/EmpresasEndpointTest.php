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
        // A sessão de sessaoAdmin() aponta para o id 1. A API confere o usuário no
        // banco a cada requisição, então a conta tem que existir (e ser root).
        $this->db->seedUser('admin', 'senha-root-123', 'root');
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
        if (PHP_VERSION_ID < 80500) {
            imagedestroy($img);
        }

        return $path;
    }

    public function testListagemEPublicaEDevolveSoAsEmpresasAtivas(): void
    {
        $this->seedEmpresa('Heineken');
        // Desativada pelo painel: não pode aparecer para o visitante.
        $this->seedEmpresa('Escondida', '/logos/escondida.png', 0);

        $res = Endpoint::call('site/empresas.php', [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertCount(1, $res->json()['companies']);
        self::assertSame('Heineken', $res->json()['companies'][0]['name'] ?? null);
    }

    public function testListagemDoAdminIncluiAsEmpresasDesativadas(): void
    {
        $this->seedEmpresa('Heineken');
        $this->seedEmpresa('Escondida', '/logos/escondida.png', 0);

        $res = Endpoint::call('site/empresas.php', [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
        ]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame(['Escondida', 'Heineken'], array_column($res->json()['companies'], 'name'));
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
            '#^/uploads/logos/cafe-cia-[0-9a-f]{6}\.' . preg_quote($this->extensaoDeSaida(), '#') . '$#',
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
        self::assertSame($this->extensaoDeSaida() === 'webp' ? IMAGETYPE_WEBP : IMAGETYPE_PNG, $info[2]);
    }

    /** O formato que o endpoint grava depende do GD deste PHP, como no lib. */
    private function extensaoDeSaida(): string
    {
        return function_exists('imagewebp') ? 'webp' : 'png';
    }

    public function testCreateMultipartSemCabecalhoContentTypeAindaLeOFormulario(): void
    {
        // Nem todo SAPI expõe CONTENT_TYPE; com $_POST/$_FILES preenchidos o
        // corpo era um formulário de qualquer jeito.
        $envio = $this->criaPngTemporario(300, 120);

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'post' => ['action' => 'create', 'name' => 'Sem Cabeçalho'],
            'files' => [
                'logo' => [
                    'name' => 'logo.png',
                    'type' => 'image/png',
                    'tmp_name' => $envio,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($envio),
                ],
            ],
            'server' => ['CONTENT_TYPE' => ''],
            'env' => ['ECOLETA_UPLOADS_DIR' => $this->uploadsDir],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertStringStartsWith('/uploads/logos/sem-cabecalho-', (string) ($res->json()['logo_url'] ?? ''));
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

    public function testUpdateChangesNameAndLogoWithoutChangingVisibility(): void
    {
        $id = $this->seedEmpresa('Antes', '/logos/antes.png', 0);
        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(), 'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'update', 'id' => $id, 'name' => 'Depois', 'logo_url' => '/logos/depois.png'],
        ]);
        self::assertSame(200, $res->status, $res->body);
        $row = $this->db->rows('site_clients')[0];
        self::assertSame('Depois', $row['name']);
        self::assertSame('/logos/depois.png', $row['logo_url']);
        self::assertSame(0, (int) $row['is_active']);
    }

    public function testUpdateComNovaImagemApagaAAnteriorDeUploads(): void
    {
        $antiga = $this->uploadsDir . '/antiga-abc123.png';
        copy($this->criaPngTemporario(300, 120), $antiga);
        $id = $this->seedEmpresa('Troca', '/uploads/logos/antiga-abc123.png');
        $envio = $this->criaPngTemporario(300, 120);

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'post' => ['action' => 'update', 'id' => (string) $id, 'name' => 'Troca'],
            'files' => [
                'logo' => [
                    'name' => 'nova.png',
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

        $novaUrl = (string) ($res->json()['logo_url'] ?? '');
        self::assertNotSame('/uploads/logos/antiga-abc123.png', $novaUrl);
        self::assertFileExists($this->uploadsDir . '/' . basename($novaUrl));
        // Sem isto o diretório de uploads só cresce: nome com hash nunca volta.
        self::assertFileDoesNotExist($antiga);
    }

    public function testUpdateNaoApagaImagemQueOutraEmpresaAindaUsa(): void
    {
        $compartilhada = $this->uploadsDir . '/compartilhada-abc123.png';
        copy($this->criaPngTemporario(300, 120), $compartilhada);
        $id = $this->seedEmpresa('Primeira', '/uploads/logos/compartilhada-abc123.png');
        $this->seedEmpresa('Segunda', '/uploads/logos/compartilhada-abc123.png');
        $envio = $this->criaPngTemporario(300, 120);

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'post' => ['action' => 'update', 'id' => (string) $id, 'name' => 'Primeira'],
            'files' => [
                'logo' => [
                    'name' => 'nova.png',
                    'type' => 'image/png',
                    'tmp_name' => $envio,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($envio),
                ],
            ],
            'env' => ['ECOLETA_UPLOADS_DIR' => $this->uploadsDir],
        ]);

        self::assertSame(200, $res->status, $res->body);
        self::assertFileExists($compartilhada);
    }

    public function testDeleteNaoApagaArquivoQueOutraEmpresaAindaUsa(): void
    {
        // Duas empresas apontando, por caminho informado à mão, para o mesmo
        // arquivo de uploads: excluir uma não pode quebrar a logo da outra.
        $compartilhada = $this->uploadsDir . '/compartilhada-abc123.png';
        copy($this->criaPngTemporario(300, 120), $compartilhada);
        $primeira = $this->seedEmpresa('Primeira', '/uploads/logos/compartilhada-abc123.png');
        $segunda = $this->seedEmpresa('Segunda', '/uploads/logos/compartilhada-abc123.png');

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'delete', 'id' => $primeira],
            'env' => ['ECOLETA_UPLOADS_DIR' => $this->uploadsDir],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame(1, $this->db->count('site_clients'));
        self::assertFileExists($compartilhada);

        // Quando a última sai, o arquivo vai junto: ninguém mais o usa.
        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'delete', 'id' => $segunda],
            'env' => ['ECOLETA_UPLOADS_DIR' => $this->uploadsDir],
        ]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame(0, $this->db->count('site_clients'));
        self::assertFileDoesNotExist($compartilhada);
    }

    public function testUpdateTrocandoPorCaminhoManualApagaAImagemEnviadaAnterior(): void
    {
        // Antes só o upload de uma imagem nova limpava a anterior; trocar por um
        // caminho informado à mão deixava o arquivo órfão em uploads/logos/.
        $antiga = $this->uploadsDir . '/antiga-abc123.png';
        copy($this->criaPngTemporario(300, 120), $antiga);
        $id = $this->seedEmpresa('Troca Manual', '/uploads/logos/antiga-abc123.png');

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'update', 'id' => $id, 'name' => 'Troca Manual', 'logo_url' => '/logos/nova.png'],
            'env' => ['ECOLETA_UPLOADS_DIR' => $this->uploadsDir],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame('/logos/nova.png', $this->db->rows('site_clients')[0]['logo_url']);
        self::assertFileDoesNotExist($antiga);
    }

    public function testUpdateTrocandoPorCaminhoManualNaoApagaImagemQueOutraEmpresaUsa(): void
    {
        $compartilhada = $this->uploadsDir . '/compartilhada-abc123.png';
        copy($this->criaPngTemporario(300, 120), $compartilhada);
        $id = $this->seedEmpresa('Primeira', '/uploads/logos/compartilhada-abc123.png');
        $this->seedEmpresa('Segunda', '/uploads/logos/compartilhada-abc123.png');

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'update', 'id' => $id, 'name' => 'Primeira', 'logo_url' => '/logos/nova.png'],
            'env' => ['ECOLETA_UPLOADS_DIR' => $this->uploadsDir],
        ]);

        self::assertSame(200, $res->status, $res->body);
        self::assertFileExists($compartilhada);
    }

    public function testUpdateQueSoMudaONomeNaoApagaALogoEnviada(): void
    {
        $arquivo = $this->uploadsDir . '/mesma-abc123.png';
        copy($this->criaPngTemporario(300, 120), $arquivo);
        $id = $this->seedEmpresa('Nome Antigo', '/uploads/logos/mesma-abc123.png');

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'update', 'id' => $id, 'name' => 'Nome Novo', 'logo_url' => '/uploads/logos/mesma-abc123.png'],
            'env' => ['ECOLETA_UPLOADS_DIR' => $this->uploadsDir],
        ]);

        self::assertSame(200, $res->status, $res->body);
        self::assertFileExists($arquivo);
    }

    public function testCreateMultipartRecusaImagemComDimensoesGigantes(): void
    {
        // PNG de 1 bit declarando 20000×20000: pequeno no disco, mas o GD
        // alocaria cerca de 1,6 GB (fora do memory_limit) para decodificá-lo.
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $gigante = $this->uploadsDir . '/gigante.png';
        file_put_contents(
            $gigante,
            "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 20000, 20000, 1, 0, 0, 0, 0)) . $chunk('IEND', '')
        );

        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoAdmin(),
            'post' => ['action' => 'create', 'name' => 'Gigante'],
            'files' => [
                'logo' => [
                    'name' => 'gigante.png',
                    'type' => 'image/png',
                    'tmp_name' => $gigante,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($gigante),
                ],
            ],
            'env' => ['ECOLETA_UPLOADS_DIR' => $this->uploadsDir],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertStringContainsString('20000×20000', (string) $res->error());
        self::assertSame(0, $this->db->count('site_clients'));
        self::assertSame([], glob($this->uploadsDir . '/gigante-*') ?: []);
    }

    public function testUpdateRejectsDuplicateName(): void
    {
        $id = $this->seedEmpresa('Antes');
        $this->seedEmpresa('Existente');
        $res = Endpoint::call('site/empresas.php', [
            'dsn' => $this->db->dsn(), 'session' => $this->sessaoAdmin(),
            'body' => ['action' => 'update', 'id' => $id, 'name' => 'Existente', 'logo_url' => '/logos/x.png'],
        ]);
        self::assertSame(409, $res->status, $res->body);
        self::assertSame('Antes', $this->db->rows('site_clients')[0]['name']);
    }
}
