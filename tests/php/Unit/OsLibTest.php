<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/os/os_lib.php';

/**
 * O documento da Ordem de Serviço montado no servidor: HTML da página pública e
 * do e-mail, texto do e-mail e mensagem de WhatsApp do robô.
 *
 * As linhas esperadas são as MESMAS de tests/lib/os-share.test.ts, que confere
 * o espelho do navegador (pré-visualização e WhatsApp pessoal). Os dois lados
 * entregam o mesmo documento ao cliente: rótulo, ordem e marcador de campo vazio
 * idênticos. Mexeu aqui, mexa lá.
 */
final class OsLibTest extends TestCase
{
    private const LINHAS_DA_OS = [
        'Cliente: Heineken',
        'Endereço da coleta: Av. das Américas, 500',
        'Data da coleta: 03/09/2026',
        'Horário aproximado: 14:30',
        'Material coletado: Óleo vegetal usado',
        'Pesagem: 150 kg',
        'Responsável pela coleta: Equipe A',
        'Qtd. sacos: 12',
        'Qtd. contêineres: 2',
    ];

    private const LINHAS_DA_OS_VAZIA = [
        'Cliente: Heineken',
        'Endereço da coleta: -',
        'Data da coleta: -',
        'Horário aproximado: -',
        'Material coletado: -',
        'Pesagem: -',
        'Responsável pela coleta: -',
        'Qtd. sacos: -',
        'Qtd. contêineres: -',
    ];

    private const LINK = 'https://ecolevaeco.com/api/os/view.php?id=42&t=abc';

    /** @return array<string,mixed> */
    private function os(): array
    {
        return [
            'id' => 42,
            'client_name' => 'Heineken',
            'collection_address' => 'Av. das Américas, 500',
            'weight' => '150 kg',
            'collection_date' => '2026-09-03',
            'approximate_time' => '14:30',
            'material_collected' => 'Óleo vegetal usado',
            'bags_count' => 12,
            'containers_count' => 2,
            'responsible' => 'Equipe A',
            'signature_text' => 'Responsável Técnica - ECOLEVA',
        ];
    }

    /** @return array<string,mixed> */
    private function osVazia(): array
    {
        return [
            'id' => 42,
            'client_name' => 'Heineken',
            'collection_address' => null,
            'weight' => '',
            'collection_date' => null,
            'approximate_time' => '   ',
            'material_collected' => null,
            'bags_count' => null,
            'containers_count' => null,
            'responsible' => null,
        ];
    }

    /**
     * Os pares rótulo/valor do documento HTML, na ordem em que aparecem.
     *
     * @return list<string>
     */
    private function linhasDoHtml(string $html): array
    {
        preg_match_all(
            '#<tr><td style="[^"]*width:190px[^"]*">([^<]*)</td><td style="[^"]*">([^<]*)</td></tr>#u',
            $html,
            $achados,
            PREG_SET_ORDER
        );

        return array_map(
            static fn (array $par): string => html_entity_decode($par[1] . ': ' . $par[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $achados
        );
    }

    // ── Mensagem de WhatsApp ─────────────────────────────────────────────────

    public function testMensagemDeWhatsAppSegueAOrdemDoDocumento(): void
    {
        $linhas = explode("\n", osWhatsAppText($this->os(), self::LINK));
        $titulo = array_shift($linhas);

        self::assertStringContainsString('*Ordem de Serviço Nº 00042*', $titulo);
        self::assertSame(
            [
                '',
                ...self::LINHAS_DA_OS,
                '',
                'Abrir e imprimir: ' . self::LINK,
                '',
                'Caso precise, envie WhatsApp para (21) 99152-9383.',
            ],
            $linhas
        );
    }

    public function testMensagemDeWhatsAppPoeOResponsavelAntesDasQuantidades(): void
    {
        $texto = osWhatsAppText($this->os(), self::LINK);

        self::assertLessThan(strpos($texto, 'Qtd. sacos'), strpos($texto, 'Responsável pela coleta'));
        self::assertLessThan(strpos($texto, 'Qtd. contêineres'), strpos($texto, 'Qtd. sacos'));
    }

    public function testMensagemDeWhatsAppMarcaCampoVazioComHifen(): void
    {
        $linhas = explode("\n", osWhatsAppText($this->osVazia(), ''));
        array_shift($linhas);

        self::assertSame(self::LINHAS_DA_OS_VAZIA, array_slice($linhas, 1, count(self::LINHAS_DA_OS_VAZIA)));
        // O único travessão da mensagem é o do título; nenhum campo o usa.
        self::assertStringNotContainsString("\u{2014}", implode("\n", $linhas));
    }

    public function testMensagemDeWhatsAppSemLinkNaoTemALinhaDoLink(): void
    {
        self::assertStringNotContainsString('Abrir e imprimir', osWhatsAppText($this->os(), ''));
    }

    // ── Texto do e-mail ──────────────────────────────────────────────────────

    public function testTextoDoEmailSegueAMesmaOrdemEOMesmoMarcador(): void
    {
        $linhas = explode("\n", osEmailText($this->os(), self::LINK));

        self::assertSame('ORDEM DE SERVIÇO Nº 00042', $linhas[0]);
        self::assertSame(self::LINHAS_DA_OS, array_slice($linhas, 2, count(self::LINHAS_DA_OS)));

        $vazio = explode("\n", osEmailText($this->osVazia(), self::LINK));
        self::assertSame(self::LINHAS_DA_OS_VAZIA, array_slice($vazio, 2, count(self::LINHAS_DA_OS_VAZIA)));
    }

    // ── Documento HTML (página pública e e-mail) ─────────────────────────────

    public function testDocumentoHtmlSegueRotulosEOrdemDasMensagens(): void
    {
        $html = osDocumentHtml($this->os(), 'https://ecolevaeco.com/');

        self::assertSame(self::LINHAS_DA_OS, $this->linhasDoHtml($html));
    }

    public function testDocumentoHtmlMarcaCampoVazioComHifenEDataVazia(): void
    {
        $html = osDocumentHtml($this->osVazia(), 'https://ecolevaeco.com/');

        self::assertSame(self::LINHAS_DA_OS_VAZIA, $this->linhasDoHtml($html));
        self::assertStringNotContainsString("\u{2014}</td>", $html, 'travessão não é marcador de campo vazio');
    }

    public function testDocumentoHtmlPreservaQuantidadeZero(): void
    {
        $os = $this->os();
        $os['bags_count'] = 0;

        self::assertContains('Qtd. sacos: 0', $this->linhasDoHtml(osDocumentHtml($os, 'https://ecolevaeco.com/')));
    }

    public function testDocumentoHtmlEscapaOConteudoDosCampos(): void
    {
        $os = $this->os();
        $os['responsible'] = '<script>alert(1)</script>';

        $html = osDocumentHtml($os, 'https://ecolevaeco.com/');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRodapeDoDocumentoUsaATelefoneDeSuporteDaConstante(): void
    {
        self::assertSame('(21) 99152-9383', OS_SUPPORT_PHONE);

        $html = osDocumentHtml($this->os(), 'https://ecolevaeco.com/');

        self::assertStringContainsString(
            'Caso precise de suporte ou esclarecimentos, envie mensagem para nosso WhatsApp: <strong>' . OS_SUPPORT_PHONE . '</strong>',
            $html
        );
    }
}
