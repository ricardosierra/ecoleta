<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/os/os_lib.php';

/**
 * A trava de reenvio do WhatsApp do robô e o que ela protege.
 *
 * `os/whatsapp.php` lia `whatsapp_sent_at`, mandava para a Meta por uma chamada
 * de até 20 segundos e só então gravava. Nessa janela, duas abas (ou dois
 * operadores) liam "ainda não enviada" e mandavam a mesma OS duas vezes ao
 * cliente. A reserva abaixo é um único UPDATE condicional feito ANTES de enviar:
 * só uma das chamadas consegue marcá-la, e o banco decide qual.
 *
 * Roda no SQLite descartável da suíte; o SQL é o mesmo do MySQL em produção
 * (UPDATE com WHERE e `rowCount()`).
 */
final class OsWhatsAppSendTest extends TestCase
{
    private TestDatabase $db;

    private int $clientId;

    private string|false $logAnterior;

    private string $logFile;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();
        $this->clientId = $this->db->seedClient('Heineken', 500.0, 10, 'active', '5521999887766');

        // error_log para um arquivo da suíte, para a saída ficar limpa.
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'ecoleta_os_log_');
        $this->logAnterior = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        if ($this->logAnterior !== false) {
            ini_set('error_log', $this->logAnterior);
        }
        @unlink($this->logFile);
        $this->db->destroy();
    }

    /** @return array{whatsapp_sent_at:?string,whatsapp_sent_to:?string} */
    private function envio(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT whatsapp_sent_at, whatsapp_sent_to FROM service_orders WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch();
    }

    /** Uma segunda conexão ao mesmo banco: o outro operador, a outra aba. */
    private function outraConexao(): PDO
    {
        return new PDO($this->db->dsn(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    // ── Reserva ──────────────────────────────────────────────────────────────

    public function testReservaMarcaAOsAntesDeEnviar(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('a', 64));

        $reserva = osWhatsAppReserve($this->db->pdo(), $id, null, null, '5521999887766');

        self::assertNotNull($reserva);
        self::assertSame('5521999887766', $this->envio($id)['whatsapp_sent_to']);
        self::assertSame($reserva['at'], $this->envio($id)['whatsapp_sent_at']);
        self::assertNull($reserva['previous_at']);
    }

    /**
     * Duas abas leram "ainda não enviada" ao mesmo tempo. A primeira reserva; a
     * segunda recebe `null` e NÃO pode enviar.
     */
    public function testSoUmaDasDuasChamadasSimultaneasConsegueReservar(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('a', 64));
        $abaA = $this->db->pdo();
        $abaB = $this->outraConexao();

        $primeira = osWhatsAppReserve($abaA, $id, null, null, '5521999887766');
        $segunda = osWhatsAppReserve($abaB, $id, null, null, '5511888888888');

        self::assertNotNull($primeira);
        self::assertNull($segunda, 'a segunda aba não pode enviar a mesma OS de novo');
        self::assertSame('5521999887766', $this->envio($id)['whatsapp_sent_to'], 'a reserva da primeira ficou intacta');
    }

    public function testReenvioConfirmadoReservaSobreOEnvioAnterior(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('a', 64), '2026-09-03', '2026-09-03 14:22:00', '5521999887766');

        $reserva = osWhatsAppReserve($this->db->pdo(), $id, '2026-09-03 14:22:00', '5521999887766', '5521999887766');

        self::assertNotNull($reserva);
        self::assertSame('2026-09-03 14:22:00', $reserva['previous_at']);
        self::assertNotSame('2026-09-03 14:22:00', $this->envio($id)['whatsapp_sent_at']);
    }

    /** Dois operadores confirmam o reenvio da mesma OS: só um passa. */
    public function testReenvioConfirmadoPorDoisOperadoresSoPassaUmaVez(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('a', 64), '2026-09-03', '2026-09-03 14:22:00', '5521999887766');

        $primeira = osWhatsAppReserve($this->db->pdo(), $id, '2026-09-03 14:22:00', '5521999887766', '5521999887766');
        $segunda = osWhatsAppReserve($this->outraConexao(), $id, '2026-09-03 14:22:00', '5521999887766', '5521999887766');

        self::assertNotNull($primeira);
        self::assertNull($segunda);
    }

    public function testReservaNaoMexeEmOutraOs(): void
    {
        $a = $this->db->seedServiceOrder($this->clientId, str_repeat('a', 64));
        $b = $this->db->seedServiceOrder($this->clientId, str_repeat('b', 64));

        osWhatsAppReserve($this->db->pdo(), $a, null, null, '5521999887766');

        self::assertNull($this->envio($b)['whatsapp_sent_at']);
    }

    public function testReservaTrataEnvioAnteriorVazioComoNaoEnviada(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('a', 64));

        // A coluna pode vir como string vazia de uma linha antiga.
        self::assertNotNull(osWhatsAppReserve($this->db->pdo(), $id, '', null, '5521999887766'));
    }

    // ── Desfazer a reserva quando o envio falha ──────────────────────────────

    public function testFalhaNoEnvioDevolveAOsAoEstadoDeAntes(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('a', 64));
        $reserva = osWhatsAppReserve($this->db->pdo(), $id, null, null, '5521999887766');

        osWhatsAppRelease($this->db->pdo(), $id, $reserva);

        self::assertSame(['whatsapp_sent_at' => null, 'whatsapp_sent_to' => null], $this->envio($id));
    }

    public function testFalhaNoReenvioRestauraOEnvioAnterior(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('a', 64), '2026-09-03', '2026-09-03 14:22:00', '5521999887766');
        $reserva = osWhatsAppReserve($this->db->pdo(), $id, '2026-09-03 14:22:00', '5521999887766', '5511888888888');

        osWhatsAppRelease($this->db->pdo(), $id, $reserva);

        self::assertSame(
            ['whatsapp_sent_at' => '2026-09-03 14:22:00', 'whatsapp_sent_to' => '5521999887766'],
            $this->envio($id)
        );
    }

    public function testDesfazerNaoPisaNoQueOutroEnvioGravouDepois(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('a', 64));
        $reserva = osWhatsAppReserve($this->db->pdo(), $id, null, null, '5521999887766');

        // Outro operador confirmou o reenvio sobre a nossa reserva e já gravou a dele.
        $this->db->pdo()->prepare('UPDATE service_orders SET whatsapp_sent_at = ?, whatsapp_sent_to = ? WHERE id = ?')
            ->execute(['2099-01-01 00:00:00', '5511888888888', $id]);

        osWhatsAppRelease($this->db->pdo(), $id, $reserva);

        self::assertSame('5511888888888', $this->envio($id)['whatsapp_sent_to']);
    }

    public function testDesfazerNaoLancaQuandoOBancoFalhaEDeixaORegistro(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('a', 64));
        $reserva = osWhatsAppReserve($this->db->pdo(), $id, null, null, '5521999887766');
        $this->db->dropTable('service_orders');

        // Já estamos devolvendo o erro do envio: um segundo erro não pode escondê-lo.
        osWhatsAppRelease($this->db->pdo(), $id, $reserva);

        self::assertStringContainsString('Falha ao desfazer a reserva', (string) file_get_contents($this->logFile));
    }

    // ── Plano de envio: de graça, cobrado ou impossível ──────────────────────

    public function testDentroDaJanelaOEnvioEGratuito(): void
    {
        self::assertSame('gratis', osWhatsAppSendPlan(true, ''));
        self::assertSame('gratis', osWhatsAppSendPlan(true, 'os_enviada'));
    }

    public function testForaDaJanelaComTemplateOEnvioECobrado(): void
    {
        self::assertSame('cobrado', osWhatsAppSendPlan(false, 'os_enviada'));
    }

    public function testForaDaJanelaSemTemplateAMetaRecusa(): void
    {
        self::assertSame('recusado', osWhatsAppSendPlan(false, ''));
    }

    // ── Registro da mensagem depois que a Meta aceitou ───────────────────────

    public function testFalhaAoRegistrarAMensagemNaoDerrubaUmEnvioQueJaSaiu(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('a', 64));
        $conversaId = waEnsureConversation($this->db->pdo(), '5521999887766');
        $this->db->dropTable('whatsapp_messages');

        // A Meta já aceitou. Lançar aqui viraria 500, a tela diria que falhou e o
        // operador reenviaria uma OS que o cliente já recebeu.
        $registrada = osRecordWhatsAppSent($this->db->pdo(), $conversaId, [
            'wa_message_id' => 'wamid.X',
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'accepted',
            'body' => 'texto',
            'service_order_id' => $id,
        ]);

        self::assertFalse($registrada);
        self::assertStringContainsString('wamid.X', (string) file_get_contents($this->logFile));
    }

    public function testRegistraAMensagemQuandoTudoVaiBem(): void
    {
        $id = $this->db->seedServiceOrder($this->clientId, str_repeat('a', 64));
        $conversaId = waEnsureConversation($this->db->pdo(), '5521999887766');

        $registrada = osRecordWhatsAppSent($this->db->pdo(), $conversaId, [
            'wa_message_id' => 'wamid.Y',
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'accepted',
            'body' => 'texto',
            'service_order_id' => $id,
        ]);

        self::assertTrue($registrada);
        self::assertSame(1, $this->db->count('whatsapp_messages'));
    }

    public function testSemConversaNaoHaOQueRegistrarEIssoNaoEErro(): void
    {
        self::assertTrue(osRecordWhatsAppSent($this->db->pdo(), null, ['direction' => 'outgoing']));
        self::assertSame(0, $this->db->count('whatsapp_messages'));
    }
}
