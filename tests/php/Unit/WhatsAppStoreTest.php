<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/whatsapp_store.php';

/**
 * PDO que reproduz a corrida do INSERT da conversa: no instante em que o código
 * prepara o INSERT (depois de já ter olhado e não achado a linha), outra
 * requisição grava o mesmo telefone. É exatamente o que duas mensagens
 * simultâneas de um número novo fazem em produção.
 */
final class WhatsAppStoreCorridaPdo extends PDO
{
    public bool $corrida = true;

    public string $telefoneDoVizinho = '';

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->corrida && str_starts_with(ltrim($query), 'INSERT INTO whatsapp_conversations')) {
            $this->corrida = false;
            $agora = gmdate('Y-m-d H:i:s');
            parent::exec(
                "INSERT INTO whatsapp_conversations (phone, created_at, updated_at)
                 VALUES ('{$this->telefoneDoVizinho}', '{$agora}', '{$agora}')"
            );
        }

        return parent::prepare($query, $options);
    }
}

/**
 * A aritmética da janela de 24 horas.
 *
 * É a conta que decide se o botão do robô fica verde e se o envio sai como
 * texto livre (gratuito) ou como template (cobrado). Errar por três horas —
 * o tamanho do fuso de Brasília — significa cobrar quando não precisava, ou
 * tentar um texto livre que a Meta vai recusar.
 */
final class WhatsAppStoreTest extends TestCase
{
    private ?TestDatabase $banco = null;

    protected function tearDown(): void
    {
        $this->banco?->destroy();
        $this->banco = null;
    }

    /** Banco descartável, criado só nos testes que gravam conversa. */
    private function banco(): TestDatabase
    {
        return $this->banco ??= new TestDatabase();
    }

    /** @return array<string,mixed> a única conversa do banco */
    private function conversa(): array
    {
        $linhas = $this->banco()->rows('whatsapp_conversations');
        self::assertCount(1, $linhas);

        return $linhas[0];
    }

    private function receber(int $conversaId, string $wamid, string $texto, string $quando): void
    {
        waRecordMessage($this->banco()->pdo(), $conversaId, [
            'wa_message_id' => $wamid,
            'direction' => 'incoming',
            'type' => 'text',
            'body' => $texto,
            'message_at' => $quando,
        ]);
    }

    public function testJanelaAbertaEnquantoOInstanteNaoChegou(): void
    {
        self::assertTrue(waWindowIsOpen('2026-09-04 10:00:00', '2026-09-03 14:00:00'));
    }

    public function testJanelaFechadaDepoisDoInstante(): void
    {
        self::assertFalse(waWindowIsOpen('2026-09-03 10:00:00', '2026-09-03 14:00:00'));
    }

    public function testJanelaFechadaExatamenteNoLimite(): void
    {
        // Empate conta como fechada: a Meta não entrega no segundo do vencimento.
        self::assertFalse(waWindowIsOpen('2026-09-03 14:00:00', '2026-09-03 14:00:00'));
    }

    public function testSemJanelaNuncaEstaAberta(): void
    {
        self::assertFalse(waWindowIsOpen(null, '2026-09-03 14:00:00'));
        self::assertFalse(waWindowIsOpen('', '2026-09-03 14:00:00'));
        self::assertFalse(waWindowIsOpen('0000-00-00 00:00:00', '2026-09-03 14:00:00'));
    }

    public function testMinutosRestantes(): void
    {
        self::assertSame(90, waWindowMinutesLeft('2026-09-03 15:30:00', '2026-09-03 14:00:00'));
        self::assertSame(0, waWindowMinutesLeft('2026-09-03 13:00:00', '2026-09-03 14:00:00'));
        self::assertNull(waWindowMinutesLeft(null, '2026-09-03 14:00:00'));
    }

    /**
     * A comparação é textual e as duas pontas são UTC. Este caso trava o
     * contrato: comparar contra um horário local de Brasília (UTC-3) daria
     * "aberta" três horas depois de a janela ter fechado de verdade.
     */
    public function testComparacaoUsaOMesmoFusoDosDoisLados(): void
    {
        $expira = '2026-09-03 03:00:00';

        self::assertTrue(waWindowIsOpen($expira, '2026-09-03 02:59:59'));
        self::assertFalse(waWindowIsOpen($expira, '2026-09-03 03:00:01'));
    }

    public function testEstadoCompletoDaJanela(): void
    {
        $estado = waWindowState('2026-09-03 15:30:00', '2026-09-03 14:00:00');

        self::assertTrue($estado['open']);
        self::assertSame('2026-09-03T15:30:00Z', $estado['expires_at']);
        self::assertSame(90, $estado['minutes_left']);
    }

    public function testEstadoSemJanela(): void
    {
        $estado = waWindowState(null, '2026-09-03 14:00:00');

        self::assertFalse($estado['open']);
        self::assertNull($estado['expires_at']);
        self::assertNull($estado['minutes_left']);
    }

    public function testTimestampDaMetaViraUtc(): void
    {
        // 1772551320 = 2026-03-03T15:22:00Z
        self::assertSame('2026-03-03 15:22:00', waTimestampToUtc(1772551320));
        self::assertSame('2026-03-03 15:22:00', waTimestampToUtc('1772551320'));
        self::assertNull(waTimestampToUtc('agora'));
        self::assertNull(waTimestampToUtc(0));
        self::assertNull(waTimestampToUtc(null));
    }

    public function testIsoParaONavegador(): void
    {
        self::assertSame('2026-09-03T14:22:00Z', waToIso('2026-09-03 14:22:00'));
        self::assertNull(waToIso(null));
        self::assertNull(waToIso(''));
        self::assertNull(waToIso('0000-00-00 00:00:00'));
    }

    public function testPreviewColapsaEspacoEQuebra(): void
    {
        self::assertSame('Bom dia, tudo bem?', waPreview("Bom dia,\n  tudo   bem?", 'text'));
    }

    public function testPreviewUsaOTipoQuandoNaoHaTexto(): void
    {
        self::assertSame('[image]', waPreview(null, 'image'));
        self::assertSame('', waPreview('', null));
    }

    public function testPreviewNaoEstouraOTamanhoDaColuna(): void
    {
        self::assertSame(180, mb_strlen(waPreview(str_repeat('a', 400), 'text')));
    }

    /** A duração é a da plataforma; mudar aqui muda o custo de cada envio. */
    public function testJanelaDaMetaEhDeVinteEQuatroHoras(): void
    {
        self::assertSame(86400, WA_SERVICE_WINDOW_SECONDS);
    }

    public function testPhoneVariantsParaCelularBrasileiro(): void
    {
        // 13 dígitos (com 9) gera variante de 12 dígitos (sem 9)
        self::assertSame(['5521999887766', '552199887766'], waPhoneVariants('5521999887766'));

        // 12 dígitos (sem 9) gera variante de 13 dígitos (com 9)
        self::assertSame(['552199887766', '5521999887766'], waPhoneVariants('552199887766'));

        // Número fixo (12 dígitos com início 2, 3, 4, 5) não gera variante com 9
        self::assertSame(['552133445566'], waPhoneVariants('552133445566'));

        // Entrada vazia
        self::assertSame([], waPhoneVariants(''));
    }

    // ── mensagem atrasada ou reentregue não encurta a janela ────────────

    /**
     * A Meta reentrega com atraso (ou fora de ordem) um evento que não vimos. A
     * janela é 24h depois da ÚLTIMA mensagem do cliente: uma mensagem antiga
     * chegando depois não pode puxá-la para trás nem trocar a prévia da lista.
     */
    public function testMensagemRecebidaAtrasadaNaoEncurtaAJanelaNemTrocaAPrevia(): void
    {
        $pdo = $this->banco()->pdo();
        $id = (int) waEnsureConversation($pdo, '5521999887766');

        $this->receber($id, 'wamid.DEZ', 'das dez', '2026-03-03 10:00:00');
        $this->receber($id, 'wamid.NOVE', 'das nove', '2026-03-03 09:00:00');

        $c = $this->conversa();
        self::assertSame('2026-03-04 10:00:00', $c['service_window_expires_at']);
        self::assertSame('2026-03-03 10:00:00', $c['last_inbound_at']);
        self::assertSame('2026-03-03 10:00:00', $c['last_message_at']);
        self::assertSame('das dez', $c['last_message_preview']);
        self::assertSame('incoming', $c['last_message_direction']);
        // A mensagem atrasada continua sendo uma mensagem nova não lida.
        self::assertSame(2, (int) $c['unread_count']);
        self::assertSame(2, $this->banco()->count('whatsapp_messages'));
    }

    public function testMensagemNaOrdemNormalAindaAvancaAJanela(): void
    {
        $pdo = $this->banco()->pdo();
        $id = (int) waEnsureConversation($pdo, '5521999887766');

        $this->receber($id, 'wamid.A', 'primeira', '2026-03-03 09:00:00');
        $this->receber($id, 'wamid.B', 'segunda', '2026-03-03 10:00:00');

        $c = $this->conversa();
        self::assertSame('2026-03-04 10:00:00', $c['service_window_expires_at']);
        self::assertSame('segunda', $c['last_message_preview']);
    }

    /** Empate de horário avança: duas mensagens no mesmo segundo, a última gravada manda na prévia. */
    public function testMensagemNoMesmoInstanteAvancaAPrevia(): void
    {
        $pdo = $this->banco()->pdo();
        $id = (int) waEnsureConversation($pdo, '5521999887766');

        $this->receber($id, 'wamid.A', 'primeira', '2026-03-03 10:00:00');
        $this->receber($id, 'wamid.B', 'segunda', '2026-03-03 10:00:00');

        self::assertSame('segunda', $this->conversa()['last_message_preview']);
    }

    /**
     * O que vale para a janela é a última mensagem do CLIENTE, e para a lista é
     * a última de qualquer lado. Uma mensagem do cliente que chega atrasada, mas
     * depois do último inbound que já tínhamos, avança a janela sem roubar a
     * prévia de uma resposta nossa mais nova.
     */
    public function testInboundAtrasadoMaisNovoQueOUltimoInboundAvancaSoAJanela(): void
    {
        $pdo = $this->banco()->pdo();
        $id = (int) waEnsureConversation($pdo, '5521999887766');

        $this->receber($id, 'wamid.A', 'das nove', '2026-03-03 09:00:00');
        waRecordMessage($pdo, $id, [
            'wa_message_id' => 'wamid.RESP',
            'direction' => 'outgoing',
            'type' => 'text',
            'body' => 'resposta das onze',
            'message_at' => '2026-03-03 11:00:00',
        ]);
        $this->receber($id, 'wamid.B', 'das dez e meia', '2026-03-03 10:30:00');

        $c = $this->conversa();
        self::assertSame('2026-03-04 10:30:00', $c['service_window_expires_at']);
        self::assertSame('2026-03-03 10:30:00', $c['last_inbound_at']);
        self::assertSame('2026-03-03 11:00:00', $c['last_message_at']);
        self::assertSame('resposta das onze', $c['last_message_preview']);
        self::assertSame('outgoing', $c['last_message_direction']);
    }

    /** Mensagem nossa com data antiga (backfill de OS) não rebaixa o cabeçalho da conversa. */
    public function testMensagemEnviadaAntigaNaoRebaixaOCabecalho(): void
    {
        $pdo = $this->banco()->pdo();
        $id = (int) waEnsureConversation($pdo, '5521999887766');

        $this->receber($id, 'wamid.A', 'recente', '2026-03-03 10:00:00');
        waRecordMessage($pdo, $id, [
            'direction' => 'outgoing',
            'type' => 'template',
            'body' => 'OS antiga',
            'message_at' => '2026-03-01 08:00:00',
        ]);

        $c = $this->conversa();
        self::assertSame('recente', $c['last_message_preview']);
        self::assertSame('2026-03-03 10:00:00', $c['last_message_at']);
        self::assertSame('incoming', $c['last_message_direction']);
        self::assertSame('2026-03-04 10:00:00', $c['service_window_expires_at']);
    }

    // ── o dono da conversa não troca sozinho ────────────────────────────

    public function testConversaMantemOClienteQueJaTinha(): void
    {
        $pdo = $this->banco()->pdo();
        $id = (int) waEnsureConversation($pdo, '5521999887766', ['client_id' => 1]);

        waUpdateConversationIdentity($pdo, $id, ['client_id' => 2]);
        waEnsureConversation($pdo, '5521999887766', ['client_id' => 3]);

        self::assertSame(1, (int) $this->conversa()['client_id']);
    }

    public function testConversaSemClienteRecebeOClienteQueAparecerDepois(): void
    {
        $pdo = $this->banco()->pdo();
        $id = (int) waEnsureConversation($pdo, '5521999887766');
        self::assertNull($this->conversa()['client_id']);

        waUpdateConversationIdentity($pdo, $id, ['client_id' => 2]);

        self::assertSame(2, (int) $this->conversa()['client_id']);
    }

    // ── corrida no INSERT da conversa ──────────────────────────────────

    /**
     * Duas mensagens simultâneas de um número novo: as duas olham, nenhuma acha,
     * as duas inserem. O telefone é UNIQUE, então a segunda INSERT falha, e a
     * mensagem dela não pode se perder: a conversa que a outra criou serve.
     */
    public function testCorridaNoInsertDaConversaReaproveitaAQueOVizinhoCriou(): void
    {
        $pdo = new WhatsAppStoreCorridaPdo($this->banco()->dsn(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->telefoneDoVizinho = '5521988887777';

        $id = waEnsureConversation($pdo, '5521988887777', ['profile_name' => 'Maria']);

        self::assertNotNull($id);
        self::assertSame(1, $this->banco()->count('whatsapp_conversations'));
        self::assertSame($id, (int) $this->conversa()['id']);
        // O que a segunda requisição sabia (o nome) ainda é aproveitado.
        self::assertSame('Maria', $this->conversa()['profile_name']);
    }

    // ── fusão de conversas duplicadas ───────────────────────────────────

    public function testFusaoMantemEncerradaQuandoAsDuasEstavamEncerradas(): void
    {
        $pdo = $this->banco()->pdo();
        $agora = gmdate('Y-m-d H:i:s');
        $inserir = $pdo->prepare(
            "INSERT INTO whatsapp_conversations (phone, status, unread_count, created_at, updated_at)
             VALUES (?, 'closed', 0, ?, ?)"
        );
        $inserir->execute(['5521999887766', $agora, $agora]);
        $manter = (int) $pdo->lastInsertId();
        $inserir->execute(['552199887766', $agora, $agora]);
        $descartar = (int) $pdo->lastInsertId();

        waRecordMessage($pdo, $descartar, [
            'wa_message_id' => 'wamid.X',
            'direction' => 'outgoing',
            'type' => 'text',
            'body' => 'nossa resposta',
            'message_at' => '2026-03-03 10:00:00',
        ]);

        waMergeConversations($pdo, $manter, $descartar);

        $c = $this->conversa();
        self::assertSame($manter, (int) $c['id']);
        self::assertSame('closed', $c['status']);
        // As mensagens da descartada vieram junto, e a prévia é a da mais recente.
        self::assertSame(1, $this->banco()->count('whatsapp_messages'));
        self::assertSame($manter, (int) $this->banco()->rows('whatsapp_messages')[0]['conversation_id']);
        self::assertSame('nossa resposta', $c['last_message_preview']);
        self::assertSame('outgoing', $c['last_message_direction']);
    }

    /** Fundir um par que outra requisição já fundiu não pode quebrar nem apagar a que sobrou. */
    public function testFusaoDeParJaFundidoNaoFazNada(): void
    {
        $pdo = $this->banco()->pdo();
        $id = (int) waEnsureConversation($pdo, '5521999887766');

        waMergeConversations($pdo, $id, 9999);

        self::assertSame($id, (int) $this->conversa()['id']);
    }
}
