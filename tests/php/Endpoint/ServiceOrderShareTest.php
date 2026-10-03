<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O encaminhamento de Ordem de Serviço rodando de verdade: criação com token,
 * o link público, o e-mail e o WhatsApp do robô.
 *
 * O envio de e-mail é desligado com `MAIL_TRANSPORT=log` — a suíte exercita
 * validação, autorização e o que fica gravado, nunca o sendmail da máquina.
 */
final class ServiceOrderShareTest extends TestCase
{
    private TestDatabase $db;

    private int $adminId;

    private int $userId;

    private int $clientId;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        $grupoId = $this->db->seedGroup('Coleta');
        $this->adminId = $this->db->seedUser('chefe', 'senha-admin-123', 'root');
        $this->userId = $this->db->seedUser('joao', 'senha-user-123', 'user', null, $grupoId);
        $this->clientId = $this->db->seedClient(
            'Heineken',
            500.0,
            10,
            'active',
            '5521999887766',
            null,
            'contato@heineken.exemplo'
        );
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    /** @return array<string,mixed> */
    private function sessaoAdmin(): array
    {
        return ['user_id' => $this->adminId, 'role' => 'root', 'login' => 'chefe'];
    }

    /** @return array<string,mixed> */
    private function sessaoUser(): array
    {
        return ['user_id' => $this->userId, 'role' => 'user', 'login' => 'joao'];
    }

    /** @return array<string,mixed> */
    private function opcoes(array $extra = []): array
    {
        // Nenhum teste pode encostar em rede: `MAIL_TRANSPORT=log` só registra o
        // destinatário e `WHATSAPP_TRANSPORT=off` desliga o robô. Sem a segunda,
        // uma máquina com o env.php de produção mandaria WhatsApp de verdade a
        // cada execução da suíte.
        return array_merge([
            'dsn' => $this->db->dsn(),
            'env' => ['MAIL_TRANSPORT' => 'log', 'WHATSAPP_TRANSPORT' => 'off'],
        ], $extra);
    }

    // ── Listagem ────────────────────────────────────────────────────────────

    public function testListagemRecusaPapelUser(): void
    {
        $res = Endpoint::call('os/index.php', $this->opcoes([
            'method' => 'GET',
            'session' => $this->sessaoUser(),
        ]));

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(403, $res->status, $res->body);
    }

    public function testListagemNaoDevolveOTokenCru(): void
    {
        $this->db->seedServiceOrder($this->clientId, str_repeat('b', 64));

        $res = Endpoint::call('os/index.php', $this->opcoes([
            'method' => 'GET',
            'session' => $this->sessaoAdmin(),
        ]));

        self::assertSame(200, $res->status, $res->body);

        $os = $res->json()['service_orders'][0] ?? [];
        self::assertArrayNotHasKey('share_token', $os, 'o token cru não deve sair da API');
        self::assertStringContainsString('t=' . str_repeat('b', 64), (string) ($os['share_url'] ?? ''));
        self::assertSame('contato@heineken.exemplo', $os['client_email'] ?? null);
    }

    // ── Criação ─────────────────────────────────────────────────────────────

    public function testCriacaoGeraTokenEDevolveOLinkPronto(): void
    {
        $res = Endpoint::call('os/index.php', $this->opcoes([
            'session' => $this->sessaoAdmin(),
            'body' => [
                'client_id' => $this->clientId,
                'collection_address' => 'Av. das Américas, 500',
                'weight' => '150 kg',
                'collection_date' => '2026-09-03',
                'approximate_time' => '14:30',
                'material_collected' => 'Óleo vegetal usado',
                'bags_count' => '12',
                'containers_count' => '2',
                'responsible' => 'Equipe A',
            ],
        ]));

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);

        $linha = $this->db->rows('service_orders')[0];
        $token = (string) $linha['share_token'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertSame('Av. das Américas, 500', $linha['collection_address']);
        self::assertSame('14:30', $linha['approximate_time']);
        self::assertSame('Óleo vegetal usado', $linha['material_collected']);

        $criada = $res->json()['service_order'] ?? [];
        self::assertStringContainsString('api/os/view.php?id=', (string) ($criada['share_url'] ?? ''));
        self::assertStringContainsString('t=' . $token, (string) ($criada['share_url'] ?? ''));
        self::assertSame('Av. das Américas, 500', $criada['collection_address']);
        self::assertSame('14:30', $criada['approximate_time']);
        self::assertSame('Óleo vegetal usado', $criada['material_collected']);
    }

    // ── Validação do corpo da criação ───────────────────────────────────────
    //
    // O documento vai ao cliente, e as colunas têm tamanho: VARCHAR(50) para
    // pesagem e horário, VARCHAR(255) para endereço, material e responsável, INT
    // para as quantidades. Sem barrar antes, o MySQL em modo estrito responde 500
    // genérico e, sem modo estrito, trunca em silêncio um texto que sai assinado.

    /** @param array<string,mixed> $campos */
    private function criar(array $campos): EndpointResponse
    {
        return Endpoint::call('os/index.php', $this->opcoes([
            'session' => $this->sessaoAdmin(),
            'body' => ['client_id' => $this->clientId] + $campos,
        ]));
    }

    private function anoAtual(): int
    {
        return (int) (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y');
    }

    public function testCriacaoSoComClienteContinuaValendo(): void
    {
        // A tela só marca o cliente como obrigatório (`Cliente *`); o servidor cobra
        // o mesmo, nem mais nem menos.
        $res = $this->criar([]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertNull($this->db->rows('service_orders')[0]['collection_date']);
    }

    public function testCriacaoSemClienteResponde400(): void
    {
        $res = Endpoint::call('os/index.php', $this->opcoes([
            'session' => $this->sessaoAdmin(),
            'body' => ['weight' => '150 kg'],
        ]));

        self::assertSame(400, $res->status, $res->body);
        self::assertSame('Cliente é obrigatório.', $res->error());
        self::assertSame(0, $this->db->count('service_orders'));
    }

    public function testCriacaoComClienteInexistenteResponde404(): void
    {
        $res = Endpoint::call('os/index.php', $this->opcoes([
            'session' => $this->sessaoAdmin(),
            'body' => ['client_id' => 9999],
        ]));

        self::assertSame(404, $res->status, $res->body);
        self::assertSame(0, $this->db->count('service_orders'));
    }

    /** @return array<string, array{0:string,1:mixed,2:string}> campo, valor, rótulo esperado na mensagem */
    public static function quantidadesInvalidas(): array
    {
        return [
            'sacos gigante' => ['bags_count', '99999999999', 'Qtd. sacos'],
            'sacos acima do teto' => ['bags_count', '100000', 'Qtd. sacos'],
            'sacos negativo' => ['bags_count', '-1', 'Qtd. sacos'],
            'sacos decimal' => ['bags_count', '1.5', 'Qtd. sacos'],
            'sacos texto' => ['bags_count', 'abc', 'Qtd. sacos'],
            'sacos notacao cientifica' => ['bags_count', '1e3', 'Qtd. sacos'],
            'sacos booleano' => ['bags_count', true, 'Qtd. sacos'],
            'sacos lista' => ['bags_count', [1], 'Qtd. sacos'],
            'sacos numero decimal json' => ['bags_count', 12.5, 'Qtd. sacos'],
            'sacos inteiro gigante json' => ['bags_count', 99999999999, 'Qtd. sacos'],
            'conteineres gigante' => ['containers_count', '99999999999', 'Qtd. contêineres'],
            'conteineres negativo' => ['containers_count', -3, 'Qtd. contêineres'],
            'conteineres texto' => ['containers_count', 'dois', 'Qtd. contêineres'],
        ];
    }

    #[DataProvider('quantidadesInvalidas')]
    public function testCriacaoRecusaQuantidadeInvalidaNomeandoOCampo(string $campo, mixed $valor, string $rotulo): void
    {
        $res = $this->criar([$campo => $valor]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertStringContainsString($rotulo, (string) $res->error());
        self::assertSame(0, $this->db->count('service_orders'));
    }

    /** @return array<string, array{0:mixed,1:?int}> */
    public static function quantidadesValidas(): array
    {
        return [
            'zero a esquerda' => ['05', 5],
            'varios zeros a esquerda' => ['007', 7],
            'so zeros' => ['000', 0],
            'zero' => ['0', 0],
            'inteiro json' => [12, 12],
            'inteiro json decimal exato' => [12.0, 12],
            'no teto' => ['99999', 99999],
            'com espacos' => [' 8 ', 8],
            'vazio' => ['', null],
            'so espacos' => ['   ', null],
            'nulo' => [null, null],
        ];
    }

    #[DataProvider('quantidadesValidas')]
    public function testCriacaoAceitaQuantidadeValida(mixed $valor, ?int $esperado): void
    {
        $res = $this->criar(['bags_count' => $valor, 'containers_count' => $valor]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);

        $linha = $this->db->rows('service_orders')[0];
        self::assertSame($esperado, $linha['bags_count'] === null ? null : (int) $linha['bags_count']);
        self::assertSame($esperado, $linha['containers_count'] === null ? null : (int) $linha['containers_count']);
    }

    /** @return array<string, array{0:string}> */
    public static function datasInvalidas(): array
    {
        return [
            'ano 26' => ['0026-09-03'],
            'ano 2099' => ['2099-09-03'],
            'antes de 2000' => ['1999-12-31'],
            'dia impossivel' => ['2026-02-31'],
            'formato brasileiro' => ['03/09/2026'],
            'texto' => ['amanha'],
            'sem zero' => ['2026-9-3'],
        ];
    }

    #[DataProvider('datasInvalidas')]
    public function testCriacaoRecusaDataInvalidaOuImplausivel(string $data): void
    {
        $res = $this->criar(['collection_date' => $data]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertStringContainsString('Data da coleta', (string) $res->error());
        self::assertSame(0, $this->db->count('service_orders'));
    }

    public function testCriacaoRecusaDataDoAnoSeguinteAoSeguinte(): void
    {
        $res = $this->criar(['collection_date' => ($this->anoAtual() + 2) . '-01-01']);

        self::assertSame(400, $res->status, $res->body);
        self::assertStringContainsString('Data da coleta', (string) $res->error());
    }

    public function testCriacaoAceitaDatasNosLimitesDoIntervalo(): void
    {
        foreach (['2000-01-01', ($this->anoAtual() + 1) . '-12-31'] as $data) {
            $res = $this->criar(['collection_date' => $data]);

            self::assertSame(200, $res->status, $data . ' ' . $res->body);
        }

        self::assertSame(2, $this->db->count('service_orders'));
    }

    public function testCriacaoRecusaDataQueNaoETexto(): void
    {
        $res = $this->criar(['collection_date' => ['2026-09-03']]);

        self::assertSame(400, $res->status, $res->body);
        self::assertStringContainsString('Data da coleta', (string) $res->error());
    }

    /** @return array<string, array{0:string,1:int,2:string}> campo, tamanho da coluna, rótulo */
    public static function camposDeTexto(): array
    {
        return [
            'pesagem' => ['weight', 50, 'Pesagem'],
            'horario' => ['approximate_time', 50, 'Horário aproximado'],
            'endereco' => ['collection_address', 255, 'Endereço da coleta'],
            'material' => ['material_collected', 255, 'Material coletado'],
            'responsavel' => ['responsible', 255, 'Responsável pela coleta'],
        ];
    }

    #[DataProvider('camposDeTexto')]
    public function testCriacaoRecusaTextoMaiorQueAColunaNomeandoOCampo(string $campo, int $limite, string $rotulo): void
    {
        $res = $this->criar([$campo => str_repeat('a', $limite + 1)]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertStringContainsString($rotulo, (string) $res->error());
        self::assertStringContainsString((string) $limite, (string) $res->error());
        self::assertSame(0, $this->db->count('service_orders'));
    }

    #[DataProvider('camposDeTexto')]
    public function testCriacaoAceitaTextoExatamenteNoTamanhoDaColuna(string $campo, int $limite, string $rotulo): void
    {
        // A coluna VARCHAR(n) do MySQL conta caracteres, não bytes: "ç" ocupa dois
        // bytes e um campo cheio deles não pode ser recusado por isso.
        $texto = str_repeat('ç', $limite);

        $res = $this->criar([$campo => $texto]);

        self::assertSame(200, $res->status, $rotulo . ' ' . $res->body);
        self::assertSame($texto, $this->db->rows('service_orders')[0][$campo]);
    }

    #[DataProvider('camposDeTexto')]
    public function testCriacaoContaCaracteresEMultibyteNaoBytes(string $campo, int $limite, string $rotulo): void
    {
        $res = $this->criar([$campo => str_repeat('ç', $limite + 1)]);

        self::assertSame(400, $res->status, $rotulo . ' ' . $res->body);
    }

    #[DataProvider('camposDeTexto')]
    public function testCriacaoRecusaTextoQueNaoETextoNomeandoOCampo(string $campo, int $limite, string $rotulo): void
    {
        $res = $this->criar([$campo => ['a', 'b']]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
        self::assertStringContainsString($rotulo, (string) $res->error());
        self::assertSame(0, $this->db->count('service_orders'));
    }

    public function testCriacaoAceitaPesagemEnviadaComoNumeroJson(): void
    {
        $res = $this->criar(['weight' => 150]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame('150', (string) $this->db->rows('service_orders')[0]['weight']);
    }

    public function testCriacaoTrataTextoSoComEspacosComoAusente(): void
    {
        $res = $this->criar(['weight' => '   ', 'responsible' => "\t", 'collection_address' => '  ']);

        self::assertSame(200, $res->status, $res->body);

        $linha = $this->db->rows('service_orders')[0];
        self::assertNull($linha['weight']);
        self::assertNull($linha['responsible']);
        self::assertNull($linha['collection_address']);
    }

    public function testCriacaoGuardaOTextoSemOsEspacosDasPontas(): void
    {
        $res = $this->criar(['weight' => '  150 kg  ']);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame('150 kg', $this->db->rows('service_orders')[0]['weight']);
    }

    public function testDuasOrdensNaoCompartilhamOMesmoToken(): void
    {
        foreach ([1, 2] as $_) {
            Endpoint::call('os/index.php', $this->opcoes([
                'session' => $this->sessaoAdmin(),
                'body' => ['client_id' => $this->clientId],
            ]));
        }

        $tokens = array_column($this->db->rows('service_orders'), 'share_token');

        self::assertCount(2, $tokens);
        self::assertNotSame($tokens[0], $tokens[1]);
    }

    // ── Link público ────────────────────────────────────────────────────────

    public function testLinkPublicoAbreSemSessao(): void
    {
        $token = str_repeat('c', 64);
        $id = $this->db->seedServiceOrder($this->clientId, $token);

        $res = Endpoint::call('os/view.php', $this->opcoes([
            'method' => 'GET',
            'query' => ['id' => $id, 't' => $token],
        ]));

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertStringContainsString('Heineken', $res->body);
        self::assertStringContainsString('Ordem de Serviço', $res->body);
        self::assertStringContainsString('assinatura-responsavel.png', $res->body, 'a assinatura precisa aparecer no documento');
        self::assertStringContainsString('03/09/2026', $res->body);
        self::assertStringContainsString('Av. das Américas, 500', $res->body);
        self::assertStringContainsString('14:30', $res->body);
        self::assertStringContainsString('Óleo vegetal usado', $res->body);
    }

    public function testLinkPublicoRecusaTokenErrado(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('c', 64));

        $res = Endpoint::call('os/view.php', $this->opcoes([
            'method' => 'GET',
            'query' => ['id' => $id, 't' => str_repeat('d', 64)],
        ]));

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(404, $res->status);
        self::assertStringNotContainsString('Heineken', $res->body);
    }

    public function testLinkPublicoRecusaOrdemSemToken(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, null);

        $res = Endpoint::call('os/view.php', $this->opcoes([
            'method' => 'GET',
            'query' => ['id' => $id, 't' => str_repeat('0', 64)],
        ]));

        self::assertSame(404, $res->status);
    }

    // ── E-mail ──────────────────────────────────────────────────────────────

    public function testEnvioPorEmailRecusaPapelUser(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('e', 64));

        $res = Endpoint::call('os/send.php', $this->opcoes([
            'session' => $this->sessaoUser(),
            'body' => ['id' => $id],
        ]));

        self::assertSame(403, $res->status, $res->body);
        self::assertNull($this->db->rows('service_orders')[0]['sent_at']);
    }

    public function testEnvioPorEmailUsaOEnderecoDoClienteERegistraOEnvio(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('e', 64));

        $res = Endpoint::call('os/send.php', $this->opcoes([
            'session' => $this->sessaoAdmin(),
            'body' => ['id' => $id],
        ]));

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        self::assertSame('contato@heineken.exemplo', $res->json()['sent_to'] ?? null);

        $linha = $this->db->rows('service_orders')[0];
        self::assertSame('contato@heineken.exemplo', $linha['sent_to']);
        self::assertNotNull($linha['sent_at']);
    }

    public function testEnvioPorEmailPrefereODestinatarioInformado(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('e', 64));

        $res = Endpoint::call('os/send.php', $this->opcoes([
            'session' => $this->sessaoAdmin(),
            'body' => ['id' => $id, 'email' => 'financeiro@heineken.exemplo'],
        ]));

        self::assertSame(200, $res->status, $res->body);
        self::assertSame('financeiro@heineken.exemplo', $this->db->rows('service_orders')[0]['sent_to']);
    }

    public function testEnvioPorEmailRecusaEnderecoInvalido(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('e', 64));

        $res = Endpoint::call('os/send.php', $this->opcoes([
            'session' => $this->sessaoAdmin(),
            'body' => ['id' => $id, 'email' => 'financeiro(at)heineken'],
        ]));

        self::assertSame(400, $res->status, $res->body);
        self::assertNull($this->db->rows('service_orders')[0]['sent_at']);
    }

    public function testEnvioPorEmailGeraTokenParaOsAntigaSemToken(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, null);

        $res = Endpoint::call('os/send.php', $this->opcoes([
            'session' => $this->sessaoAdmin(),
            'body' => ['id' => $id],
        ]));

        self::assertSame(200, $res->status, $res->body);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            (string) $this->db->rows('service_orders')[0]['share_token']
        );
    }

    public function testEnvioPorEmailResponde404ParaOsInexistente(): void
    {
        $res = Endpoint::call('os/send.php', $this->opcoes([
            'session' => $this->sessaoAdmin(),
            'body' => ['id' => 4242],
        ]));

        self::assertSame(404, $res->status, $res->body);
    }

    // ── WhatsApp do robô ────────────────────────────────────────────────────

    public function testWhatsAppRecusaPapelUser(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('f', 64));

        $res = Endpoint::call('os/whatsapp.php', $this->opcoes([
            'session' => $this->sessaoUser(),
            'body' => ['id' => $id],
        ]));

        self::assertSame(403, $res->status, $res->body);
    }

    /**
     * Sem credenciais da Meta o robô se recusa a tentar — e não deixa a tela
     * achando que a mensagem saiu.
     */
    public function testWhatsAppRespondeQueNaoEstaConfigurado(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('f', 64));

        $res = Endpoint::call('os/whatsapp.php', $this->opcoes([
            'session' => $this->sessaoAdmin(),
            'body' => ['id' => $id],
        ]));

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(503, $res->status, $res->body);
        self::assertSame('whatsapp_not_configured', $res->json()['code'] ?? null);
        self::assertNull($this->db->rows('service_orders')[0]['whatsapp_sent_at']);
    }

    /**
     * A trava de reenvio vem ANTES de qualquer chamada à Meta: é o servidor que
     * sabe que a OS já saiu, e é ele que exige a confirmação da tela.
     */
    public function testWhatsAppExigeConfirmacaoQuandoAOsJaFoiEnviada(): void
    {
        $id = $this->db->seedServiceOrder(
            $this->clientId,
            str_repeat('f', 64),
            '2026-09-03',
            '2026-09-03 14:22:00',
            '5521999887766'
        );

        $res = Endpoint::call('os/whatsapp.php', $this->opcoes([
            'session' => $this->sessaoAdmin(),
            'server' => ['HTTP_HOST' => 'ecolevaeco.com'],
            'body' => ['id' => $id],
        ]));

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(409, $res->status, $res->body);

        $json = $res->json();
        self::assertSame('whatsapp_already_sent', $json['code'] ?? null);
        self::assertSame('2026-09-03 14:22:00', $json['whatsapp_sent_at'] ?? null);
        self::assertSame('5521999887766', $json['whatsapp_sent_to'] ?? null);
    }

    public function testWhatsAppRecusaClienteSemNumero(): void
    {
        $semNumero = $this->db->seedClient('Cliente Sem Zap', 100.0, 10, 'active', null);
        $id = $this->db->seedServiceOrder($semNumero, str_repeat('f', 64));

        $res = Endpoint::call('os/whatsapp.php', $this->opcoes([
            'session' => $this->sessaoAdmin(),
            'body' => ['id' => $id],
        ]));

        self::assertSame(400, $res->status, $res->body);
        self::assertSame('whatsapp_missing_number', $res->json()['code'] ?? null);
    }
}
