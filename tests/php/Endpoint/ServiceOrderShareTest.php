<?php
declare(strict_types=1);

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
                'weight' => '150 kg',
                'collection_date' => '2026-09-03',
                'bags_count' => '12',
                'containers_count' => '2',
                'responsible' => 'Equipe A',
            ],
        ]));

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);

        $token = (string) $this->db->rows('service_orders')[0]['share_token'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        $criada = $res->json()['service_order'] ?? [];
        self::assertStringContainsString('api/os/view.php?id=', (string) ($criada['share_url'] ?? ''));
        self::assertStringContainsString('t=' . $token, (string) ($criada['share_url'] ?? ''));
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
