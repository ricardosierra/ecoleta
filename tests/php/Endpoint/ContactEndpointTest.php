<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O formulário de contato REAL: public/contact.php rodando de verdade, do
 * começo ao fim. O site é export estático, então não existe rota do Next para
 * isso; o que o visitante aciona é este arquivo.
 *
 * Nenhum teste manda e-mail de verdade. A entrega passa por mail(), e o
 * `sendmail_path` do processo filho aponta para um script de mentira que só
 * grava a mensagem em disco (ou que falha, no caso de "servidor recusou").
 * Assim a asserção vê o MIME exatamente como o servidor de e-mail o receberia.
 *
 * O limite de envio guarda estado na pasta temporária do sistema. Cada teste
 * dá ao processo filho a sua própria TMPDIR: nada vaza entre testes nem entre
 * execuções da suíte.
 */
final class ContactEndpointTest extends TestCase
{
    private string $work;

    private string $tmp;

    private string $maildir;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/ecoleta_contact_' . bin2hex(random_bytes(6));
        $this->tmp = $this->work . '/tmp';
        $this->maildir = $this->work . '/mail';
        mkdir($this->tmp, 0700, true);
        mkdir($this->maildir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->work);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /** @return array<string,string> um envio completo e válido */
    private function envio(array $sobrescreve = []): array
    {
        return $sobrescreve + [
            'nome' => 'Maria Silva',
            'email' => 'maria@empresa.com.br',
            'telefone' => '11 98888-7777',
            'empresa' => 'Empresa Exemplo',
            'tipoOperacao' => 'Indústria',
            'mensagem' => 'Gostaria de entender a coleta seletiva na nossa unidade fabril.',
        ];
    }

    /** Script que grava a mensagem recebida em maildir/ e sai com sucesso. */
    private function sendmailQueGrava(): string
    {
        $script = $this->work . '/sendmail-grava.sh';
        file_put_contents(
            $script,
            "#!/bin/sh\nfile=\"\$(mktemp '" . $this->maildir . "/msg-XXXXXX')\"\nmv \"\$file\" \"\$file.eml\"\ncat > \"\$file.eml\"\n"
        );
        chmod($script, 0755);

        return $script;
    }

    /** Script que descarta a mensagem e sai com erro, como um MTA que recusa. */
    private function sendmailQueFalha(): string
    {
        $script = $this->work . '/sendmail-falha.sh';
        file_put_contents($script, "#!/bin/sh\ncat > /dev/null\nexit 1\n");
        chmod($script, 0755);

        return $script;
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $opcoes remote_addr, env, server e sendmail
     */
    private function envia(array $body, array $opcoes = []): EndpointResponse
    {
        $sendmail = $opcoes['sendmail'] ?? $this->sendmailQueGrava();

        return Endpoint::callPublic('contact.php', [
            'method' => $opcoes['method'] ?? 'POST',
            'body' => $body,
            'remote_addr' => $opcoes['remote_addr'] ?? '203.0.113.10',
            'env' => ($opcoes['env'] ?? []) + ['TMPDIR' => $this->tmp],
            'ini' => ['sendmail_path' => $sendmail],
        ]);
    }

    /** @return list<string> mensagens gravadas pelo sendmail de mentira */
    private function emails(): array
    {
        $arquivos = glob($this->maildir . '/*.eml') ?: [];
        sort($arquivos);

        return array_map(static fn (string $f): string => (string) file_get_contents($f), $arquivos);
    }

    /**
     * Separa o MIME gravado em cabeçalhos, parte texto e parte HTML, já
     * decodificadas do quoted-printable.
     *
     * @return array{headers: string, text: string, html: string}
     */
    private function mime(string $eml): array
    {
        self::assertSame(1, preg_match('/boundary="([^"]+)"/', $eml, $m), 'MIME sem boundary: ' . $eml);
        $blocos = explode('--' . $m[1], $eml);
        self::assertGreaterThanOrEqual(3, count($blocos), 'MIME sem as duas partes: ' . $eml);

        $corpo = static function (string $bloco): string {
            $partes = preg_split('/\r?\n\r?\n/', $bloco, 2);

            return quoted_printable_decode(trim((string) ($partes[1] ?? '')));
        };

        return [
            'headers' => $blocos[0],
            'text' => $corpo($blocos[1]),
            'html' => $corpo($blocos[2]),
        ];
    }

    /** Nome do arquivo de estado do limite para um IP (ver contactRateFile()). */
    private function arquivoDeLimite(string $ip): string
    {
        return $this->tmp . '/ecoleta-contact-rl/' . hash('sha256', 'contact|' . $ip) . '.json';
    }

    /** Planta tentativas antigas para o IP, sem esperar o relógio andar. */
    private function plantaTentativas(string $ip, array $instantes): void
    {
        $dir = $this->tmp . '/ecoleta-contact-rl';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($this->arquivoDeLimite($ip), json_encode(array_values($instantes)));
    }

    // ── Comportamento básico ─────────────────────────────────────────────────

    public function testEnviaOEmailParaODestinoEORemetentePadrao(): void
    {
        $res = $this->envia($this->envio());

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertTrue($res->json()['ok'] ?? false);

        $emails = $this->emails();
        self::assertCount(1, $emails);
        self::assertStringContainsString('To: diretoria@econformidade.com.br', $emails[0]);
        self::assertStringContainsString('From: Site Ecoleva <noreply@ecoleva.com>', $emails[0]);
        self::assertStringContainsString('Reply-To: maria@empresa.com.br', $emails[0]);

        $mime = $this->mime($emails[0]);
        self::assertStringContainsString('Maria Silva', $mime['html']);
        self::assertStringContainsString('Maria Silva', $mime['text']);
    }

    public function testRecusaMetodoQueNaoEPost(): void
    {
        $res = $this->envia([], ['method' => 'GET']);

        self::assertSame(405, $res->status, $res->body);
        self::assertSame([], $this->emails());
    }

    public function testHoneypotPreenchidoRespondeOkSemEnviarNada(): void
    {
        $res = $this->envia($this->envio(['website' => 'http://spam.example']));

        self::assertSame(200, $res->status, $res->body);
        self::assertSame([], $this->emails());
    }

    // ── C1: escape do que vem do visitante ───────────────────────────────────

    public function testEscapaHtmlDeTodosOsCamposNoCorpoHtmlDoEmail(): void
    {
        $res = $this->envia($this->envio([
            'nome' => '<img src=x onerror=alert(1)> Maria',
            'telefone' => '<b>11</b> 98888-7777',
            'empresa' => 'Acme <script>alert(2)</script>',
            'mensagem' => 'Veja <a href="http://phishing.example/login">sua fatura</a> agora mesmo.',
        ]));

        self::assertSame(200, $res->status, $res->body);
        $html = $this->mime($this->emails()[0])['html'];

        // Nada do que o visitante digitou pode virar marcação ativa.
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<a href="http://phishing.example', $html);
        self::assertStringNotContainsString('<b>11</b>', $html);

        // E o texto continua legível, só que escapado.
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; Maria', $html);
        self::assertStringContainsString('Acme &lt;script&gt;alert(2)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;a href=&quot;http://phishing.example/login&quot;&gt;sua fatura&lt;/a&gt;', $html);
    }

    public function testEscapaOEmailDentroDoHrefEDoTexto(): void
    {
        // O filtro de e-mail do PHP aceita parte local entre aspas, e as aspas
        // fechariam o atributo href.
        $res = $this->envia($this->envio(['email' => '"<b>x"@empresa.com.br']));

        self::assertSame(200, $res->status, $res->body);
        $html = $this->mime($this->emails()[0])['html'];

        self::assertStringNotContainsString('<b>x', $html);
        self::assertStringContainsString('href="mailto:&quot;&lt;b&gt;x&quot;@empresa.com.br"', $html);
    }

    public function testParteTextoNaoEEscapadaPoisNaoInterpretaHtml(): void
    {
        $res = $this->envia($this->envio(['mensagem' => 'Preciso de 2 < 3 coletas & mais 1 > 0 por semana.']));

        self::assertSame(200, $res->status, $res->body);
        $mime = $this->mime($this->emails()[0]);

        self::assertStringContainsString('Preciso de 2 < 3 coletas & mais 1 > 0 por semana.', $mime['text']);
        self::assertStringContainsString('Preciso de 2 &lt; 3 coletas &amp; mais 1 &gt; 0 por semana.', $mime['html']);
    }

    // ── C1 (a): destino e remetente vêm da configuração ──────────────────────

    /** Cria um env.php como o do deploy e devolve o caminho. */
    private function envPhp(string $conteudo): string
    {
        $path = $this->work . '/env.php';
        file_put_contents($path, "<?php\n" . $conteudo . "\n");

        return $path;
    }

    public function testDestinoERemetenteVemDoEnvPhpDoDeploy(): void
    {
        $envFile = $this->envPhp(
            "define('CONTACT_TO_EMAIL', 'contato@ecolevaeco.com');\n"
            . "define('CONTACT_FROM_EMAIL', 'site@ecolevaeco.com');"
        );

        $res = $this->envia($this->envio(), ['env' => ['ECOLETA_ENV_FILE' => $envFile]]);

        self::assertSame(200, $res->status, $res->body);
        $email = $this->emails()[0];
        self::assertStringContainsString('To: contato@ecolevaeco.com', $email);
        self::assertStringContainsString('From: Site Ecoleva <site@ecolevaeco.com>', $email);
    }

    public function testConstantesVaziasDoDeployCaemNosPadroes(): void
    {
        // O deploy grava define('CONTACT_FROM_EMAIL', '') quando a variável não
        // está no .env: vazio significa "não configurado", não "sem remetente".
        $envFile = $this->envPhp(
            "define('CONTACT_TO_EMAIL', '');\n"
            . "define('CONTACT_FROM_EMAIL', '');"
        );

        $res = $this->envia($this->envio(), ['env' => ['ECOLETA_ENV_FILE' => $envFile]]);

        self::assertSame(200, $res->status, $res->body);
        $email = $this->emails()[0];
        self::assertStringContainsString('To: diretoria@econformidade.com.br', $email);
        self::assertStringContainsString('From: Site Ecoleva <noreply@ecoleva.com>', $email);
    }

    public function testDestinoTambemPodeVirDeVariavelDeAmbiente(): void
    {
        $res = $this->envia($this->envio(), ['env' => ['CONTACT_TO_EMAIL' => 'comercial@ecolevaeco.com']]);

        self::assertSame(200, $res->status, $res->body);
        self::assertStringContainsString('To: comercial@ecolevaeco.com', $this->emails()[0]);
    }

    public function testEnvPhpInexistenteNaoQuebraENaoMudaOsPadroes(): void
    {
        $res = $this->envia($this->envio(), ['env' => ['ECOLETA_ENV_FILE' => $this->work . '/nao-existe.php']]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertStringContainsString('To: diretoria@econformidade.com.br', $this->emails()[0]);
    }

    public function testDestinoInvalidoNaConfiguracaoCaiNoPadraoERegistraOMotivo(): void
    {
        $res = $this->envia($this->envio(), ['env' => ['CONTACT_TO_EMAIL' => "ruim@x.com\r\nBcc: alguem@x.com"]]);

        self::assertSame(200, $res->status, $res->body);
        $email = $this->emails()[0];
        self::assertStringContainsString('To: diretoria@econformidade.com.br', $email);
        self::assertStringNotContainsString('Bcc:', $email);
        self::assertTrue($res->logged('CONTACT_TO_EMAIL'), $res->errorLog);
    }

    public function testTransporteLogNaoEnviaENaoDerrubaOFormulario(): void
    {
        $res = $this->envia($this->envio(), ['env' => ['MAIL_TRANSPORT' => 'log']]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame([], $this->emails());
        self::assertTrue($res->logged('MAIL_TRANSPORT=log'), $res->errorLog);
    }

    // ── C1 (b): falha de entrega ─────────────────────────────────────────────

    public function testFalhaNoEnvioDevolveErroERegistraSemDadosPessoais(): void
    {
        $res = $this->envia(
            $this->envio(['nome' => 'Fulana Sigilosa', 'telefone' => '21 91234-5678', 'mensagem' => 'Conteudo reservado do pedido de coleta.']),
            ['sendmail' => $this->sendmailQueFalha()]
        );

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(500, $res->status, $res->body);
        self::assertArrayNotHasKey('ok', $res->json());
        self::assertNotSame('', (string) $res->error());

        // Registra o motivo para quem opera o servidor...
        self::assertNotSame('', trim($res->errorLog), 'a falha de mail() ficou sem registro');
        // ...mas sem nenhum dado do visitante.
        foreach (['Fulana Sigilosa', 'maria@empresa.com.br', '21 91234-5678', 'Conteudo reservado', 'Empresa Exemplo'] as $dado) {
            self::assertStringNotContainsString($dado, $res->errorLog);
        }
    }

    // ── C1 (c): mensagens de tamanho ─────────────────────────────────────────

    public function testMensagemCurtaPedeParaContarMaisDaOperacao(): void
    {
        $res = $this->envia($this->envio(['mensagem' => 'curta']));

        self::assertSame(400, $res->status, $res->body);
        $issue = $this->issue($res, 'mensagem');
        self::assertSame('Conte um pouco sobre sua operação.', $issue);
    }

    public function testMensagemLongaDemaisDizQueEstourouOLimite(): void
    {
        $res = $this->envia($this->envio(['mensagem' => str_repeat('a', 4001)]));

        self::assertSame(400, $res->status, $res->body);
        $issue = $this->issue($res, 'mensagem');
        // A mensagem antiga mandava "contar um pouco" a quem escreveu demais.
        self::assertStringNotContainsString('Conte um pouco', $issue);
        self::assertStringContainsString('4000', $issue);
        self::assertSame([], $this->emails());
    }

    public function testMensagemNoLimiteExatoPassa(): void
    {
        $res = $this->envia($this->envio(['mensagem' => str_repeat('a', 4000)]));

        self::assertSame(200, $res->status, $res->body);
    }

    /** @return array<string,array{string,int,string,string}> campo, tamanho que estoura, mensagem de campo vazio, teto */
    public static function camposComTeto(): array
    {
        return [
            'nome' => ['nome', 121, 'Informe seu nome.', '120'],
            'telefone' => ['telefone', 41, 'Informe um telefone válido.', '40'],
            'empresa' => ['empresa', 161, 'Informe a empresa.', '160'],
        ];
    }

    #[DataProvider('camposComTeto')]
    public function testCampoAcimaDoTetoNaoRecebeAMensagemDeCampoVazio(string $campo, int $tamanho, string $mensagemDeVazio, string $teto): void
    {
        $res = $this->envia($this->envio([$campo => str_repeat('a', $tamanho)]));

        self::assertSame(400, $res->status, $res->body);
        $issue = $this->issue($res, $campo);
        self::assertNotSame($mensagemDeVazio, $issue);
        self::assertStringContainsString($teto, $issue);
    }

    private function issue(EndpointResponse $res, string $campo): string
    {
        foreach ($res->json()['issues'] ?? [] as $issue) {
            if (($issue['path'] ?? null) === $campo) {
                return (string) $issue['message'];
            }
        }

        self::fail("Nenhum problema apontado para {$campo}: " . $res->body);
    }

    // ── C1 (d): limite de envio por IP ───────────────────────────────────────

    public function testCincoEnviosPorMinutoPassamEOSextoEBarrado(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $res = $this->envia($this->envio());
            self::assertSame(200, $res->status, "envio {$i}: " . $res->body);
        }

        $barrado = $this->envia($this->envio());

        self::assertNull($barrado->fatal, (string) $barrado->fatal);
        self::assertSame(429, $barrado->status, $barrado->body);
        self::assertNotSame('', (string) $barrado->error());
        self::assertGreaterThan(0, (int) ($barrado->json()['retryAfter'] ?? 0));
        self::assertLessThanOrEqual(60, (int) $barrado->json()['retryAfter']);
        // O barrado não gera e-mail: só os cinco primeiros chegaram.
        self::assertCount(5, $this->emails());
    }

    public function testOLimiteEPorIp(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->envia($this->envio(), ['remote_addr' => '198.51.100.7']);
        }

        self::assertSame(429, $this->envia($this->envio(), ['remote_addr' => '198.51.100.7'])->status);
        self::assertSame(200, $this->envia($this->envio(), ['remote_addr' => '198.51.100.8'])->status);
    }

    public function testTentativasComMaisDeUmMinutoNaoContamNoLimiteDoMinuto(): void
    {
        $agora = time();
        $this->plantaTentativas('203.0.113.10', [$agora - 70, $agora - 80, $agora - 90, $agora - 100, $agora - 110]);

        $res = $this->envia($this->envio());

        self::assertSame(200, $res->status, $res->body);
    }

    public function testTrintaEnviosNaUltimaHoraBarramMesmoComOMinutoLivre(): void
    {
        $agora = time();
        // 30 tentativas espalhadas pela última hora, todas fora do último minuto.
        $instantes = [];
        for ($i = 0; $i < 30; $i++) {
            $instantes[] = $agora - 120 - ($i * 100);
        }
        $this->plantaTentativas('203.0.113.10', $instantes);

        $res = $this->envia($this->envio());

        self::assertSame(429, $res->status, $res->body);
        $espera = (int) ($res->json()['retryAfter'] ?? 0);
        self::assertGreaterThan(60, $espera, 'o prazo da hora é bem maior que o do minuto');
        self::assertLessThanOrEqual(3600, $espera);
        self::assertSame([], $this->emails());
    }

    public function testVinteENoveEnviosNaHoraAindaDeixamPassarOTrigesimoEBarramOSeguinte(): void
    {
        $agora = time();
        $instantes = [];
        for ($i = 0; $i < 29; $i++) {
            $instantes[] = $agora - 120 - ($i * 100);
        }
        $this->plantaTentativas('203.0.113.10', $instantes);

        self::assertSame(200, $this->envia($this->envio())->status);
        // E agora são 30 na hora: o próximo é barrado.
        self::assertSame(429, $this->envia($this->envio())->status);
    }

    public function testTentativasComMaisDeUmaHoraSaemDaConta(): void
    {
        $agora = time();
        $instantes = [];
        for ($i = 0; $i < 40; $i++) {
            $instantes[] = $agora - 3700 - ($i * 10);
        }
        $this->plantaTentativas('203.0.113.10', $instantes);

        self::assertSame(200, $this->envia($this->envio())->status);
    }

    public function testRequisicaoInvalidaOuHoneypotNaoGastaOLimite(): void
    {
        // O limite protege a caixa da diretoria: só conta o que viraria e-mail.
        for ($i = 0; $i < 8; $i++) {
            self::assertSame(400, $this->envia($this->envio(['nome' => 'X']))->status);
            self::assertSame(200, $this->envia($this->envio(['website' => 'spam']))->status);
        }

        self::assertSame(200, $this->envia($this->envio())->status);
    }

    public function testEstadoNaoGuardaDadosPessoaisEmClaro(): void
    {
        $this->envia($this->envio(), ['remote_addr' => '203.0.113.99']);

        $arquivos = glob($this->tmp . '/ecoleta-contact-rl/*') ?: [];
        self::assertCount(1, $arquivos);
        self::assertStringNotContainsString('203.0.113.99', basename($arquivos[0]));
        $conteudo = (string) file_get_contents($arquivos[0]);
        self::assertStringNotContainsString('203.0.113.99', $conteudo);
        self::assertStringNotContainsString('maria@empresa.com.br', $conteudo);
        // Só instantes (inteiros) em uma lista.
        $lista = json_decode($conteudo, true);
        self::assertIsArray($lista);
        self::assertCount(1, $lista);
        self::assertIsInt($lista[0]);
    }

    public function testFalhaAbertoQuandoNaoConsegueGravarOEstado(): void
    {
        // TMPDIR apontando para um lugar onde não dá para criar a pasta do estado.
        $env = ['TMPDIR' => $this->work . '/nao-existe/nem-este'];

        for ($i = 1; $i <= 7; $i++) {
            $res = $this->envia($this->envio(), ['env' => $env]);
            self::assertNull($res->fatal, (string) $res->fatal);
            self::assertSame(200, $res->status, "envio {$i}: " . $res->body);
        }
    }

    public function testEstadoCorrompidoNaoDerrubaNemBarraOVisitante(): void
    {
        $dir = $this->tmp . '/ecoleta-contact-rl';
        mkdir($dir, 0700, true);
        file_put_contents($this->arquivoDeLimite('203.0.113.10'), '{lixo que nao e uma lista');

        $res = $this->envia($this->envio());

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
    }

    public function testArquivosDeIpsQueSumiramHaMaisDeUmaHoraSaoLimpos(): void
    {
        $dir = $this->tmp . '/ecoleta-contact-rl';
        mkdir($dir, 0700, true);
        $velho = $dir . '/' . str_repeat('a', 64) . '.json';
        $recente = $dir . '/' . str_repeat('b', 64) . '.json';
        file_put_contents($velho, '[]');
        file_put_contents($recente, '[]');
        touch($velho, time() - 7200);

        // Um IP novo cria o próprio arquivo e aproveita para varrer o resto.
        self::assertSame(200, $this->envia($this->envio(), ['remote_addr' => '192.0.2.55'])->status);

        self::assertFileDoesNotExist($velho);
        self::assertFileExists($recente);
    }
}
