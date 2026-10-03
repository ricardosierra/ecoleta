<?php
declare(strict_types=1);

/**
 * Peças compartilhadas do encaminhamento de Ordem de Serviço.
 *
 * Três consumidores usam exatamente o mesmo documento:
 *   - `os/view.php`  — a página pública que o cliente abre pelo link;
 *   - `os/send.php`  — o corpo HTML do e-mail;
 *   - o dashboard    — a pré-visualização em `app/dashboard/os/page.tsx`.
 *
 * Os dois primeiros vivem aqui para não divergirem. O terceiro é React e não
 * pode compartilhar código com o PHP, mas lê os mesmos campos e desenha o mesmo
 * cabeçalho, os mesmos rótulos e a mesma assinatura — quem mexer em um lado
 * precisa olhar o outro.
 */

require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../whatsapp_store.php';

/** Assinatura digitalizada da responsável técnica, publicada em public/. */
const OS_SIGNATURE_IMAGE = 'assinatura-responsavel.png';

/** Logo usada no cabeçalho do documento (a mesma de components/Logo.tsx). */
const OS_LOGO_IMAGE = 'ecoleva-logo-dark.png';

/** Remetente do e-mail. Igual ao de public/contact.php, salvo override no env. */
const OS_MAIL_FROM_DEFAULT = 'noreply@ecoleva.com';

/**
 * WhatsApp de suporte que o documento e a mensagem oferecem ao cliente. Espelho
 * de `OS_SUPPORT_PHONE` em `lib/os-share.ts`: mexeu em um, mexa no outro.
 */
const OS_SUPPORT_PHONE = '(21) 99152-9383';

/**
 * Marcador de campo vazio em TODO texto do documento (HTML, e-mail e WhatsApp).
 * É um hífen, e o mesmo do navegador (`OS_EMPTY_FIELD` em `lib/os-share.ts`):
 * o texto do robô misturava hífen e travessão conforme o campo, e a
 * pré-visualização desenhava outro marcador do que o cliente recebia.
 */
const OS_EMPTY_FIELD = '-';

/**
 * Rótulo de cada campo da OS, na ordem do documento (o cliente vem antes).
 *
 * É a ordem e a redação que o cliente lê na página pública, no e-mail e no
 * WhatsApp, e que `osDocumentFields()` em `lib/os-share.ts` repete na
 * pré-visualização e no WhatsApp pessoal. As chaves são as colunas de
 * `service_orders`, para a validação de `os/index.php` falar do campo pelo mesmo
 * nome que o cliente vê.
 */
const OS_FIELD_LABELS = [
    'collection_address' => 'Endereço da coleta',
    'collection_date' => 'Data da coleta',
    'approximate_time' => 'Horário aproximado',
    'material_collected' => 'Material coletado',
    'weight' => 'Pesagem',
    'responsible' => 'Responsável pela coleta',
    'bags_count' => 'Qtd. sacos',
    'containers_count' => 'Qtd. contêineres',
];

/**
 * Segredo do link público de uma OS.
 *
 * 32 bytes de `random_bytes` — não é derivado do id, então conhecer uma OS não
 * ajuda a adivinhar a próxima, e revogar o link é trocar esta coluna.
 */
function osShareTokenNew(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * URL absoluta da raiz do site, com barra no fim.
 *
 * `SITE_BASE_URL` no env vence sempre. Sem ela, a URL é montada a partir do
 * host da requisição — que o cliente controla pelo cabeçalho Host. Para a
 * página em si isso é inofensivo (quem abriu já está no host que digitou), mas
 * o link vai também dentro de um e-mail que NÓS enviamos: um Host forjado
 * mandaria o destinatário para o servidor de outra pessoa. Daí a validação
 * estrita abaixo e a recomendação de fixar SITE_BASE_URL em produção.
 */
function osBaseUrl(): string
{
    $configured = apiSecret('SITE_BASE_URL');
    if ($configured !== '') {
        return rtrim($configured, '/') . '/';
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || preg_match('/^[A-Za-z0-9.\-]+(:[0-9]{1,5})?$/', $host) !== 1) {
        $host = 'localhost';
    }

    // apiBasePath() devolve a pasta /api/ do deploy ('/api/' ou '/sub/api/').
    // A raiz do site é o que vem antes dela.
    $apiPath = apiBasePath();
    $root = $apiPath === '/' ? '/' : substr($apiPath, 0, -4);
    if ($root === '' || $root === false) {
        $root = '/';
    }

    return (apiIsHttps() ? 'https' : 'http') . '://' . $host . $root;
}

/** Link público de uma OS — id e token juntos; um sem o outro não abre nada. */
function osShareUrl(int $id, string $token, ?string $baseUrl = null): string
{
    $baseUrl ??= osBaseUrl();

    return $baseUrl . 'api/os/view.php?id=' . $id . '&t=' . rawurlencode($token);
}

/**
 * Uma OS com os dados do cliente. `null` quando o id não existe.
 *
 * @return array<string,mixed>|null
 */
function osFindById(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('
        SELECT o.*, c.name AS client_name, c.email AS client_email, c.whatsapp AS client_whatsapp
          FROM service_orders o
          JOIN clients c ON c.id = o.client_id
         WHERE o.id = ?
         LIMIT 1
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

/**
 * A OS de um link público, se o token conferir.
 *
 * A comparação é `hash_equals` sobre o token guardado, e não um `WHERE
 * share_token = ?`: assim o tempo de resposta não varia com quantos caracteres
 * do token o visitante acertou. Linha sem token (nunca deveria acontecer depois
 * da migration 014) nunca abre.
 *
 * @return array<string,mixed>|null
 */
function osFindShared(PDO $db, int $id, string $token): ?array
{
    if ($id <= 0 || $token === '') {
        return null;
    }

    $os = osFindById($db, $id);
    $stored = is_array($os) ? (string) ($os['share_token'] ?? '') : '';

    if ($os === null || $stored === '' || !hash_equals($stored, $token)) {
        return null;
    }

    return $os;
}

/**
 * Uma linha de `service_orders` no formato que o dashboard consome.
 *
 * O `share_token` cru não vai junto: o que a tela precisa é o link pronto, e
 * devolver só a URL mantém um único lugar (aqui) montando o endereço.
 *
 * @param array<string,mixed> $row
 * @param array{open:bool,expires_at:?string,minutes_left:?int}|null $whatsappWindow
 * @param array{status:?string,error:?string}|null $whatsappMessage a última mensagem do robô para esta OS (ver `osLastWhatsAppMessages()`)
 * @return array<string,mixed>
 */
function osPresent(array $row, ?string $baseUrl = null, ?array $whatsappWindow = null, ?array $whatsappMessage = null): array
{
    $id = (int) $row['id'];
    $token = (string) ($row['share_token'] ?? '');

    return [
        'id' => $id,
        'client_id' => (int) $row['client_id'],
        'client_name' => (string) ($row['client_name'] ?? ''),
        'client_email' => $row['client_email'] ?? null,
        'client_whatsapp' => $row['client_whatsapp'] ?? null,
        'collection_address' => $row['collection_address'] ?? null,
        'weight' => $row['weight'] ?? null,
        'collection_date' => $row['collection_date'] ?? null,
        'approximate_time' => $row['approximate_time'] ?? null,
        'material_collected' => $row['material_collected'] ?? null,
        'bags_count' => $row['bags_count'] ?? null,
        'containers_count' => $row['containers_count'] ?? null,
        'responsible' => $row['responsible'] ?? null,
        'signature_text' => (string) ($row['signature_text'] ?? 'Responsável Técnica - ECOLEVA'),
        'sent_at' => $row['sent_at'] ?? null,
        'sent_to' => $row['sent_to'] ?? null,
        'whatsapp_sent_at' => $row['whatsapp_sent_at'] ?? null,
        'whatsapp_sent_to' => $row['whatsapp_sent_to'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'share_url' => $token === '' ? null : osShareUrl($id, $token, $baseUrl),
        // Janela de 24h do WhatsApp deste cliente. É o que pinta o botão do robô
        // de verde na tela: dentro da janela o envio é texto livre, que a Meta
        // não cobra. `null` quando o cliente nunca escreveu para o número.
        'whatsapp_window' => $whatsappWindow,
        // O que aconteceu com a última mensagem do robô: accepted, sent, delivered,
        // read ou failed. `whatsapp_sent_at` só diz que a Meta ACEITOU o pedido;
        // número fixo ou sem WhatsApp falha depois, pelo webhook, e é aqui que a
        // tela fica sabendo. `null` quando o robô nunca enviou esta OS.
        'whatsapp_status' => $whatsappMessage['status'] ?? null,
        'whatsapp_error' => $whatsappMessage['error'] ?? null,
    ];
}

/**
 * A última mensagem de WhatsApp que o robô enviou para cada OS, em uma consulta
 * só: uma por linha da tabela faria uma consulta por OS.
 *
 * "Última" é a de maior id: o reenvio de uma OS gera uma mensagem nova, e é o
 * destino dela que importa. Só conta o que saiu (`outgoing`), já que mensagem
 * recebida não tem status de entrega.
 *
 * O status é o que o webhook (`api/webhooks/whatsapp.php`) foi atualizando em
 * `whatsapp_messages`, então a leitura aqui não depende de mexer nele.
 *
 * @return array<int,array{status:?string,error:?string}> por id da OS
 */
function osLastWhatsAppMessages(PDO $db): array
{
    $stmt = $db->query("
        SELECT m.service_order_id, m.status, m.error_message
          FROM whatsapp_messages m
          JOIN (
                SELECT service_order_id, MAX(id) AS last_id
                  FROM whatsapp_messages
                 WHERE service_order_id IS NOT NULL AND direction = 'outgoing'
                 GROUP BY service_order_id
               ) ultimas ON ultimas.last_id = m.id
    ");

    $porOs = [];
    foreach ($stmt ? $stmt->fetchAll() : [] as $linha) {
        $porOs[(int) $linha['service_order_id']] = [
            'status' => $linha['status'] === null ? null : (string) $linha['status'],
            'error' => $linha['error_message'] === null ? null : (string) $linha['error_message'],
        ];
    }

    return $porOs;
}

function osEsc(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Número da OS no formato exibido em toda parte: #00042. */
function osNumber(int $id): string
{
    return str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

/**
 * Tamanho máximo, em CARACTERES, das colunas de texto de `service_orders`
 * (migrations 008 e 016). O VARCHAR do MySQL conta caracteres, não bytes: "ç"
 * ocupa dois bytes e um campo cheio deles continua cabendo.
 */
const OS_TEXT_MAX_LENGTH = [
    'collection_address' => 255,
    'approximate_time' => 50,
    'material_collected' => 255,
    'weight' => 50,
    'responsible' => 255,
];

/**
 * Maior quantidade de sacos ou contêineres aceita. A coluna é INT, mas uma OS de
 * coleta com mais de cem mil sacos é um erro de digitação, e é esse que a tela
 * precisa apontar antes de o documento sair assinado.
 */
const OS_MAX_QUANTITY = 99999;

/**
 * Reserva o envio da OS pelo WhatsApp do robô ANTES de chamar a Meta.
 *
 * `os/whatsapp.php` lia `whatsapp_sent_at`, enviava (chamada de até 20 segundos)
 * e só depois gravava. Nessa janela, duas abas ou dois operadores liam "ainda não
 * enviada" e mandavam a mesma OS duas vezes ao cliente. Aqui a marca é um único
 * UPDATE condicional, e quem decide qual das chamadas passa é o banco:
 *
 *  - OS ainda não enviada: `WHERE whatsapp_sent_at IS NULL`;
 *  - reenvio confirmado: `WHERE whatsapp_sent_at = <o valor que esta chamada leu>`.
 *    Dois operadores confirmando o mesmo reenvio leram o mesmo valor, e só o
 *    primeiro UPDATE o encontra.
 *
 * O horário vem do relógio do banco (como o `CURRENT_TIMESTAMP` de antes, e como
 * o `sent_at` do e-mail), lido primeiro para que `osWhatsAppRelease()` saiba qual
 * valor é o nosso. Funciona igual em MySQL e SQLite.
 *
 * O `rowCount()` do MySQL conta linhas que MUDARAM. Reenviar no mesmo segundo do
 * envio anterior, para o mesmo número, não muda nada e conta zero: a chamada é
 * tratada como perdida e a tela pede a confirmação de novo, que é o lado seguro.
 *
 * @return array{at:string,previous_at:?string,previous_to:?string}|null `null`
 *         quando outra chamada chegou primeiro: esta NÃO pode enviar
 */
function osWhatsAppReserve(PDO $db, int $id, ?string $previousAt, ?string $previousTo, string $destino): ?array
{
    $previousAt = $previousAt === null || trim($previousAt) === '' ? null : $previousAt;
    $agora = (string) $db->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();

    if ($previousAt === null) {
        $stmt = $db->prepare('UPDATE service_orders SET whatsapp_sent_at = ?, whatsapp_sent_to = ? WHERE id = ? AND whatsapp_sent_at IS NULL');
        $stmt->execute([$agora, $destino, $id]);
    } else {
        $stmt = $db->prepare('UPDATE service_orders SET whatsapp_sent_at = ?, whatsapp_sent_to = ? WHERE id = ? AND whatsapp_sent_at = ?');
        $stmt->execute([$agora, $destino, $id, $previousAt]);
    }

    if ($stmt->rowCount() !== 1) {
        return null;
    }

    return ['at' => $agora, 'previous_at' => $previousAt, 'previous_to' => $previousTo];
}

/**
 * Desfaz a reserva quando o envio falhou: a OS volta a constar como estava, e o
 * operador pode tentar de novo sem ver "já enviada".
 *
 * Só desfaz se a linha ainda tem A NOSSA marca (`whatsapp_sent_at = at`): se outro
 * operador gravou o dele nesse meio-tempo, não se pisa nisso. Nunca lança: quem
 * chama está devolvendo o erro do envio, e um segundo erro o esconderia. Se falhar,
 * a OS fica marcada como enviada (o lado seguro: pede confirmação em vez de
 * duplicar) e o log diz qual.
 *
 * @param array{at:string,previous_at:?string,previous_to:?string} $reserva
 */
function osWhatsAppRelease(PDO $db, int $id, array $reserva): void
{
    try {
        $stmt = $db->prepare('UPDATE service_orders SET whatsapp_sent_at = ?, whatsapp_sent_to = ? WHERE id = ? AND whatsapp_sent_at = ?');
        $stmt->execute([$reserva['previous_at'], $reserva['previous_to'], $id, $reserva['at']]);
    } catch (Throwable) {
        error_log(sprintf('Falha ao desfazer a reserva de WhatsApp da OS #%d; ela segue marcada como enviada.', $id));
    }
}

/**
 * Como o envio de uma OS sairia agora, pela janela de 24 horas e pelo template.
 *
 *  - `gratis`: janela aberta, texto livre, a Meta não cobra;
 *  - `cobrado`: janela fechada e template configurado, a Meta entrega e COBRA;
 *  - `recusado`: janela fechada sem template, a Meta recusa (`outsideWindow`).
 *
 * A tela pede confirmação antes do `cobrado`: o botão do robô não pode gastar
 * dinheiro no primeiro clique.
 */
function osWhatsAppSendPlan(bool $windowOpen, string $template): string
{
    if ($windowOpen) {
        return 'gratis';
    }

    return trim($template) !== '' ? 'cobrado' : 'recusado';
}

/**
 * Grava a mensagem que a Meta acabou de aceitar, sem nunca lançar.
 *
 * A Meta já aceitou: o cliente vai receber. Se gravar o histórico falhar e a
 * exceção subisse, a resposta seria 500, a tela diria que falhou e o operador
 * reenviaria uma OS que o cliente já tem. Falhar aqui só deixa a conversa sem o
 * registro; o `wamid` vai para o log para ser reconciliado.
 *
 * @param array<string,mixed> $mensagem os campos de `waRecordMessage()`
 * @return bool `false` quando a gravação falhou; `true` também quando não há conversa
 */
function osRecordWhatsAppSent(PDO $db, ?int $conversationId, array $mensagem): bool
{
    if ($conversationId === null) {
        return true;
    }

    try {
        waRecordMessage($db, $conversationId, $mensagem);

        return true;
    } catch (Throwable) {
        error_log(sprintf(
            'OS enviada pela Meta, mas a mensagem %s não foi gravada no histórico de conversas.',
            (string) ($mensagem['wa_message_id'] ?? '(sem wamid)')
        ));

        return false;
    }
}

/** Primeiro ano aceito na data da coleta. O último é o ano seguinte ao corrente. */
const OS_MIN_YEAR = 2000;

/**
 * Valida e normaliza o corpo da criação de OS (`os/index.php`).
 *
 * O cliente fica de fora: quem o checa é o endpoint, que também confere que ele
 * existe. A tela só marca o cliente como obrigatório, e o servidor cobra o mesmo,
 * nem mais nem menos; o resto é opcional, mas precisa caber na coluna.
 *
 * Toda mensagem NOMEIA o campo, pelo rótulo que o cliente lê no documento
 * (`OS_FIELD_LABELS`). Antes, "As quantidades devem ser números inteiros não
 * negativos." não dizia qual das duas estava errada.
 *
 * - texto: aceita texto e número (a tela manda texto, mas `150` em JSON é legítimo);
 *   lista, objeto e booleano são recusados, e vazio ou só espaços vira `null`;
 * - quantidade: inteiro de 0 a `OS_MAX_QUANTITY`; zeros à esquerda ("05") valem,
 *   porque é assim que muita gente digita; decimal, sinal e notação científica não;
 * - data: `YYYY-MM-DD` real, entre `OS_MIN_YEAR` e o ano seguinte. "0026-09-03" e
 *   "2099-09-03" eram datas válidas para o `DateTime` e absurdas para uma coleta.
 *
 * @param array<string,mixed> $body
 * @return array{0:array<string,mixed>,1:?string} os valores prontos para gravar e a mensagem de erro (`null` se tudo certo)
 */
function osValidateInput(array $body, ?DateTimeImmutable $now = null): array
{
    $valores = [];

    foreach (OS_TEXT_MAX_LENGTH as $campo => $maximo) {
        $rotulo = OS_FIELD_LABELS[$campo];
        $bruto = $body[$campo] ?? null;

        if ($bruto !== null && !is_string($bruto) && !is_int($bruto) && !is_float($bruto)) {
            return [[], $rotulo . ': valor inválido.'];
        }

        $texto = trim((string) ($bruto ?? ''));
        if (mb_strlen($texto, 'UTF-8') > $maximo) {
            return [[], sprintf('%s deve ter no máximo %d caracteres.', $rotulo, $maximo)];
        }

        $valores[$campo] = $texto === '' ? null : $texto;
    }

    foreach (['bags_count', 'containers_count'] as $campo) {
        $bruto = $body[$campo] ?? null;
        if (is_string($bruto)) {
            $bruto = trim($bruto);
        }

        if ($bruto === null || $bruto === '') {
            $valores[$campo] = null;
            continue;
        }

        $quantidade = null;
        if (is_int($bruto)) {
            $quantidade = $bruto;
        } elseif (is_float($bruto) && floor($bruto) === $bruto && abs($bruto) <= OS_MAX_QUANTITY) {
            $quantidade = (int) $bruto;
        } elseif (is_string($bruto) && preg_match('/^\d+$/D', $bruto) === 1) {
            // Sem os zeros à esquerda, o que passar de seis dígitos já estoura o teto
            // (e não vira inteiro gigante na conversão).
            $digitos = ltrim($bruto, '0');
            $quantidade = $digitos === '' ? 0 : (strlen($digitos) > 6 ? PHP_INT_MAX : (int) $digitos);
        }

        if ($quantidade === null || $quantidade < 0 || $quantidade > OS_MAX_QUANTITY) {
            return [[], sprintf('%s deve ser um número inteiro de 0 a %d.', OS_FIELD_LABELS[$campo], OS_MAX_QUANTITY)];
        }

        $valores[$campo] = $quantidade;
    }

    $rotuloData = OS_FIELD_LABELS['collection_date'];
    $brutoData = $body['collection_date'] ?? null;

    if ($brutoData !== null && !is_string($brutoData)) {
        return [[], $rotuloData . ' inválida.'];
    }

    $data = trim((string) ($brutoData ?? ''));
    if ($data === '') {
        $valores['collection_date'] = null;
    } else {
        $analisada = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if ($analisada === false || $analisada->format('Y-m-d') !== $data) {
            return [[], $rotuloData . ' inválida.'];
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        $anoMaximo = (int) $now->format('Y') + 1;
        $ano = (int) $analisada->format('Y');
        if ($ano < OS_MIN_YEAR || $ano > $anoMaximo) {
            return [[], sprintf('%s deve estar entre os anos de %d e %d.', $rotuloData, OS_MIN_YEAR, $anoMaximo)];
        }

        $valores['collection_date'] = $data;
    }

    return [$valores, null];
}

/** Data ISO do banco em dd/mm/aaaa. String vazia e nulo viram o marcador de vazio. */
function osFormatDate(?string $isoDate): string
{
    $isoDate = trim((string) $isoDate);
    if ($isoDate === '' || str_starts_with($isoDate, '0000')) {
        return OS_EMPTY_FIELD;
    }

    $date = date_create($isoDate);

    return $date === false ? OS_EMPTY_FIELD : $date->format('d/m/Y');
}

/**
 * Os campos do documento em texto puro (rótulo => valor), na ordem do documento
 * e com o marcador de vazio onde não há valor. O cliente não está aqui: cada
 * canal o trata a seu modo.
 *
 * É a única fonte de rótulo, ordem e marcador do HTML, do e-mail e do WhatsApp.
 * Com três listas escritas à mão elas divergiram: o robô mandava sacos antes do
 * responsável, o documento depois.
 *
 * @param array<string,mixed> $os
 * @return array<string,string>
 */
function osDocumentFields(array $os): array
{
    $campos = [];
    foreach (OS_FIELD_LABELS as $coluna => $rotulo) {
        $campos[$rotulo] = $coluna === 'collection_date'
            ? osFormatDate(isset($os['collection_date']) ? (string) $os['collection_date'] : null)
            : osPlainField($os[$coluna] ?? null);
    }

    return $campos;
}

/**
 * O documento da OS em HTML — o mesmo bloco na página pública e no e-mail.
 *
 * Estilo inline em tabelas, sem CSS externo e sem flexbox, porque o mesmo
 * markup precisa sobreviver ao Gmail e ao Outlook, que descartam `<style>` e
 * não implementam layout moderno.
 *
 * @param array<string,mixed> $os
 */
function osDocumentHtml(array $os, string $baseUrl): string
{
    $numero = osNumber((int) $os['id']);
    $assinatura = osEsc((string) ($os['signature_text'] ?? 'Responsável Técnica - ECOLEVA'));
    $logo = osEsc($baseUrl . OS_LOGO_IMAGE);
    $rubrica = osEsc($baseUrl . OS_SIGNATURE_IMAGE);
    $suporte = osEsc(OS_SUPPORT_PHONE);

    $linhas = '';
    $campos = ['Cliente' => osEsc((string) ($os['client_name'] ?? ''))];
    foreach (osDocumentFields($os) as $rotulo => $valor) {
        $campos[$rotulo] = osEsc($valor);
    }
    foreach ($campos as $rotulo => $valor) {
        $linhas .= '<tr>'
            . '<td style="padding:10px 0;color:#5A5A5A;font-size:13px;width:190px;vertical-align:top;">' . osEsc($rotulo) . '</td>'
            . '<td style="padding:10px 0;color:#242424;font-size:15px;font-weight:600;">' . $valor . '</td>'
            . '</tr>';
    }

    return <<<HTML
<div style="max-width:640px;margin:0 auto;background:#FFFFFF;color:#242424;font-family:'Montserrat',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;padding:32px;">
  <table role="presentation" style="width:100%;border-collapse:collapse;border-bottom:2px solid #ECF5FB;">
    <tr>
      <td style="padding-bottom:24px;vertical-align:middle;">
        <img src="{$logo}" alt="Ecoleva" width="150" style="display:block;width:150px;height:auto;border:0;">
      </td>
      <td style="padding-bottom:24px;text-align:right;vertical-align:middle;">
        <div style="font-size:20px;font-weight:700;color:#2D5934;text-transform:uppercase;letter-spacing:0.08em;">Ordem de Serviço</div>
        <div style="font-size:13px;color:#5A5A5A;margin-top:4px;">Nº {$numero}</div>
      </td>
    </tr>
  </table>

  <table role="presentation" style="width:100%;border-collapse:collapse;margin-top:24px;">
    {$linhas}
  </table>

  <table role="presentation" style="width:100%;border-collapse:collapse;margin-top:56px;">
    <tr>
      <td style="text-align:center;">
        <img src="{$rubrica}" alt="" width="220" style="display:block;width:220px;height:auto;margin:0 auto -14px;border:0;">
        <div style="width:280px;margin:0 auto;border-top:1px solid #242424;"></div>
        <div style="font-size:13px;font-weight:600;color:#242424;padding-top:8px;">{$assinatura}</div>
      </td>
    </tr>
  </table>

  <table role="presentation" style="width:100%;border-collapse:collapse;margin-top:40px;border-top:1px solid #ECF5FB;">
    <tr>
      <td style="padding-top:16px;text-align:center;font-size:12px;color:#5A5A5A;">
        Caso precise de suporte ou esclarecimentos, envie mensagem para nosso WhatsApp: <strong>{$suporte}</strong>
      </td>
    </tr>
  </table>
</div>
HTML;
}

/**
 * A página pública completa: o documento acima dentro de um HTML autônomo,
 * com botão de impressão que some no papel.
 *
 * @param array<string,mixed> $os
 */
function osViewPageHtml(array $os, string $baseUrl): string
{
    $numero = osNumber((int) $os['id']);
    $cliente = osEsc((string) ($os['client_name'] ?? ''));
    $documento = osDocumentHtml($os, $baseUrl);

    return <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Ordem de Serviço Nº {$numero} — {$cliente}</title>
<link rel="icon" href="{$baseUrl}ecoleta-icon.svg">
<style>
  body { margin:0; padding:24px 16px 64px; background:#ECF5FB; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; }
  .os-acoes { max-width:640px; margin:0 auto 16px; text-align:right; }
  .os-acoes button {
    font:inherit; font-weight:600; font-size:14px; cursor:pointer;
    background:#2D5934; color:#FFFFFF; border:0; border-radius:50px; padding:10px 22px;
  }
  .os-acoes button:hover { opacity:.9; }
  .os-folha { box-shadow:0 8px 32px rgba(13,31,15,.12); border-radius:10px; overflow:hidden; max-width:640px; margin:0 auto; }
  @media print {
    body { background:#FFFFFF; padding:0; }
    .os-acoes { display:none; }
    .os-folha { box-shadow:none; border-radius:0; }
  }
</style>
</head>
<body>
  <div class="os-acoes"><button type="button" onclick="window.print()">Imprimir / Salvar PDF</button></div>
  <div class="os-folha">{$documento}</div>
</body>
</html>
HTML;
}

/**
 * Corpo do e-mail: o documento mais uma chamada para o link público.
 *
 * @param array<string,mixed> $os
 */
function osEmailHtml(array $os, string $baseUrl, string $shareUrl): string
{
    $documento = osDocumentHtml($os, $baseUrl);
    $link = osEsc($shareUrl);

    return <<<HTML
<div style="background:#ECF5FB;padding:24px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
  {$documento}
  <div style="max-width:640px;margin:0 auto;padding:0 32px 32px;background:#FFFFFF;text-align:center;">
    <a href="{$link}" style="display:inline-block;background:#2D5934;color:#FFFFFF;text-decoration:none;font-weight:600;font-size:14px;border-radius:50px;padding:14px 32px;">Abrir e imprimir a OS</a>
  </div>
</div>
HTML;
}

/**
 * Versão em texto puro do e-mail — o que aparece em cliente que não renderiza
 * HTML, e o que evita que a mensagem seja pontuada como spam por ser só imagem.
 *
 * @param array<string,mixed> $os
 */
function osEmailText(array $os, string $shareUrl): string
{
    $linhas = [
        'ORDEM DE SERVIÇO Nº ' . osNumber((int) $os['id']),
        '',
        'Cliente: ' . trim((string) ($os['client_name'] ?? '')),
    ];
    foreach (osDocumentFields($os) as $rotulo => $valor) {
        $linhas[] = $rotulo . ': ' . $valor;
    }
    array_push(
        $linhas,
        '',
        'Abrir e imprimir: ' . $shareUrl,
        '',
        (string) ($os['signature_text'] ?? 'Responsável Técnica - ECOLEVA')
    );

    return implode("\n", $linhas);
}

/**
 * Texto da mensagem de WhatsApp.
 *
 * É o espelho de `osShareMessage()` em `lib/os-share.ts`, que monta a mesma
 * mensagem para o WhatsApp pessoal. Os dois caminhos entregam a mesma coisa ao
 * cliente; mexeu aqui, mexa lá.
 *
 * @param array<string,mixed> $os
 */
function osWhatsAppText(array $os, string $shareUrl): string
{
    $linhas = [
        '*Ordem de Serviço Nº ' . osNumber((int) $os['id']) . '* — Ecoleva',
        '',
        'Cliente: ' . osPlainField($os['client_name'] ?? null),
    ];
    foreach (osDocumentFields($os) as $rotulo => $valor) {
        $linhas[] = $rotulo . ': ' . $valor;
    }

    if ($shareUrl !== '') {
        $linhas[] = '';
        $linhas[] = 'Abrir e imprimir: ' . $shareUrl;
    }

    $linhas[] = '';
    $linhas[] = 'Caso precise, envie WhatsApp para ' . OS_SUPPORT_PHONE . '.';

    return implode("\n", $linhas);
}

/**
 * Valor de campo opcional em texto puro (sem escapar: não passa por HTML), com o
 * marcador de vazio quando não há nada.
 */
function osPlainField($value): string
{
    $value = trim((string) ($value ?? ''));

    return $value === '' ? OS_EMPTY_FIELD : $value;
}

/**
 * Assunto do e-mail, em texto puro.
 *
 * Quem o codifica é o envio, e só no ramo que precisa (`osMailEncodeSubject()`,
 * para o `mail()`). Antes esta função já devolvia o cabeçalho codificado, e o
 * ramo do SMTP desfazia a codificação na mão para o PHPMailer codificar de novo.
 *
 * @param array<string,mixed> $os
 */
function osEmailSubject(array $os): string
{
    return sprintf(
        'Ordem de Serviço Nº %s — %s',
        osNumber((int) $os['id']),
        trim((string) ($os['client_name'] ?? ''))
    );
}

/**
 * Assunto pronto para o `mail()` do PHP, que não codifica nada sozinho.
 *
 * Cada palavra codificada de um cabeçalho (RFC 2047) tem no máximo 75
 * caracteres. O assunto saía como UMA palavra só, que passava disso com um nome
 * de cliente longo, e servidores mais rigorosos recusam ou truncam. O
 * `mb_encode_mimeheader` divide em quantas palavras forem precisas, dobrando a
 * linha com espaço, e deixa intacto o que é só ASCII.
 *
 * Quebra de linha vira espaço antes de tudo: é por ela que um assunto forjado
 * abriria um cabeçalho novo (`Bcc:`).
 */
function osMailEncodeSubject(string $subject): string
{
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? '');

    // O recuo é o tamanho de "Subject: ", que o mail() escreve antes do valor.
    return mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n", strlen('Subject: '));
}

/** Tempo limite, em segundos, de conexão e leitura do SMTP. O padrão do PHPMailer é 300. */
const OS_SMTP_TIMEOUT = 15;

/**
 * `true` no modo de teste (`MAIL_TRANSPORT=log`): `osSendMail()` só registra o
 * destinatário no log e responde sucesso sem enviar nada. A tela precisa saber,
 * para não dizer "Enviada para X" de um e-mail que não saiu.
 */
function osMailIsLogOnly(): bool
{
    return strcasecmp(apiSecret('MAIL_TRANSPORT'), 'log') === 0;
}

/**
 * Remetente do e-mail da OS (e dos demais e-mails que passam por `osSendMail`).
 *
 * `OS_MAIL_FROM` vale nos DOIS ramos, como o `env.example.php` promete: só o do
 * `mail()` o lia, e com SMTP configurado ele era ignorado. Valor que não é um
 * e-mail é ignorado.
 *
 * Sem override: o `mail()` cai no padrão; o SMTP segue `CONTACT_FROM_EMAIL`, depois
 * o usuário do próprio SMTP (o servidor costuma exigir um remetente que a conta
 * autentique), e só então o padrão.
 */
function osMailFrom(bool $smtp): string
{
    $override = apiSecret('OS_MAIL_FROM');
    if ($override !== '' && filter_var($override, FILTER_VALIDATE_EMAIL) !== false) {
        return $override;
    }

    if ($smtp) {
        foreach (['CONTACT_FROM_EMAIL', 'SMTP_USER'] as $nome) {
            $valor = apiSecret($nome);
            if ($valor !== '') {
                return $valor;
            }
        }
    }

    return OS_MAIL_FROM_DEFAULT;
}

/**
 * O PHPMailer já configurado para o SMTP, sem enviar. Fica separado de
 * `osSendMail()` para dar para conferir remetente, assunto e tempo limite sem
 * abrir conexão nenhuma.
 *
 * O assunto entra em texto puro: o PHPMailer o codifica sozinho.
 *
 * @throws \PHPMailer\PHPMailer\Exception endereço inválido
 */
function osBuildSmtpMailer(string $to, string $subject, string $html, string $text, ?string $replyTo): \PHPMailer\PHPMailer\PHPMailer
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = apiSecret('SMTP_HOST');
    $mail->SMTPAuth = true;
    $mail->Username = apiSecret('SMTP_USER');
    $mail->Password = apiSecret('SMTP_PASS');

    $smtpSecure = strcasecmp(apiSecret('SMTP_SECURE'), 'true') === 0
        ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->SMTPSecure = (int) apiSecret('SMTP_PORT') === 465 ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : $smtpSecure;
    $mail->Port = (int) apiSecret('SMTP_PORT');

    // O padrão do PHPMailer é 300 segundos: com o SMTP fora do ar o botão ficava
    // cinco minutos pendurado, segurando o processo do PHP.
    $mail->Timeout = OS_SMTP_TIMEOUT;

    $mail->setFrom(osMailFrom(true), apiSecret('CONTACT_FROM_NAME') ?: 'Ecoleva');
    $mail->addAddress($to);
    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $mail->addReplyTo($replyTo);
    }

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $html;
    $mail->AltBody = $text;

    return $mail;
}

/**
 * Envia o e-mail multipart (texto + HTML).
 *
 * `MAIL_TRANSPORT=log` no env desliga o envio e registra o destinatário no log
 * do servidor. É o modo usado pela suíte de testes e por quem roda o painel na
 * máquina local, onde `mail()` cairia em um sendmail inexistente e o endpoint
 * responderia 500 sem que nada estivesse errado.
 */
function osSendMail(string $to, string $subject, string $html, string $text, ?string $replyTo = null): bool
{
    if (osMailIsLogOnly()) {
        error_log(sprintf('MAIL_TRANSPORT=log: e-mail de OS para %s não foi enviado.', $to));
        return true;
    }

    // Se SMTP não estiver configurado, faz fallback pro mail()
    if (apiSecret('SMTP_HOST') === '') {
        $boundary = '----=_' . bin2hex(random_bytes(8));

        $headers = [
            'From: Ecoleva <' . osMailFrom(false) . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $mime = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($text)
            . "\r\n--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($html)
            . "\r\n--{$boundary}--";

        // O mail() não codifica o assunto sozinho (o PHPMailer, abaixo, sim).
        return mail($to, osMailEncodeSubject($subject), $mime, implode("\r\n", $headers));
    }

    // Envio autenticado via PHPMailer / SMTP
    if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
        error_log('PHPMailer não instalado. Rode: composer require phpmailer/phpmailer');
        return false;
    }

    $mail = null;
    try {
        $mail = osBuildSmtpMailer($to, $subject, $html, $text, $replyTo);
        $mail->send();

        return true;
    } catch (\Throwable $e) {
        error_log('PHPMailer Error: ' . ($mail !== null && $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage()));

        return false;
    }
}
