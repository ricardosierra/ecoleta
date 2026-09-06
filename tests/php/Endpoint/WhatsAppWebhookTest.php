<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * O webhook do WhatsApp rodando de verdade.
 *
 * É o único endpoint público que ESCREVE no banco, e o que decide quando a
 * janela de 24 horas abre — ou seja, quando o envio da OS deixa de ser cobrado.
 * Por isso a cobertura aqui é de autenticidade (assinatura da Meta) antes de
 * ser de funcionalidade.
 */
final class WhatsAppWebhookTest extends TestCase
{
    private const APP_SECRET = 'segredo-de-teste-do-app';

    private const VERIFY_TOKEN = 'token-de-verificacao';

    private TestDatabase $db;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
    }

    protected function tearDown(): void
    {
        $this->db->destroy();
    }

    /** @return array<string,mixed> */
    private function env(): array
    {
        return [
            'WHATSAPP_APP_SECRET' => self::APP_SECRET,
            'WHATSAPP_WEBHOOK_VERIFY_TOKEN' => self::VERIFY_TOKEN,
        ];
    }

    /** Corpo assinado como a Meta assina: HMAC-SHA256 do corpo cru. */
    private function postar(array $payload, array $opcoes = []): EndpointResponse
    {
        $corpo = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $assinatura = $opcoes['signature']
            ?? 'sha256=' . hash_hmac('sha256', $corpo, self::APP_SECRET);

        return Endpoint::call('webhooks/whatsapp.php', [
            'method' => 'POST',
            'dsn' => $this->db->dsn(),
            'env' => array_merge($this->env(), $opcoes['env'] ?? []),
            'body' => $corpo,
            'csrf' => false,
            'server' => ['HTTP_X_HUB_SIGNATURE_256' => $assinatura],
        ]);
    }

    /** Um evento `messages` no formato que a Meta entrega. */
    private function eventoDeMensagem(
        string $waMessageId = 'wamid.TESTE1',
        string $texto = 'Bom dia, pode passar hoje?',
        int $timestamp = 1772551320,
        string $from = '5521999887766'
    ): array {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '123',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'contacts' => [[
                            'wa_id' => $from,
                            'profile' => ['name' => 'João da Heineken'],
                        ]],
                        'messages' => [[
                            'id' => $waMessageId,
                            'from' => $from,
                            'timestamp' => (string) $timestamp,
                            'type' => 'text',
                            'text' => ['body' => $texto],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    // ── Verificação da URL ──────────────────────────────────────────────────

    public function testVerificacaoDevolveOChallenge(): void
    {
        $res = Endpoint::call('webhooks/whatsapp.php', [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'env' => $this->env(),
            'csrf' => false,
            'query' => [
                'hub_mode' => 'subscribe',
                'hub_verify_token' => self::VERIFY_TOKEN,
                'hub_challenge' => '1234567890',
            ],
        ]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status);
        self::assertSame('1234567890', $res->body);
    }

    public function testVerificacaoRecusaTokenErrado(): void
    {
        $res = Endpoint::call('webhooks/whatsapp.php', [
            'method' => 'GET',
            'dsn' => $this->db->dsn(),
            'env' => $this->env(),
            'csrf' => false,
            'query' => [
                'hub_verify_token' => 'token-errado',
                'hub_challenge' => '1234567890',
            ],
        ]);

        self::assertSame(403, $res->status);
        self::assertStringNotContainsString('1234567890', $res->body);
    }

    // ── Autenticidade ───────────────────────────────────────────────────────

    /**
     * Sem esta trava, qualquer um na internet inventaria uma mensagem de cliente
     * e abriria a janela de 24 horas por conta própria.
     */
    public function testEventoComAssinaturaErradaNaoGravaNada(): void
    {
        $res = $this->postar($this->eventoDeMensagem(), ['signature' => 'sha256=' . str_repeat('0', 64)]);

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(403, $res->status);
        self::assertSame(0, $this->db->count('whatsapp_messages'));
        self::assertSame(0, $this->db->count('whatsapp_conversations'));
    }

    public function testEventoSemAssinaturaNaoGravaNada(): void
    {
        $res = $this->postar($this->eventoDeMensagem(), ['signature' => '']);

        self::assertSame(403, $res->status);
        self::assertSame(0, $this->db->count('whatsapp_messages'));
    }

    /** Servidor sem App Secret recusa em vez de aceitar corpo não verificado. */
    public function testSemAppSecretORecebimentoEhRecusado(): void
    {
        $res = $this->postar($this->eventoDeMensagem(), ['env' => ['WHATSAPP_APP_SECRET' => '']]);

        self::assertSame(503, $res->status);
        self::assertSame(0, $this->db->count('whatsapp_messages'));
    }

    // ── Mensagem recebida ───────────────────────────────────────────────────

    public function testMensagemRecebidaCriaConversaEAbreAJanela(): void
    {
        $res = $this->postar($this->eventoDeMensagem());

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status, $res->body);

        $conversas = $this->db->rows('whatsapp_conversations');
        self::assertCount(1, $conversas);
        self::assertSame('5521999887766', $conversas[0]['phone']);
        self::assertSame('João da Heineken', $conversas[0]['profile_name']);
        self::assertSame(1, (int) $conversas[0]['unread_count']);
        self::assertSame('incoming', $conversas[0]['last_message_direction']);
        self::assertSame('Bom dia, pode passar hoje?', $conversas[0]['last_message_preview']);

        // A janela é exatamente 24h depois do instante da mensagem.
        self::assertSame('2026-03-03 15:22:00', $conversas[0]['last_inbound_at']);
        self::assertSame('2026-03-04 15:22:00', $conversas[0]['service_window_expires_at']);

        $mensagens = $this->db->rows('whatsapp_messages');
        self::assertCount(1, $mensagens);
        self::assertSame('incoming', $mensagens[0]['direction']);
        self::assertSame('wamid.TESTE1', $mensagens[0]['wa_message_id']);
        self::assertSame('Bom dia, pode passar hoje?', $mensagens[0]['body']);
    }

    /** O número do cliente cadastrado com máscara precisa cair na mesma conversa. */
    public function testConversaEhLigadaAoClienteMesmoComTelefoneFormatado(): void
    {
        $clientId = $this->db->seedClient('Heineken', 500.0, 10, 'active', '+55 (21) 99988-7766');

        $this->postar($this->eventoDeMensagem());

        self::assertSame($clientId, (int) $this->db->rows('whatsapp_conversations')[0]['client_id']);
    }

    /**
     * A Meta reentrega o mesmo evento quando o ACK demora. Gravar duas vezes
     * inflaria o contador de não lidas e duplicaria a bolha na tela.
     */
    public function testEventoReentregueNaoDuplicaAMensagem(): void
    {
        $evento = $this->eventoDeMensagem();

        $this->postar($evento);
        $res = $this->postar($evento);

        self::assertSame(200, $res->status);
        self::assertSame(1, $this->db->count('whatsapp_messages'));
        self::assertSame(1, (int) $this->db->rows('whatsapp_conversations')[0]['unread_count']);
    }

    public function testSegundaMensagemEmpurraAJanelaParaFrente(): void
    {
        $this->postar($this->eventoDeMensagem('wamid.A', 'primeira', 1772551320));
        $this->postar($this->eventoDeMensagem('wamid.B', 'segunda', 1772554920));

        $conversa = $this->db->rows('whatsapp_conversations')[0];

        self::assertSame(2, $this->db->count('whatsapp_messages'));
        self::assertSame(2, (int) $conversa['unread_count']);
        self::assertSame('segunda', $conversa['last_message_preview']);
        self::assertSame('2026-03-04 16:22:00', $conversa['service_window_expires_at']);
    }

    public function testMidiaSemLegendaViraRotulo(): void
    {
        $evento = $this->eventoDeMensagem();
        $evento['entry'][0]['changes'][0]['value']['messages'][0] = [
            'id' => 'wamid.IMG',
            'from' => '5521999887766',
            'timestamp' => '1772551320',
            'type' => 'image',
            'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg'],
        ];

        $this->postar($evento);

        self::assertSame('[imagem]', $this->db->rows('whatsapp_messages')[0]['body']);
    }

    public function testLegendaDaMidiaVenceORotulo(): void
    {
        $evento = $this->eventoDeMensagem();
        $evento['entry'][0]['changes'][0]['value']['messages'][0] = [
            'id' => 'wamid.IMG2',
            'from' => '5521999887766',
            'timestamp' => '1772551320',
            'type' => 'image',
            'image' => ['id' => 'media-2', 'caption' => 'Foto da caçamba cheia'],
        ];

        $this->postar($evento);

        self::assertSame('Foto da caçamba cheia', $this->db->rows('whatsapp_messages')[0]['body']);
    }

    // ── Confirmações de entrega ─────────────────────────────────────────────

    public function testStatusAvancaOEstadoDaMensagemEnviada(): void
    {
        $conversaId = $this->semearConversaComEnvio('wamid.SAIDA1');

        $this->postarStatus('wamid.SAIDA1', 'delivered');

        self::assertSame('delivered', $this->db->rows('whatsapp_messages')[0]['status']);
        self::assertSame(0, (int) $this->db->rows('whatsapp_conversations')[0]['unread_count']);
        self::assertGreaterThan(0, $conversaId);
    }

    /** `sent` atrasado, chegando depois do `read`, não pode rebaixar o estado. */
    public function testStatusAtrasadoNaoRetrocede(): void
    {
        $this->semearConversaComEnvio('wamid.SAIDA2');

        $this->postarStatus('wamid.SAIDA2', 'read');
        $this->postarStatus('wamid.SAIDA2', 'sent');

        self::assertSame('read', $this->db->rows('whatsapp_messages')[0]['status']);
    }

    public function testStatusDeFalhaGuardaOMotivo(): void
    {
        $this->semearConversaComEnvio('wamid.SAIDA3');

        $this->postarStatus('wamid.SAIDA3', 'failed', 'Message undeliverable');

        $mensagem = $this->db->rows('whatsapp_messages')[0];
        self::assertSame('failed', $mensagem['status']);
        self::assertSame('Message undeliverable', $mensagem['error_message']);
    }

    public function testStatusDeMensagemDesconhecidaNaoQuebra(): void
    {
        $res = $this->postarStatus('wamid.NUNCA-VISTA', 'delivered');

        self::assertNull($res->fatal, (string) $res->fatal);
        self::assertSame(200, $res->status);
    }

    private function semearConversaComEnvio(string $waMessageId): int
    {
        $pdo = $this->db->pdo();
        $agora = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO whatsapp_conversations (phone, status, created_at, updated_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['5521999887766', 'open', $agora, $agora]);
        $conversaId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO whatsapp_messages
                (conversation_id, wa_message_id, direction, type, status, body, message_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$conversaId, $waMessageId, 'outgoing', 'text', 'accepted', 'OS enviada', $agora, $agora]);

        return $conversaId;
    }

    private function postarStatus(string $waMessageId, string $status, ?string $erro = null): EndpointResponse
    {
        $evento = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '123',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'statuses' => [array_filter([
                            'id' => $waMessageId,
                            'status' => $status,
                            'recipient_id' => '5521999887766',
                            'errors' => $erro === null ? null : [['title' => $erro]],
                        ], static fn ($v) => $v !== null)],
                    ],
                ]],
            ]],
        ];

        return $this->postar($evento);
    }
}
