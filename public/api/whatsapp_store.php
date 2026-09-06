<?php
declare(strict_types=1);

/**
 * Conversas e mensagens de WhatsApp no banco local (migration 015).
 *
 * Tudo que entra pelo webhook e tudo que sai pelo robô passa por aqui, para que
 * exista um único lugar que sabe:
 *
 *   - qual conversa pertence a qual número (e a qual cliente da Ecoleta);
 *   - o que já foi dito, nos dois sentidos;
 *   - até quando a janela de 24 horas da Meta está aberta.
 *
 * TEMPO É SEMPRE UTC. As colunas são DATETIME e todo instante entra por
 * `waNow()`/`waTimestampToUtc()` e sai comparado em PHP — nunca por NOW() do
 * MySQL, cujo fuso de sessão não é necessariamente o do PHP. Ver o comentário
 * na migration 015.
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/authz.php';
require_once __DIR__ . '/clients/phone_lib.php';

/** Duração da janela de atendimento da Meta, em segundos. */
const WA_SERVICE_WINDOW_SECONDS = 86400;

/**
 * Exige uma sessão que possa ler o painel de conversas.
 *
 * A regra pura mora em `apiRoleCanViewWhatsAppPanel()` (authz.php, espelhada em
 * `lib/authz.ts`). O que acontece aqui é o que só o servidor pode fazer: buscar
 * o e-mail GRAVADO na conta, em vez de acreditar no que o navegador mandou.
 *
 * @return array{id:int,role:string,login:string,email:?string}
 */
function waRequirePanelAccess(PDO $db): array
{
    $actor = apiRequireAuthenticated();

    $stmt = $db->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$actor['id']]);
    $email = $stmt->fetchColumn();
    $email = is_string($email) && $email !== '' ? $email : null;

    if (!apiRoleCanViewWhatsAppPanel($actor['role'], $email)) {
        apiJsonResponse(403, ['error' => API_ACCESS_DENIED]);
    }

    return $actor + ['email' => $email];
}

/** Instante atual em UTC, no formato das colunas DATETIME. */
function waNow(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Epoch (o `timestamp` da Meta, em segundos) para DATETIME UTC. */
function waTimestampToUtc($timestamp): ?string
{
    if (!is_numeric($timestamp)) {
        return null;
    }

    $epoch = (int) $timestamp;

    return $epoch > 0 ? gmdate('Y-m-d H:i:s', $epoch) : null;
}

/** DATETIME UTC do banco para ISO-8601 com Z — o que o navegador sabe formatar. */
function waToIso(?string $utcDatetime): ?string
{
    $value = trim((string) $utcDatetime);
    if ($value === '' || str_starts_with($value, '0000')) {
        return null;
    }

    return str_replace(' ', 'T', $value) . 'Z';
}

/**
 * A janela ainda está aberta?
 *
 * Comparação de strings UTC no formato `Y-m-d H:i:s`, que é ordenável
 * lexicograficamente — não precisa construir DateTime só para isso.
 */
function waWindowIsOpen(?string $expiresAtUtc, ?string $nowUtc = null): bool
{
    $expires = trim((string) $expiresAtUtc);
    if ($expires === '' || str_starts_with($expires, '0000')) {
        return false;
    }

    return $expires > ($nowUtc ?? waNow());
}

/** Minutos que faltam para a janela fechar. `null` quando não há janela. */
function waWindowMinutesLeft(?string $expiresAtUtc, ?string $nowUtc = null): ?int
{
    $expires = trim((string) $expiresAtUtc);
    if ($expires === '' || str_starts_with($expires, '0000')) {
        return null;
    }

    $diff = strtotime($expires . ' UTC') - strtotime(($nowUtc ?? waNow()) . ' UTC');

    return $diff <= 0 ? 0 : intdiv($diff, 60);
}

/**
 * O estado da janela de um número, como a interface precisa ler.
 *
 * @return array{open:bool,expires_at:?string,minutes_left:?int}
 */
function waWindowState(?string $expiresAtUtc, ?string $nowUtc = null): array
{
    return [
        'open' => waWindowIsOpen($expiresAtUtc, $nowUtc),
        'expires_at' => waToIso($expiresAtUtc),
        'minutes_left' => waWindowMinutesLeft($expiresAtUtc, $nowUtc),
    ];
}

/**
 * Acha (ou cria) a conversa de um número.
 *
 * O número é normalizado antes de qualquer coisa: o mesmo telefone escrito como
 * `(21) 99988-7766` no cadastro do cliente e como `5521999887766` pela Meta
 * precisa cair na mesma linha, senão a janela aberta por uma mensagem recebida
 * nunca seria encontrada na hora de enviar.
 */
function waEnsureConversation(PDO $db, string $phone, array $extra = []): ?int
{
    $phone = normalizePhone($phone);
    if ($phone === '') {
        return null;
    }

    $stmt = $db->prepare('SELECT id FROM whatsapp_conversations WHERE phone = ? LIMIT 1');
    $stmt->execute([$phone]);
    $id = $stmt->fetchColumn();

    if ($id !== false && $id !== null) {
        waUpdateConversationIdentity($db, (int) $id, $extra);

        return (int) $id;
    }

    $now = waNow();

    $stmt = $db->prepare('
        INSERT INTO whatsapp_conversations
            (phone, wa_id, profile_name, client_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $phone,
        $extra['wa_id'] ?? null,
        $extra['profile_name'] ?? null,
        $extra['client_id'] ?? waFindClientIdByPhone($db, $phone),
        $now,
        $now,
    ]);

    return (int) $db->lastInsertId();
}

/** Preenche nome e cliente quando o dado só apareceu depois. */
function waUpdateConversationIdentity(PDO $db, int $conversationId, array $extra): void
{
    $campos = [];
    $valores = [];

    foreach (['wa_id', 'profile_name'] as $coluna) {
        $valor = trim((string) ($extra[$coluna] ?? ''));
        if ($valor !== '') {
            $campos[] = "{$coluna} = ?";
            $valores[] = $valor;
        }
    }

    if (isset($extra['client_id']) && $extra['client_id'] !== null) {
        $campos[] = 'client_id = ?';
        $valores[] = (int) $extra['client_id'];
    }

    if ($campos === []) {
        return;
    }

    $valores[] = waNow();
    $valores[] = $conversationId;

    $stmt = $db->prepare(
        'UPDATE whatsapp_conversations SET ' . implode(', ', $campos) . ', updated_at = ? WHERE id = ?'
    );
    $stmt->execute($valores);
}

/**
 * O cliente da Ecoleta dono de um número, se houver.
 *
 * A comparação é feita sobre o valor normalizado dos dois lados porque o
 * cadastro antigo pode ter guardado o telefone com máscara.
 */
function waFindClientIdByPhone(PDO $db, string $phone): ?int
{
    $phone = normalizePhone($phone);
    if ($phone === '') {
        return null;
    }

    $stmt = $db->query('SELECT id, whatsapp FROM clients WHERE whatsapp IS NOT NULL AND whatsapp <> \'\'');
    foreach ($stmt ? $stmt->fetchAll() : [] as $linha) {
        if (normalizePhone((string) $linha['whatsapp']) === $phone) {
            return (int) $linha['id'];
        }
    }

    return null;
}

/** Resumo de uma linha na lista de conversas: corpo curto, sem quebra. */
function waPreview(?string $body, ?string $type): string
{
    $texto = trim((string) $body);
    if ($texto === '') {
        $texto = $type !== null && $type !== '' ? '[' . $type . ']' : '';
    }

    $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');

    return mb_substr($texto, 0, 180);
}

/**
 * Grava uma mensagem e atualiza o cabeçalho da conversa.
 *
 * Mensagem RECEBIDA reabre a conversa, soma no não lidas e empurra a janela
 * para 24h depois do instante dela — é o único evento que abre a janela.
 * Mensagem ENVIADA não mexe na janela: responder não compra tempo novo.
 *
 * @param array{
 *     wa_message_id?: ?string, direction: string, type?: ?string, status?: ?string,
 *     body?: ?string, error_message?: ?string, raw_payload?: mixed,
 *     message_at?: ?string, sent_by_user_id?: ?int, service_order_id?: ?int
 * } $mensagem
 * @return int|null id da linha; `null` quando a mensagem já estava gravada
 */
function waRecordMessage(PDO $db, int $conversationId, array $mensagem): ?int
{
    $direction = $mensagem['direction'] === 'outgoing' ? 'outgoing' : 'incoming';
    $messageAt = $mensagem['message_at'] ?? waNow();
    $now = waNow();
    $waMessageId = trim((string) ($mensagem['wa_message_id'] ?? '')) ?: null;

    // A Meta reentrega o mesmo evento quando o ACK demora. O índice único em
    // wa_message_id é a garantia real; esta consulta só evita o erro de chave
    // duplicada no caminho normal.
    if ($waMessageId !== null && waFindMessageIdByWaId($db, $waMessageId) !== null) {
        return null;
    }

    $payload = $mensagem['raw_payload'] ?? null;

    try {
        $stmt = $db->prepare('
            INSERT INTO whatsapp_messages
                (conversation_id, wa_message_id, direction, type, status, body, error_message,
                 raw_payload, message_at, sent_by_user_id, service_order_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $conversationId,
            $waMessageId,
            $direction,
            $mensagem['type'] ?? null,
            $mensagem['status'] ?? ($direction === 'outgoing' ? 'accepted' : null),
            $mensagem['body'] ?? null,
            $mensagem['error_message'] ?? null,
            $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            $messageAt,
            $mensagem['sent_by_user_id'] ?? null,
            $mensagem['service_order_id'] ?? null,
            $now,
        ]);
    } catch (PDOException $e) {
        // 23000 = violação do índice único: chegou duas vezes ao mesmo tempo.
        if ($e->getCode() === '23000') {
            return null;
        }

        throw $e;
    }

    $id = (int) $db->lastInsertId();

    $campos = [
        'last_message_at = ?',
        'last_message_preview = ?',
        'last_message_direction = ?',
        'updated_at = ?',
    ];
    $valores = [
        $messageAt,
        waPreview($mensagem['body'] ?? null, $mensagem['type'] ?? null),
        $direction,
        $now,
    ];

    if ($direction === 'incoming') {
        $campos[] = 'last_inbound_at = ?';
        $valores[] = $messageAt;
        $campos[] = 'service_window_expires_at = ?';
        $valores[] = gmdate('Y-m-d H:i:s', strtotime($messageAt . ' UTC') + WA_SERVICE_WINDOW_SECONDS);
        $campos[] = 'status = ?';
        $valores[] = 'open';
        $campos[] = 'unread_count = unread_count + 1';
    }

    $valores[] = $conversationId;

    $stmt = $db->prepare(
        'UPDATE whatsapp_conversations SET ' . implode(', ', $campos) . ' WHERE id = ?'
    );
    $stmt->execute($valores);

    return $id;
}

/** id interno de uma mensagem pelo `wamid` da Meta. */
function waFindMessageIdByWaId(PDO $db, string $waMessageId): ?int
{
    $stmt = $db->prepare('SELECT id FROM whatsapp_messages WHERE wa_message_id = ? LIMIT 1');
    $stmt->execute([$waMessageId]);
    $id = $stmt->fetchColumn();

    return $id === false || $id === null ? null : (int) $id;
}

/**
 * Aplica um evento `statuses` do webhook (sent → delivered → read, ou failed).
 *
 * Só avança: um `sent` que chega atrasado, depois do `read`, não rebaixa o que
 * já foi confirmado. `failed` vence tudo, porque é estado terminal.
 */
function waUpdateMessageStatus(PDO $db, string $waMessageId, string $status, ?string $errorMessage = null): bool
{
    $ordem = ['accepted' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

    $stmt = $db->prepare('SELECT id, status FROM whatsapp_messages WHERE wa_message_id = ? LIMIT 1');
    $stmt->execute([$waMessageId]);
    $linha = $stmt->fetch();

    if (!is_array($linha)) {
        return false;
    }

    $atual = (string) ($linha['status'] ?? '');

    if ($status !== 'failed') {
        if ($atual === 'failed') {
            return false;
        }
        if (isset($ordem[$status], $ordem[$atual]) && $ordem[$status] <= $ordem[$atual]) {
            return false;
        }
    }

    $stmt = $db->prepare('UPDATE whatsapp_messages SET status = ?, error_message = COALESCE(?, error_message) WHERE id = ?');
    $stmt->execute([$status, $errorMessage, (int) $linha['id']]);

    return true;
}

/**
 * A conversa de um número, sem criar nada. Usado pelo botão da OS para saber se
 * a janela está aberta antes de tentar enviar.
 *
 * @return array<string,mixed>|null
 */
function waFindConversationByPhone(PDO $db, string $phone): ?array
{
    $phone = normalizePhone($phone);
    if ($phone === '') {
        return null;
    }

    $stmt = $db->prepare('SELECT * FROM whatsapp_conversations WHERE phone = ? LIMIT 1');
    $stmt->execute([$phone]);
    $linha = $stmt->fetch();

    return is_array($linha) ? $linha : null;
}

/**
 * Janela por número, em uma consulta só.
 *
 * A lista de OS precisa do estado de dezenas de clientes de uma vez; perguntar
 * conversa por conversa faria uma consulta por linha da tabela.
 *
 * @param list<string> $phones
 * @return array<string, array{open:bool,expires_at:?string,minutes_left:?int}>
 */
function waWindowsByPhone(PDO $db, array $phones): array
{
    $normalizados = [];
    foreach ($phones as $phone) {
        $normalizado = normalizePhone((string) $phone);
        if ($normalizado !== '') {
            $normalizados[$normalizado] = true;
        }
    }

    if ($normalizados === []) {
        return [];
    }

    $lista = array_keys($normalizados);
    $marcadores = implode(',', array_fill(0, count($lista), '?'));

    $stmt = $db->prepare(
        'SELECT phone, service_window_expires_at FROM whatsapp_conversations WHERE phone IN (' . $marcadores . ')'
    );
    $stmt->execute($lista);

    $agora = waNow();
    $janelas = [];
    foreach ($stmt->fetchAll() as $linha) {
        $janelas[(string) $linha['phone']] = waWindowState(
            $linha['service_window_expires_at'] === null ? null : (string) $linha['service_window_expires_at'],
            $agora
        );
    }

    return $janelas;
}
