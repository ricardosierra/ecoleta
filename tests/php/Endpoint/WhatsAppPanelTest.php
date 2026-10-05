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
            'midia'     => ['whatsapp/media.php'],
            'templates' => ['whatsapp/templates.php'],
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

    /** Master agora tem acesso ao painel de WhatsApp. */
    #[DataProvider('endpointsDoPainel')]
    public function testMasterEntra(string $script): void
    {
        $id = $this->db->seedUser('gerente', 'senha-master-123', 'master', 'gerente@exemplo.com');

        $res = $this->chamar($script, ['user_id' => $id, 'role' => 'master', 'login' => 'gerente']);

        self::assertNotSame(403, $res->status, $res->body);
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

    public function testMarcarComoNaoLida(): void
    {
        $sessao = $this->sessaoPermitida();
        $conversaId = $this->semearConversa(null, null, 0);

        $res = Endpoint::call('whatsapp/messages.php', [
            'method' => 'POST',
            'dsn' => $this->db->dsn(),
            'session' => $sessao,
            'body' => ['action' => 'mark_unread', 'conversation_id' => $conversaId],
        ]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame(1, (int) $this->db->rows('whatsapp_conversations')[0]['unread_count']);
    }

    public function testAlternarStatusConversa(): void
    {
        $sessao = $this->sessaoPermitida();
        $conversaId = $this->semearConversa(null, null, 0);

        // De open para closed
        $res = Endpoint::call('whatsapp/conversations.php', [
            'method' => 'POST',
            'dsn' => $this->db->dsn(),
            'session' => $sessao,
            'body' => ['action' => 'toggle_status', 'conversation_id' => $conversaId],
        ]);

        self::assertSame(200, $res->status, $res->body);
        self::assertSame('closed', $res->json()['status']);
        self::assertSame('closed', $this->db->rows('whatsapp_conversations')[0]['status']);

        // De closed de volta para open
        $res2 = Endpoint::call('whatsapp/conversations.php', [
            'method' => 'POST',
            'dsn' => $this->db->dsn(),
            'session' => $sessao,
            'body' => ['action' => 'toggle_status', 'conversation_id' => $conversaId],
        ]);

        self::assertSame(200, $res2->status, $res2->body);
        self::assertSame('open', $res2->json()['status']);
    }

    public function testIniciarNovaConversaComCliente(): void
    {
        $sessao = $this->sessaoPermitida();
        $clientId = $this->db->seedClient('Ambev', 800.0, 15, 'active', '5521988887777');

        $res = Endpoint::call('whatsapp/conversations.php', [
            'method' => 'POST',
            'dsn' => $this->db->dsn(),
            'session' => $sessao,
            'body' => [
                'action' => 'start',
                'phone' => '21 98888-7777',
                'client_id' => $clientId,
            ],
        ]);

        self::assertSame(200, $res->status, $res->body);
        $conversa = $res->json()['conversation'];
        self::assertSame('5521988887777', $conversa['phone']);
        self::assertSame($clientId, $conversa['client_id']);
        self::assertSame('open', $conversa['status']);
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

    public function testBackfillDeOsApareceNoHistorico(): void
    {
        $sessao = $this->sessaoPermitida();
        $clientId = $this->db->seedClient('Ambev', 500.0, 10, 'active', '5521988887777');

        // Cria uma OS enviada por WhatsApp no passado
        $stmt = $this->db->pdo()->prepare('
            INSERT INTO service_orders
                (client_id, collection_date, whatsapp_sent_at, whatsapp_sent_to, created_at)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $clientId,
            '2026-03-01',
            '2026-03-01 10:00:00',
            '5521988887777',
            '2026-03-01 10:00:00',
        ]);
        $osId = (int) $this->db->pdo()->lastInsertId();

        // Ao abrir a lista de conversas, o backfill processa a OS
        $resList = $this->chamar('whatsapp/conversations.php', $sessao);
        self::assertSame(200, $resList->status);
        $conversas = $resList->json()['conversations'];
        self::assertNotEmpty($conversas);

        $conversaId = $conversas[0]['id'];

        // Ao abrir as mensagens da conversa, a OS aparece com o service_order_id
        $resMsg = $this->chamar('whatsapp/messages.php', $sessao, ['conversation_id' => $conversaId]);
        self::assertSame(200, $resMsg->status);
        $msgs = $resMsg->json()['messages'];
        self::assertCount(1, $msgs);
        self::assertSame($osId, $msgs[0]['service_order_id']);
        self::assertStringContainsString((string) $osId, $msgs[0]['body']);
    }

    // ── Auxiliares das conversas e das mensagens ─────────────────────────────────

    /**
     * Conversa com todos os campos que a unificação e a busca leem. O que não
     * vier em $campos recebe um valor neutro.
     *
     * @param array<string,mixed> $campos
     */
    private function semearConversaCom(string $telefone, array $campos = []): int
    {
        $agora = gmdate('Y-m-d H:i:s');
        $linha = array_merge([
            'phone' => $telefone,
            'profile_name' => null,
            'client_id' => null,
            'status' => 'open',
            'unread_count' => 0,
            'last_inbound_at' => null,
            'last_message_at' => null,
            'last_message_preview' => null,
            'last_message_direction' => null,
            'service_window_expires_at' => null,
            'created_at' => $agora,
            'updated_at' => $agora,
        ], $campos);

        $colunas = implode(', ', array_keys($linha));
        $marcadores = implode(', ', array_fill(0, count($linha), '?'));
        $stmt = $this->db->pdo()->prepare("INSERT INTO whatsapp_conversations ({$colunas}) VALUES ({$marcadores})");
        $stmt->execute(array_values($linha));

        return (int) $this->db->pdo()->lastInsertId();
    }

    // ── dois clientes no mesmo telefone ─────────────────────────────────

    /**
     * A sincronização da lista cria conversa para cliente com WhatsApp. Com dois
     * clientes no mesmo número, cada GET reatribuía a conversa ao cliente que
     * "não tinha conversa" (Associação, Condomínio, Associação...). O dono é o
     * primeiro e fica.
     */
    public function testDoisClientesNoMesmoTelefoneNaoTrocamODonoDaConversa(): void
    {
        $sessao = $this->sessaoPermitida();
        $a = $this->db->seedClient('Associação', 0.0, 10, 'active', '5521999887766');
        $this->db->seedClient('Condomínio', 0.0, 10, 'active', '(21) 99988-7766');

        $donos = [];
        $nomes = [];
        for ($i = 0; $i < 4; $i++) {
            $res = $this->chamar('whatsapp/conversations.php', $sessao);
            self::assertSame(200, $res->status, $res->body);
            $conversas = $res->json()['conversations'];
            self::assertCount(1, $conversas);
            $donos[] = $conversas[0]['client_id'];
            $nomes[] = $conversas[0]['name'];
        }

        self::assertSame([$a, $a, $a, $a], $donos);
        self::assertSame(['Associação', 'Associação', 'Associação', 'Associação'], $nomes);
    }

    // ── unificação de conversas duplicadas ──────────────────────────────

    /**
     * 12 e 13 dígitos do mesmo celular viram uma conversa só, e a unificação
     * mantém a de menor id. O que ela NÃO pode fazer é jogar fora o estado da
     * outra: o cliente escreveu há 4 horas, a janela estava aberta, e depois do
     * GET a conversa mantida aparecia fechada, sem não lidas e encerrada.
     */
    public function testUnificacaoPreservaJanelaNaoLidasStatusEPerfil(): void
    {
        $sessao = $this->sessaoPermitida();
        $clientId = $this->db->seedClient('Heineken', 500.0, 10, 'active', '5521999887766');

        $quatroHorasAtras = gmdate('Y-m-d H:i:s', time() - 4 * 3600);
        $janela = gmdate('Y-m-d H:i:s', time() + 20 * 3600);

        // Mantida (menor id): 13 dígitos, encerrada, sem janela, sem perfil.
        $mantida = $this->semearConversaCom('5521999887766', [
            'status' => 'closed',
            'unread_count' => 1,
            'last_inbound_at' => gmdate('Y-m-d H:i:s', time() - 3 * 86400),
            'service_window_expires_at' => gmdate('Y-m-d H:i:s', time() - 2 * 86400),
        ]);
        // Descartada: 12 dígitos, aberta, janela aberta, com perfil e cliente.
        $descartada = $this->semearConversaCom('552199887766', [
            'status' => 'open',
            'unread_count' => 2,
            'profile_name' => 'João da Heineken',
            'client_id' => $clientId,
            'last_inbound_at' => $quatroHorasAtras,
            'last_message_at' => $quatroHorasAtras,
            'last_message_preview' => 'Bom dia',
            'last_message_direction' => 'incoming',
            'service_window_expires_at' => $janela,
        ]);
        $this->semearMensagem($descartada, 'incoming', 'Bom dia', $quatroHorasAtras);

        $res = $this->chamar('whatsapp/conversations.php', $sessao);

        self::assertSame(200, $res->status, $res->body);
        $conversas = $res->json()['conversations'];
        self::assertCount(1, $conversas);

        $c = $conversas[0];
        self::assertSame($mantida, $c['id']);
        self::assertTrue($c['window']['open'], 'a janela aberta da outra conversa foi perdida');
        self::assertSame(str_replace(' ', 'T', $janela) . 'Z', $c['window']['expires_at']);
        self::assertSame(3, $c['unread_count']);
        self::assertSame('open', $c['status']);
        self::assertSame('João da Heineken', $c['profile_name']);
        self::assertSame($clientId, $c['client_id']);
        self::assertSame('Bom dia', $c['last_message_preview']);

        $linha = $this->db->rows('whatsapp_conversations')[0];
        self::assertSame($quatroHorasAtras, $linha['last_inbound_at']);
        self::assertSame(1, $this->db->count('whatsapp_conversations'));
    }

    /** O perfil e o cliente da mantida valem mais que os da descartada. */
    public function testUnificacaoNaoSobrescreveOQueAMantidaJaTinha(): void
    {
        $sessao = $this->sessaoPermitida();
        $dono = $this->db->seedClient('Dono', 0.0, 10, 'active', null);
        $outro = $this->db->seedClient('Outro', 0.0, 10, 'active', null);

        $futuro = gmdate('Y-m-d H:i:s', time() + 5 * 3600);
        $maisLonge = gmdate('Y-m-d H:i:s', time() + 20 * 3600);

        $this->semearConversaCom('5521999887766', [
            'profile_name' => 'Nome da mantida',
            'client_id' => $dono,
            'last_inbound_at' => gmdate('Y-m-d H:i:s', time() - 4 * 3600),
            'service_window_expires_at' => $maisLonge,
        ]);
        $this->semearConversaCom('552199887766', [
            'profile_name' => 'Nome da descartada',
            'client_id' => $outro,
            'last_inbound_at' => gmdate('Y-m-d H:i:s', time() - 19 * 3600),
            'service_window_expires_at' => $futuro,
        ]);

        $res = $this->chamar('whatsapp/conversations.php', $sessao);

        $c = $res->json()['conversations'][0];
        self::assertSame('Nome da mantida', $c['profile_name']);
        self::assertSame($dono, $c['client_id']);
        // A maior das duas janelas vence, nunca a menor.
        self::assertSame(str_replace(' ', 'T', $maisLonge) . 'Z', $c['window']['expires_at']);
    }

    // ── paginação e busca sem silêncio ──────────────────────────────────

    /** @param int $quantas mensagens, de 1 minuto em 1 minuto a partir de 2026-03-01 */
    private function semearMuitasMensagens(int $conversaId, int $quantas): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO whatsapp_messages
                (conversation_id, direction, type, status, body, message_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $inicio = strtotime('2026-03-01 00:00:00 UTC');
        for ($i = 1; $i <= $quantas; $i++) {
            $stmt->execute([
                $conversaId, 'incoming', 'text', null, 'msg ' . $i,
                gmdate('Y-m-d H:i:s', $inicio + $i * 60), gmdate('Y-m-d H:i:s'),
            ]);
        }
        $pdo->commit();
    }

    /**
     * ORDER BY ... ASC LIMIT 500 devolve as 500 MAIS ANTIGAS: numa conversa
     * longa, as mensagens novas sumiam da tela. Devolve as 500 mais recentes,
     * em ordem cronológica.
     */
    public function testConversaLongaDevolveAsMensagensMaisRecentes(): void
    {
        $sessao = $this->sessaoPermitida();
        $conversaId = $this->semearConversa(null, null, 0);
        $this->semearMuitasMensagens($conversaId, 620);

        $res = $this->chamar('whatsapp/messages.php', $sessao, ['conversation_id' => $conversaId]);

        self::assertSame(200, $res->status, $res->body);
        $json = $res->json();
        $mensagens = $json['messages'];

        self::assertCount(500, $mensagens);
        self::assertSame('msg 121', $mensagens[0]['body']);
        self::assertSame('msg 620', $mensagens[499]['body']);
        // Em ordem cronológica, como a tela espera.
        $horas = array_column($mensagens, 'message_at');
        $ordenadas = $horas;
        sort($ordenadas);
        self::assertSame($ordenadas, $horas);
        // E avisa que há mais antigas, em vez de cortar em silêncio.
        self::assertTrue($json['truncated']);
    }

    public function testConversaCurtaNaoMarcaTruncada(): void
    {
        $sessao = $this->sessaoPermitida();
        $conversaId = $this->semearConversa(null, null, 0);
        $this->semearMuitasMensagens($conversaId, 3);

        $res = $this->chamar('whatsapp/messages.php', $sessao, ['conversation_id' => $conversaId]);

        self::assertCount(3, $res->json()['messages']);
        self::assertFalse($res->json()['truncated']);
    }

    /** Lista cortada em 300: a resposta diz quantas existem, e a busca do servidor alcança a 301ª. */
    public function testListaCortadaInformaOTotalEABuscaAchaAConversaAntiga(): void
    {
        $sessao = $this->sessaoPermitida();

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        for ($i = 1; $i <= 305; $i++) {
            $this->semearConversaCom('55219' . str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT), [
                'profile_name' => $i === 1 ? 'Zeta Mais Antiga' : 'Contato ' . $i,
                // A número 1 é a mais antiga, então cai fora do corte de 300.
                'last_message_at' => gmdate('Y-m-d H:i:s', strtotime('2026-03-01 00:00:00 UTC') + $i * 60),
                'last_message_preview' => 'oi',
            ]);
        }
        $pdo->commit();

        $lista = $this->chamar('whatsapp/conversations.php', $sessao)->json();
        self::assertCount(300, $lista['conversations']);
        self::assertSame(305, $lista['total']);
        self::assertTrue($lista['truncated']);

        $busca = $this->chamar('whatsapp/conversations.php', $sessao, ['q' => 'Zeta'])->json();
        self::assertCount(1, $busca['conversations']);
        self::assertSame('Zeta Mais Antiga', $busca['conversations'][0]['name']);
        self::assertFalse($busca['truncated']);
    }

    /** "Posto 3" é nome com número, não telefone: não pode casar com todo telefone que tenha um 3. */
    public function testBuscaPorNomeComDigitoNaoCasaComTelefone(): void
    {
        $sessao = $this->sessaoPermitida();
        $this->semearConversaCom('5521933333333', ['profile_name' => 'Padaria', 'last_message_preview' => 'oi']);
        $this->semearConversaCom('5521988887777', ['profile_name' => 'Posto 3', 'last_message_preview' => 'oi']);

        $res = $this->chamar('whatsapp/conversations.php', $sessao, ['q' => 'Posto 3'])->json();

        self::assertCount(1, $res['conversations']);
        self::assertSame('Posto 3', $res['conversations'][0]['name']);
    }

    /** Busca só de número (com máscara) continua achando pelo telefone. */
    public function testBuscaPorTelefoneComMascaraContinuaFuncionando(): void
    {
        $sessao = $this->sessaoPermitida();
        $this->semearConversaCom('5521933333333', ['profile_name' => 'Padaria', 'last_message_preview' => 'oi']);
        $this->semearConversaCom('5521988887777', ['profile_name' => 'Posto', 'last_message_preview' => 'oi']);

        $res = $this->chamar('whatsapp/conversations.php', $sessao, ['q' => '(21) 98888-7777'])->json();

        self::assertCount(1, $res['conversations']);
        self::assertSame('Posto', $res['conversations'][0]['name']);
    }

    // ── áudio gravado no navegador ──────────────────────────────────────

    /** @return array{0:int,1:array<string,mixed>} id da conversa e a sessão */
    private function conversaComJanelaAberta(): array
    {
        $sessao = $this->sessaoPermitida();
        $conversaId = $this->semearConversaCom('5521999887766', [
            'service_window_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);

        return [$conversaId, $sessao];
    }

    /** @param array<string,mixed> $body */
    private function enviarMidia(array $sessao, array $body): EndpointResponse
    {
        return Endpoint::call('whatsapp/media.php', [
            'method' => 'POST',
            'dsn' => $this->db->dsn(),
            'session' => $sessao,
            'body' => $body,
            // O robô desligado: quem passa da validação para no 503, sem tocar na Meta.
            'env' => ['WHATSAPP_TRANSPORT' => 'off'],
        ]);
    }

    /**
     * O Chrome gera "data:audio/webm;codecs=opus;base64,...", que não casava com
     * a regex do servidor: a pessoa recebia "Arquivo base64 corrompido" para um
     * arquivo perfeito. O defeito real é outro, e a mensagem tem que dizê-lo: a
     * Meta não aceita webm.
     */
    public function testAudioWebmDoChromeExplicaOsFormatosAceitos(): void
    {
        [$conversaId, $sessao] = $this->conversaComJanelaAberta();

        $res = $this->enviarMidia($sessao, [
            'conversation_id' => $conversaId,
            'type' => 'audio',
            'mime_type' => 'audio/webm;codecs=opus',
            'filename' => 'audio_1.ogg',
            'media_base64' => 'data:audio/webm;codecs=opus;base64,' . base64_encode(str_repeat('x', 2000)),
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(400, $res->status, $res->body);
        $erro = $res->json()['error'];
        self::assertStringNotContainsString('corrompido', $erro);
        foreach (['ogg', 'opus', 'mp3', 'aac', 'amr', 'mp4'] as $formato) {
            self::assertStringContainsString($formato, $erro);
        }
        self::assertSame('unsupported_audio_format', $res->json()['code']);
    }

    /** Sem o campo mime_type, o tipo vem do próprio data URI, com parâmetros e tudo. */
    public function testAudioWebmSoNoDataUriTambemEhRecusadoComMensagemClara(): void
    {
        [$conversaId, $sessao] = $this->conversaComJanelaAberta();

        $res = $this->enviarMidia($sessao, [
            'conversation_id' => $conversaId,
            'type' => 'audio',
            'media_base64' => 'data:audio/webm;codecs=opus;base64,' . base64_encode(str_repeat('x', 2000)),
        ]);

        self::assertSame(400, $res->status, $res->body);
        self::assertStringNotContainsString('corrompido', $res->json()['error']);
        self::assertSame('unsupported_audio_format', $res->json()['code']);
    }

    /** Ogg com opus tem data URI com parâmetros e é aceito: passa da validação e para no robô desligado. */
    public function testAudioOggOpusComParametrosNoDataUriPassaDaValidacao(): void
    {
        [$conversaId, $sessao] = $this->conversaComJanelaAberta();

        $res = $this->enviarMidia($sessao, [
            'conversation_id' => $conversaId,
            'type' => 'audio',
            'mime_type' => 'audio/ogg;codecs=opus',
            'media_base64' => 'data:audio/ogg;codecs=opus;base64,' . base64_encode(str_repeat('x', 2000)),
        ]);

        self::assertSame(503, $res->status, $res->body);
        self::assertSame('whatsapp_not_configured', $res->json()['code']);
    }

    public function testAudioMp3EmBase64PuroPassaDaValidacao(): void
    {
        [$conversaId, $sessao] = $this->conversaComJanelaAberta();

        $res = $this->enviarMidia($sessao, [
            'conversation_id' => $conversaId,
            'type' => 'audio',
            'mime_type' => 'audio/mpeg',
            'media_base64' => base64_encode(str_repeat('x', 2000)),
        ]);

        self::assertSame(503, $res->status, $res->body);
    }

    /** Imagem com data URI simples continua passando como antes. */
    public function testImagemComDataUriSimplesContinuaPassando(): void
    {
        [$conversaId, $sessao] = $this->conversaComJanelaAberta();

        $res = $this->enviarMidia($sessao, [
            'conversation_id' => $conversaId,
            'type' => 'image',
            'media_base64' => 'data:image/png;base64,' . base64_encode(str_repeat('x', 2000)),
        ]);

        self::assertSame(503, $res->status, $res->body);
    }

    /** Base64 que de fato está quebrado continua sendo "corrompido". */
    public function testBase64QuebradoContinuaSendoCorrompido(): void
    {
        [$conversaId, $sessao] = $this->conversaComJanelaAberta();

        $res = $this->enviarMidia($sessao, [
            'conversation_id' => $conversaId,
            'type' => 'image',
            'mime_type' => 'image/png',
            'media_base64' => 'data:image/png;base64,%%%isso nao e base64%%%',
        ]);

        self::assertSame(400, $res->status, $res->body);
        self::assertStringContainsString('corrompido', $res->json()['error']);
    }

    /** O mesmo vale para quem anexa o arquivo (multipart) em vez de mandar base64. */
    public function testAudioWebmAnexadoComoArquivoTambemExplicaOsFormatos(): void
    {
        [$conversaId, $sessao] = $this->conversaComJanelaAberta();

        $tmp = tempnam(sys_get_temp_dir(), 'wa_test_');
        file_put_contents($tmp, str_repeat('x', 2000));

        try {
            $res = Endpoint::call('whatsapp/media.php', [
                'method' => 'POST',
                'dsn' => $this->db->dsn(),
                'session' => $sessao,
                'env' => ['WHATSAPP_TRANSPORT' => 'off'],
                'csrf' => true,
                'server' => ['CONTENT_TYPE' => 'multipart/form-data; boundary=ecoleta-test'],
                'post' => ['conversation_id' => (string) $conversaId, 'type' => 'audio'],
                'files' => ['file' => [
                    'name' => 'gravacao.webm',
                    'type' => 'audio/webm',
                    'tmp_name' => $tmp,
                    'error' => 0,
                    'size' => 2000,
                ]],
            ]);
        } finally {
            @unlink($tmp);
        }

        self::assertSame(400, $res->status, $res->body);
        self::assertSame('unsupported_audio_format', $res->json()['code']);
    }

    // ── templates sem inventar aprovação ────────────────────────────────

    /** @param array<string,string> $env */
    private function listarTemplates(array $env = []): EndpointResponse
    {
        return Endpoint::call('whatsapp/templates.php', [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'session' => $this->sessaoPermitida(),
            'env' => $env,
        ]);
    }

    /**
     * Sem credencial da Meta o painel mostrava dois templates "APPROVED" com texto
     * inventado. Agora a lista vem vazia e a resposta traz o motivo, em português.
     */
    public function testSemCredenciaisDaMetaNaoHaTemplateAprovadoInventado(): void
    {
        $res = $this->listarTemplates();

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);
        $json = $res->json();
        self::assertSame([], $json['templates']);
        self::assertStringContainsString('WHATSAPP_ACCESS_TOKEN', (string) $json['error']);
        self::assertStringNotContainsString('APPROVED', $res->body);
    }

    public function testTemplatesConfiguradosSemMetaSaemComoNaoVerificados(): void
    {
        $res = $this->listarTemplates([
            'WHATSAPP_OS_TEMPLATE' => 'meu_os',
            'WHATSAPP_BILLING_TEMPLATE' => 'minha_cobranca',
        ]);

        $json = $res->json();
        self::assertNotNull($json['error']);
        self::assertSame(['meu_os', 'minha_cobranca'], array_column($json['templates'], 'name'));
        self::assertSame(['UNVERIFIED', 'UNVERIFIED'], array_column($json['templates'], 'status'));
        self::assertSame('', $json['templates'][0]['body_text']);
        self::assertSame(['Nome do Cliente', 'Número da OS', 'Link da OS'], $json['templates'][0]['param_labels']);
        self::assertStringNotContainsString('APPROVED', $res->body);
    }
}
