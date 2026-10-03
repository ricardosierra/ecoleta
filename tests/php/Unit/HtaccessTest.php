<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * public/.htaccess: o que NENHUM servidor web roda aqui.
 *
 * Não há Apache nem LiteSpeed na suíte, então isto não prova que o servidor
 * obedece: prova o que dá para provar de um arquivo de texto. As expressões
 * regulares das regras de negação são extraídas do próprio .htaccess e rodadas
 * contra nomes de arquivo reais (o PCRE do PHP é o mesmo dialeto que o Apache
 * usa), e a estrutura é conferida para não sobrar bloco aberto nem diretiva
 * que o .htaccess não aceita.
 *
 * O deploy por FTP só ENVIA arquivos: nunca apaga nada do servidor. Por isso
 * scripts temporários que um dia estiveram no repositório (temp_reset.php,
 * migrate_temp.php) continuam lá, e o env.php com as credenciais é gerado a cada
 * deploy dentro do webroot.
 */
final class HtaccessTest extends TestCase
{
    private string $conteudo;

    protected function setUp(): void
    {
        $this->conteudo = (string) file_get_contents(ECOLETA_ROOT . '/public/.htaccess');
    }

    /** Sem comentários, para as asserções não casarem com texto explicativo. */
    private function diretivas(): string
    {
        return (string) preg_replace('/^\s*#.*$/m', '', $this->conteudo);
    }

    /** Padrão do <FilesMatch> que nega acesso, extraído do arquivo. */
    private function padraoDeNegacaoDeArquivos(): string
    {
        $achou = preg_match(
            '/<FilesMatch\s+"([^"]+)">\s*Require\s+all\s+denied\s*<\/FilesMatch>/i',
            $this->diretivas(),
            $m
        );
        self::assertSame(1, $achou, 'falta o <FilesMatch> com "Require all denied"');

        return $m[1];
    }

    /** Padrão da RewriteRule [F] que nega o vendor/, extraído do arquivo. */
    private function padraoDeNegacaoDoVendor(): string
    {
        $achou = preg_match('/RewriteRule\s+(\S*vendor\S*)\s+-\s+\[([^\]]*\bF\b[^\]]*)\]/i', $this->diretivas(), $m);
        self::assertSame(1, $achou, 'falta a RewriteRule [F] para api/vendor/');

        return $m[1];
    }

    private function casa(string $padrao, string $alvo, bool $ignorarCaixa = false): bool
    {
        return preg_match('~' . str_replace('~', '\\~', $padrao) . '~' . ($ignorarCaixa ? 'i' : ''), $alvo) === 1;
    }

    // --- arquivos que ficam negados --------------------------------------------

    /** @return array<string, array{0:string}> */
    public static function arquivosNegados(): array
    {
        return [
            'credenciais geradas pelo deploy' => ['env.php'],
            'exemplo de credenciais' => ['env.example.php'],
            'manifesto do composer' => ['composer.json'],
            'trava do composer' => ['composer.lock'],
            'reset temporário' => ['temp_reset.php'],
            'migration temporária' => ['migrate_temp.php'],
            'instalação antiga' => ['setup_db.php'],
            'migration 13 avulsa' => ['apply_mig_13.php'],
        ];
    }

    #[DataProvider('arquivosNegados')]
    public function testArquivoInternoEhNegado(string $arquivo): void
    {
        self::assertTrue($this->casa($this->padraoDeNegacaoDeArquivos(), $arquivo), "{$arquivo} deveria ser negado");
    }

    /**
     * O que tem uso real NÃO pode ser negado: install.php (criação única do root,
     * já se desativa sozinho) e migrate.php (chamado pelo deploy, e protegido pelo
     * segredo).
     *
     * @return array<string, array{0:string}>
     */
    public static function arquivosQueTemUso(): array
    {
        return [
            'install' => ['install.php'],
            'migrate' => ['migrate.php'],
            'login' => ['login.php'],
            'sessão' => ['me.php'],
            'índice' => ['index.php'],
            'contato' => ['contact.php'],
            'schema' => ['schema.php'],
            'nome parecido com env' => ['environment.php'],
            'nome parecido com composer' => ['composer-setup.php'],
            'env em outro formato' => ['env.json'],
            'prefixo no meio do nome' => ['meu_env.php'],
            'página do site' => ['index.html'],
        ];
    }

    #[DataProvider('arquivosQueTemUso')]
    public function testArquivoComUsoNaoEhNegado(string $arquivo): void
    {
        self::assertFalse($this->casa($this->padraoDeNegacaoDeArquivos(), $arquivo), "{$arquivo} NÃO pode ser negado");
    }

    public function testNegacaoDeArquivosEAncorada(): void
    {
        $padrao = $this->padraoDeNegacaoDeArquivos();

        self::assertStringStartsWith('^', $padrao, 'sem ^, "x_env.php" e "env.php.bak" também casariam');
        self::assertStringEndsWith('$', $padrao);
    }

    // --- vendor/ ---------------------------------------------------------------

    /**
     * <DirectoryMatch> só existe em configuração de servidor e de host virtual: em
     * .htaccess é erro, e erro de sintaxe em .htaccess derruba o site inteiro com
     * 500. Diretório é negado por RewriteRule, que este arquivo já usa.
     */
    public function testNaoUsaDirectoryMatchPorqueNaoValeEmHtaccess(): void
    {
        self::assertStringNotContainsStringIgnoringCase('<DirectoryMatch', $this->diretivas());
        self::assertStringNotContainsStringIgnoringCase('<Directory ', $this->diretivas());
    }

    #[DataProvider('caminhosDoVendor')]
    public function testVendorEhNegadoPelaWeb(string $caminho, bool $negado): void
    {
        self::assertSame($negado, $this->casa($this->padraoDeNegacaoDoVendor(), $caminho), $caminho);
    }

    /** @return array<string, array{0:string, 1:bool}> */
    public static function caminhosDoVendor(): array
    {
        return [
            'autoload' => ['api/vendor/autoload.php', true],
            'phpmailer' => ['api/vendor/phpmailer/phpmailer/get_oauth_token.php', true],
            'a pasta em si' => ['api/vendor', true],
            'a pasta com barra' => ['api/vendor/', true],
            'arquivo com nome parecido' => ['api/vendors.php', false],
            'pasta com nome parecido' => ['api/vendor-extra/x.php', false],
            'endpoint' => ['api/auth/login.php', false],
            'vendor em outra pasta' => ['gallery/vendor/foto.png', false],
        ];
    }

    // --- estrutura -------------------------------------------------------------

    public function testBlocosEstaoBalanceados(): void
    {
        $diretivas = $this->diretivas();

        foreach (['IfModule', 'FilesMatch', 'Files'] as $bloco) {
            self::assertSame(
                preg_match_all('/<' . $bloco . '[\s>]/i', $diretivas),
                preg_match_all('/<\/' . $bloco . '>/i', $diretivas),
                "bloco <{$bloco}> aberto sem fechar (ou o contrário)"
            );
        }
    }

    public function testNegacaoDeArquivosUsaASintaxeDoApache24(): void
    {
        $diretivas = $this->diretivas();

        self::assertStringContainsString('Require all denied', $diretivas);
        // A sintaxe 2.2 não existe no Apache 2.4 sem mod_access_compat.
        self::assertDoesNotMatchRegularExpression('/^\s*(Order|Deny|Allow)\s/mi', $diretivas);
    }

    // --- o que NÃO entra -------------------------------------------------------

    /**
     * CSP e HSTS ficam de fora de propósito: um CSP errado quebra o site (o
     * dashboard embute o Power BI e o site carrega fontes e vídeo de fora) e HSTS
     * é irreversível por meses no navegador de quem visitou. Sem como testar
     * contra o servidor real, não entram.
     */
    public function testSemCspNemHsts(): void
    {
        self::assertStringNotContainsStringIgnoringCase('Content-Security-Policy', $this->diretivas());
        self::assertStringNotContainsStringIgnoringCase('Strict-Transport-Security', $this->diretivas());
    }

    public function testRegrasQueJaExistiamContinuamIntactas(): void
    {
        $diretivas = $this->diretivas();

        foreach ([
            'RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]',
            'RewriteRule ^api/os/view/?$ /api/os/view.php [QSA,L]',
            'RewriteRule ^os/view/?$ /api/os/view.php [QSA,L]',
            'DirectoryIndex index.html',
            'Options -Indexes',
            'ErrorDocument 404 /404.html',
            'Header always set X-Content-Type-Options "nosniff"',
            'Header always set X-Frame-Options "SAMEORIGIN"',
            'Header always set Referrer-Policy "strict-origin-when-cross-origin"',
        ] as $regra) {
            self::assertStringContainsString($regra, $diretivas, "regra existente sumiu: {$regra}");
        }
    }

    // --- Permissions-Policy ----------------------------------------------------

    private function permissionsPolicy(): string
    {
        $achou = preg_match('/Header\s+always\s+set\s+Permissions-Policy\s+"([^"]+)"/i', $this->diretivas(), $m);
        self::assertSame(1, $achou, 'falta o cabeçalho Permissions-Policy');

        return $m[1];
    }

    public function testPermissionsPolicyNegaORecursoQueOSiteNaoUsa(): void
    {
        $politica = $this->permissionsPolicy();

        foreach (['camera=()', 'geolocation=()', 'payment=()', 'usb=()'] as $recurso) {
            self::assertStringContainsString($recurso, $politica);
        }
    }

    /**
     * O painel de WhatsApp grava mensagem de voz (getUserMedia de áudio em
     * app/dashboard/whatsapp/page.tsx). `microphone=()` o quebraria para todo
     * mundo; o recurso fica liberado para a própria origem e continua negado a
     * qualquer iframe de terceiros (o do Power BI inclusive).
     */
    public function testPermissionsPolicyNaoQuebraAGravacaoDeAudioDoWhatsApp(): void
    {
        $politica = $this->permissionsPolicy();

        self::assertStringContainsString('microphone=(self)', $politica);
        self::assertStringNotContainsString('microphone=()', $politica);
    }

    public function testPermissionsPolicyNaoMexeEmFullscreenNemEmAreaDeTransferencia(): void
    {
        $politica = $this->permissionsPolicy();

        // O Power BI embutido usa tela cheia, e o painel copia senha gerada e
        // número de OS: nenhum dos dois pode ser desligado por engano.
        self::assertStringNotContainsString('fullscreen', $politica);
        self::assertStringNotContainsString('clipboard', $politica);
    }
}
