<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * public/api/groups/index.php (criação) e groups/edit.php: a URL do Power BI.
 *
 * A URL vira o `src` de um <iframe> no dashboard. Aceitar qualquer texto
 * deixava um administrador (ou alguém com a sessão dele) gravar `javascript:` ou
 * `data:` ali, e o iframe executaria no navegador de todo usuário do grupo.
 */
final class GroupsEndpointTest extends TestCase
{
    private TestDatabase $db;

    private int $rootId;

    private int $grupoId;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        $this->rootId = $this->db->seedUser('admin', 'senha-root-123', 'root');
        $this->grupoId = $this->db->seedGroup('Coleta', 'https://app.powerbi.com/view?r=original');
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    private function chamar(string $script, array $body): EndpointResponse
    {
        return Endpoint::call($script, [
            'dsn' => $this->db->dsn(),
            'session' => ['user_id' => $this->rootId, 'role' => 'root', 'login' => 'admin'],
            'body' => $body,
        ]);
    }

    private function editar(string $url): EndpointResponse
    {
        return $this->chamar('groups/edit.php', ['group_id' => $this->grupoId, 'name' => 'Coleta', 'powerbi_url' => $url]);
    }

    private function criar(string $url): EndpointResponse
    {
        return $this->chamar('groups/index.php', ['name' => 'Infectantes', 'powerbi_url' => $url]);
    }

    private function urlGravada(): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT powerbi_url FROM `groups` WHERE id = ?');
        $stmt->execute([$this->grupoId]);
        $valor = $stmt->fetchColumn();

        return $valor === false || $valor === null ? null : (string) $valor;
    }

    /** @return array<string, array{0:string}> */
    public static function urlsRecusadas(): array
    {
        return [
            'javascript' => ['javascript:alert(document.cookie)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'http puro' => ['http://app.powerbi.com/view?r=abc'],
            'sem esquema' => ['app.powerbi.com/view?r=abc'],
            'esquema relativo' => ['//app.powerbi.com/view?r=abc'],
            'ftp' => ['ftp://app.powerbi.com/relatorio'],
            'texto solto' => ['isto nao e url'],
            'https sem host' => ['https:///view?r=abc'],
            'credencial embutida' => ['https://app.powerbi.com@evil.example/view'],
            'com espaco' => ['https://app.powerbi.com/view?r=a b'],
            'iframe com javascript' => ['<iframe src="javascript:alert(1)"></iframe>'],
        ];
    }

    #[DataProvider('urlsRecusadas')]
    public function testEdicaoRecusaUrlQueNaoEHttps(string $url): void
    {
        $resposta = $this->editar($url);

        self::assertSame(400, $resposta->status, $resposta->body);
        self::assertStringContainsString('https', (string) $resposta->error());
        self::assertSame('https://app.powerbi.com/view?r=original', $this->urlGravada(), 'a URL recusada foi gravada');
    }

    #[DataProvider('urlsRecusadas')]
    public function testCriacaoRecusaUrlQueNaoEHttps(string $url): void
    {
        $resposta = $this->criar($url);

        self::assertSame(400, $resposta->status, $resposta->body);
        self::assertSame(1, $this->db->count('groups'), 'o grupo foi criado com uma URL recusada');
    }

    public function testEdicaoAceitaUrlHttps(): void
    {
        $resposta = $this->editar('https://app.powerbi.com/view?r=novo&pageName=ReportSection');

        self::assertSame(200, $resposta->status, $resposta->body);
        self::assertSame('https://app.powerbi.com/view?r=novo&pageName=ReportSection', $this->urlGravada());
    }

    /** Quem cola a tag <iframe> inteira continua funcionando: o src é extraído e validado. */
    public function testEdicaoAceitaIframeColadoComSrcHttps(): void
    {
        $resposta = $this->editar('<iframe title="x" width="600" src="https://app.powerbi.com/view?r=abc" frameborder="0"></iframe>');

        self::assertSame(200, $resposta->status, $resposta->body);
        self::assertSame('https://app.powerbi.com/view?r=abc', $this->urlGravada());
    }

    public function testEdicaoComUrlVaziaContinuaPermitidaELimpaOCampo(): void
    {
        $resposta = $this->editar('');

        self::assertSame(200, $resposta->status, $resposta->body);
        self::assertNull($this->urlGravada());
    }

    public function testCriacaoAceitaUrlHttpsEUrlVazia(): void
    {
        self::assertSame(200, $this->criar('https://app.powerbi.com/view?r=abc')->status);

        $semUrl = $this->chamar('groups/index.php', ['name' => 'Sem relatorio', 'powerbi_url' => '']);
        self::assertSame(200, $semUrl->status, $semUrl->body);
        self::assertSame(3, $this->db->count('groups'));
    }
}
