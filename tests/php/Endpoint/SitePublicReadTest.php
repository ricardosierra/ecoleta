<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A leitura PÚBLICA de public/api/site/empresas.php e indicadores.php, do jeito
 * que o servidor a recebe: um visitante sem cookie.
 *
 * Por que não usar Endpoint::call() aqui: o harness abre e semeia uma sessão
 * ANTES do script rodar, e com a sessão já ativa nada distingue "o endpoint abriu
 * uma sessão para o visitante" de "não abriu". O que este teste precisa
 * enxergar é justamente isso, então o processo filho roda sem harness, só com um
 * prólogo mínimo que monta a requisição, e a pasta de sessões é observada: sessão
 * aberta deixa um arquivo lá, sessão não aberta não deixa nada.
 */
final class SitePublicReadTest extends TestCase
{
    private TestDatabase $db;

    private string $work;

    private string $sessions;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        $this->work = sys_get_temp_dir() . '/ecoleta_sitepub_' . bin2hex(random_bytes(6));
        $this->sessions = $this->work . '/sessions';
        mkdir($this->sessions, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
        foreach (glob($this->work . '/{,sessions/}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->sessions);
        @rmdir($this->work);
    }

    private function seedEmpresa(string $name, string $logoUrl, int $ativo): void
    {
        $this->db->pdo()
            ->prepare('INSERT INTO site_clients (name, logo_url, is_active) VALUES (?, ?, ?)')
            ->execute([$name, $logoUrl, $ativo]);
    }

    /** Cria no disco uma sessão como a que o login deixa, e devolve o id. */
    private function plantaSessao(string $role): string
    {
        $id = bin2hex(random_bytes(16));
        $dados = sprintf(
            'user_id|i:1;role|s:%d:"%s";login|s:5:"admin";csrf_token|s:64:"%s";last_activity|i:%d;',
            strlen($role),
            $role,
            str_repeat('a', 64),
            time()
        );
        file_put_contents($this->sessions . '/sess_' . $id, $dados);

        return $id;
    }

    /** @return list<string> arquivos que o endpoint deixou na pasta de sessões */
    private function arquivosDeSessao(): array
    {
        return array_map('basename', glob($this->sessions . '/sess_*') ?: []);
    }

    /**
     * GET no endpoint, sem harness. `$cookie` é o id de sessão que o navegador
     * mandaria; null é o visitante comum.
     *
     * @return array{status: int, json: array<string,mixed>, body: string}
     */
    private function get(string $script, ?string $cookie = null): array
    {
        $prologo = $this->work . '/prologo.php';
        file_put_contents($prologo, '<?php
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REMOTE_ADDR"] = "203.0.113.10";
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["SCRIPT_NAME"] = "/api/site/' . $script . '";
' . ($cookie !== null ? '$_COOKIE["ECOLETA_SESSION"] = "' . $cookie . '";
session_id("' . $cookie . '");
' : '') . 'register_shutdown_function(static function () {
    fwrite(STDERR, "STATUS=" . (http_response_code() ?: 200));
});
');

        $process = proc_open(
            [
                PHP_BINARY,
                '-d', 'auto_prepend_file=' . $prologo,
                '-d', 'session.save_path=' . $this->sessions,
                '-d', 'display_errors=0',
                '-d', 'log_errors=0',
                ECOLETA_API_DIR . '/site/' . $script,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            ECOLETA_ROOT,
            [
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                'ECOLETA_ENV_FILE' => 'none',
                'DB_DSN' => $this->db->dsn(),
            ]
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $body = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $status = preg_match('/STATUS=(\d+)/', $stderr, $m) ? (int) $m[1] : 0;
        $json = json_decode($body, true);

        return ['status' => $status, 'json' => is_array($json) ? $json : [], 'body' => $body];
    }

    /** @return list<string> */
    private function nomes(array $resposta): array
    {
        return array_map(static fn (array $c): string => (string) $c['name'], $resposta['json']['companies'] ?? []);
    }

    // ── Empresas ─────────────────────────────────────────────────────────────

    public function testVisitanteSoRecebeEmpresasAtivasENuncaAsInativas(): void
    {
        $this->seedEmpresa('Ativa', '/logos/ativa.png', 1);
        $this->seedEmpresa('Escondida', '/uploads/logos/escondida-abc123.webp', 0);

        $res = $this->get('empresas.php');

        self::assertSame(200, $res['status'], $res['body']);
        self::assertSame(['Ativa'], $this->nomes($res));
        // "Tirar do site" tem de tirar da resposta, não só da tela: nem o nome
        // nem o caminho da logo podem aparecer para quem só olha a rede.
        self::assertStringNotContainsString('Escondida', $res['body']);
        self::assertStringNotContainsString('escondida-abc123', $res['body']);
    }

    public function testVisitanteNaoAbreSessaoNemGanhaCookie(): void
    {
        $this->seedEmpresa('Ativa', '/logos/ativa.png', 1);

        $this->get('empresas.php');

        // Cada visitante anônimo gravava um arquivo de sessão e recebia
        // Set-Cookie; num site público isso é custo e ruído sem benefício.
        self::assertSame([], $this->arquivosDeSessao());
    }

    public function testAdminComSessaoVeTodasAsEmpresasInclusiveInativas(): void
    {
        $this->seedEmpresa('Ativa', '/logos/ativa.png', 1);
        $this->seedEmpresa('Escondida', '/logos/escondida.png', 0);

        $res = $this->get('empresas.php', $this->plantaSessao('root'));

        self::assertSame(200, $res['status'], $res['body']);
        self::assertSame(['Ativa', 'Escondida'], $this->nomes($res));
        $inativa = array_values(array_filter(
            $res['json']['companies'],
            static fn (array $c): bool => $c['name'] === 'Escondida'
        ))[0];
        self::assertSame(0, (int) $inativa['is_active']);
    }

    public function testMasterTambemVeTodasAsEmpresas(): void
    {
        $this->seedEmpresa('Ativa', '/logos/ativa.png', 1);
        $this->seedEmpresa('Escondida', '/logos/escondida.png', 0);

        $res = $this->get('empresas.php', $this->plantaSessao('master'));

        self::assertSame(['Ativa', 'Escondida'], $this->nomes($res));
    }

    public function testContaComumLogadaSoRecebeEmpresasAtivas(): void
    {
        $this->seedEmpresa('Ativa', '/logos/ativa.png', 1);
        $this->seedEmpresa('Escondida', '/logos/escondida.png', 0);

        $res = $this->get('empresas.php', $this->plantaSessao('user'));

        self::assertSame(['Ativa'], $this->nomes($res));
    }

    public function testCookieQueNaoCorrespondeASessaoNenhumaNaoDaAcessoAAdmin(): void
    {
        $this->seedEmpresa('Ativa', '/logos/ativa.png', 1);
        $this->seedEmpresa('Escondida', '/logos/escondida.png', 0);

        $res = $this->get('empresas.php', bin2hex(random_bytes(16)));

        self::assertSame(['Ativa'], $this->nomes($res));
    }

    // ── Indicadores ──────────────────────────────────────────────────────────

    public function testIndicadoresPublicosTambemNaoAbremSessao(): void
    {
        $this->db->pdo()
            ->prepare('INSERT INTO site_indicators (indicator_key, value, label, symbol_type, symbol_value) VALUES (?, ?, ?, ?, ?)')
            ->execute(['pessoas', '300 Mil', 'Pessoas impactadas', 'icon', 'ImpactPeopleIcon']);

        $res = $this->get('indicadores.php');

        self::assertSame(200, $res['status'], $res['body']);
        self::assertSame('300 Mil', $res['json']['indicators'][0]['value'] ?? null);
        self::assertSame([], $this->arquivosDeSessao());
    }
}
