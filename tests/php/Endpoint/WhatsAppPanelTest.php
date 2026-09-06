<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O painel de conversas e quem pode abri-lo.
 *
 * Conversa de cliente é o material mais sensível do dashboard. O corte não é o
 * de sempre ("é admin?"): exige `root` E estar na lista de
 * `API_WHATSAPP_PANEL_EMAILS`. Estes casos travam as duas condições, porque
 * afrouxar qualquer uma delas é uma linha de código.
 */
final class WhatsAppPanelTest extends TestCase
{
    private const EMAIL_PERMITIDO = 'sierra.csi@gmail.com';

    private TestDatabase $db;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    /** @return array<string, array{0:string}> */
    public static function endpointsDoPainel(): array
    {
        return [
            'conversas' => ['whatsapp/conversations.php'],
            'mensagens' => ['whatsapp/messages.php'],
        ];
    }

    private function chamar(string $script, array $session, array $query = []): EndpointResponse
    {
        return Endpoint::call($script, [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'session' => $session,
            'query' => $query,
        ]);
    }

    // ── Quem não entra ──────────────────────────────────────────────────────

    #[DataProvider('endpointsDoPainel')]
    public function testSemSessaoNaoEntra(string $script): void
    {
        $res = Endpoint::call($script, ['method' => 'GET', 'dsn' => $this->db->dsn()]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(401, $res->status, $res->body);
    }

    #[DataProvider('endpointsDoPainel')]
    public function testPapelUserNaoEntra(string $script): void
    {
        $grupoId = $this->db->seedGroup('Coleta');
        $id = $this->db->seedUser('joao', 'senha-user-123', 'user', 'joao@exemplo.com', $grupoId);

        $res = $this->chamar($script, ['user_id' => $id, 'role' => 'user', 'login' => 'joao']);

        self::assertSame(403, $res->status, $res->body);
    }

    /** Master administra usuários, mas não lê conversa de cliente. */
    #[DataProvider('endpointsDoPainel')]
    public function testMasterNaoEntra(string $script): void
    {
        $id = $this->db->seedUser('gerente', 'senha-master-123', 'master', 'gerente@exemplo.com');

        $res = $this->chamar($script, ['user_id' => $id, 'role' => 'master', 'login' => 'gerente']);

        self::assertSame(403, $res->status, $res->body);
    }

    /** Ser root não basta: o e-mail precisa estar na lista. */
    #[DataProvider('endpointsDoPainel')]
    public function testRootForaDaListaNaoEntra(string $script): void
    {
        $id = $this->db->seedUser('outro', 'senha-root-1234', 'root', 'outro.root@exemplo.com');

        $res = $this->chamar($script, ['user_id' => $id, 'role' => 'root', 'login' => 'outro']);

        self::assertSame(403, $res->status, $res->body);
    }

    /**
     * O e-mail vale é o GRAVADO na conta. Uma sessão que se diga da conta certa
     * mas aponte para um id sem esse e-mail não entra.
     */
    #[DataProvider('endpointsDoPainel')]
    public function testEmailVemDoBancoENaoDaSessao(string $script): void
    {
        $id = $this->db->seedUser('impostor', 'senha-root-1234', 'root', 'impostor@exemplo.com');

        $res = Endpoint::call($script, [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'session' => [
                'user_id' => $id,
                'role' => 'root',
                'login' => 'impostor',
                'email' => self::EMAIL_PERMITIDO,
            ],
        ]);

        self::assertSame(403, $res->status, $res->body);
    }

    // ── Quem entra ──────────────────────────────────────────────────────────

    private function sessaoPermitida(): array
    {
        $id = $this->db->seedUser('sierra', 'senha-root-1234', 'root', self::EMAIL_PERMITIDO);

        return ['user_id' => $id, 'role' => 'root', 'login' => 'sierra'];
    }

    public function testListagemDeConversas(): void
    {
        $sessao = $this->sessaoPermitida();
        $clientId = $this->db->seedClient('Heineken', 500.0, 10, 'active', '5521999887766');
        $this->semearConversa($clientId, '2026-03-04 15:22:00', 3);

        $res = $this->chamar('whatsapp/conversations.php', $sessao);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);

        $conversa = $res->json()['conversations'][0] ?? [];
        // O nome do cadastro vence o do perfil do WhatsApp.
        self::assertSame('Heineken', $conversa['name'] ?? null);
        self::assertSame(3, $conversa['unread_count'] ?? null);
        self::assertSame('2026-03-04T15:22:00Z', $conversa['window']['expires_at'] ?? null);
    }

    public function testConversaSemClienteUsaONomeDoPerfil(): void
    {
        $sessao = $this->sessaoPermitida();
        $this->semearConversa(null, null, 0);

        $res = $this->chamar('whatsapp/conversations.php', $sessao);

        self::assertSame('João da Heineken', $res->json()['conversations'][0]['name'] ?? null);
    }

    public function testMensagensDaConversaEmOrdemCronologica(): void
    {
        $sessao = $this->sessaoPermitida();
        $conversaId = $this->semearConversa(null, null, 1);

        $this->semearMensagem($conversaId, 'incoming', 'Bom dia', '2026-03-03 15:22:00');
        $this->semearMensagem($conversaId, 'outgoing', 'OS a caminho', '2026-03-03 15:30:00');

        $res = $this->chamar('whatsapp/messages.php', $sessao, ['conversation_id' => $conversaId]);

        self::assertSame(200, $res->status, $res->body);

        $mensagens = $res->json()['messages'];
        self::assertCount(2, $mensagens);
        self::assertSame('Bom dia', $mensagens[0]['body']);
        self::assertSame('incoming', $mensagens[0]['direction']);
        self::assertSame('OS a caminho', $mensagens[1]['body']);
        self::assertSame('2026-03-03T15:30:00Z', $mensagens[1]['message_at']);
    }

    public function testConversaInexistenteResponde404(): void
    {
        $res = $this->chamar('whatsapp/messages.php', $this->sessaoPermitida(), ['conversation_id' => 999]);

        self::assertSame(404, $res->status, $res->body);
    }

    public function testMarcarComoLidaZeraOContador(): void
    {
        $sessao = $this->sessaoPermitida();
        $conversaId = $this->semearConversa(null, null, 5);

        $res = Endpoint::call('whatsapp/messages.php', [
            'method' => 'POST',
            'dsn' => $this->db->dsn(),
            'session' => $sessao,
            'body' => ['conversation_id' => $conversaId],
        ]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame(0, (int) $this->db->rows('whatsapp_conversations')[0]['unread_count']);
    }

    /** Escrita exige token CSRF como qualquer outro POST do dashboard. */
    public function testMarcarComoLidaExigeTokenCsrf(): void
    {
        $sessao = $this->sessaoPermitida();
        $conversaId = $this->semearConversa(null, null, 5);

        $res = Endpoint::call('whatsapp/messages.php', [
            'method' => 'POST',
            'dsn' => $this->db->dsn(),
            'session' => $sessao,
            'csrf' => false,
            'body' => ['conversation_id' => $conversaId],
        ]);

        self::assertSame(403, $res->status, $res->body);
        self::assertSame(5, (int) $this->db->rows('whatsapp_conversations')[0]['unread_count']);
    }

    private function semearConversa(?int $clientId, ?string $janela, int $naoLidas): int
    {
        $pdo = $this->db->pdo();
        $agora = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO whatsapp_conversations
                (phone, profile_name, client_id, status, unread_count, last_message_at,
                 last_message_preview, last_message_direction, service_window_expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            '5521999887766',
            'João da Heineken',
            $clientId,
            'open',
            $naoLidas,
            '2026-03-03 15:22:00',
            'Bom dia',
            'incoming',
            $janela,
            $agora,
            $agora,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function semearMensagem(int $conversaId, string $direcao, string $corpo, string $quando): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO whatsapp_messages
                (conversation_id, direction, type, status, body, message_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$conversaId, $direcao, 'text', 'delivered', $corpo, $quando, gmdate('Y-m-d H:i:s')]);
    }
}
