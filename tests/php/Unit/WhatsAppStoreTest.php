<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/whatsapp_store.php';

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
}
