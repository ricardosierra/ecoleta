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
 * `lib/authz.ts`). `apiRequireAuthenticated()` confere no banco o papel real da
 * conta, sem confiar no papel enviado pela sessão.
 *
 * @return array{id:int,role:string,login:string,force_password_change:bool}
 */
function waRequirePanelAccess(): array
{
    $actor = apiRequireAuthenticated();

    if (!apiRoleCanViewWhatsAppPanel($actor['role'])) {
        apiJsonResponse(403, ['error' => API_ACCESS_DENIED]);
    }

    return $actor;
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
 * Converte o conteúdo de uma mensagem recebida pela Cloud API em texto útil
 * para o histórico. O payload original continua guardado separadamente; aqui
 * ficam apenas os campos que uma pessoa reconhece na conversa.
 */
function waExtractIncomingMessageBody(array $message): ?string
{
    $type = strtolower(trim((string) ($message['type'] ?? '')));
    $body = null;

    if (is_array($message['text'] ?? null)) {
        $body = waMessageScalarText($message['text']['body'] ?? null);
    }

    if ($body === null) {
        foreach (['image', 'video', 'document', 'audio'] as $mediaType) {
            $media = $message[$mediaType] ?? null;
            if (is_array($media)) {
                $body = waMessageScalarText($media['caption'] ?? null);
                if ($body !== null) {
                    break;
                }
            }
        }
    }

    if ($body === null && is_array($message['button'] ?? null)) {
        $body = waMessageScalarText($message['button']['text'] ?? null)
            ?? waMessageScalarText($message['button']['payload'] ?? null);
    }

    if ($body === null && $type === 'interactive' && is_array($message['interactive'] ?? null)) {
        $interactive = $message['interactive'];

        foreach (['button_reply', 'list_reply'] as $replyType) {
            $reply = $interactive[$replyType] ?? null;
            if (!is_array($reply)) {
                continue;
            }

            $body = waMessageScalarText($reply['title'] ?? null)
                ?? waMessageScalarText($reply['description'] ?? null)
                ?? waMessageScalarText($reply['id'] ?? null);
            if ($body !== null) {
                break;
            }
        }

        if ($body === null && is_array($interactive['nfm_reply'] ?? null)) {
            $reply = $interactive['nfm_reply'];
            $replyText = waMessageScalarText($reply['body'] ?? null);
            $responseJson = waMessageScalarText($reply['response_json'] ?? null);
            $response = $responseJson !== null ? json_decode($responseJson, true) : null;
            $responseLines = is_array($response) ? waMessageValueLines($response) : [];

            if ($responseLines !== []) {
                $body = ($replyText !== null && strcasecmp($replyText, 'sent') !== 0 ? $replyText . "\n" : '')
                    . implode("\n", $responseLines);
            } else {
                $body = $replyText;
            }
        }

        if ($body === null && is_array($interactive['call_permission_reply'] ?? null)) {
            $accepted = strtolower((string) ($interactive['call_permission_reply']['response'] ?? '')) === 'accept';
            $expiration = waMessageScalarText($interactive['call_permission_reply']['expiration_timestamp'] ?? null);
            $permanent = ($interactive['call_permission_reply']['is_permanent'] ?? false) === true;
            $body = $accepted
                ? 'Permissão para ligação aceita' . ($permanent ? ' sem prazo.' : '.')
                : 'Permissão para ligação recusada.';
            if ($accepted && !$permanent && $expiration !== null && ctype_digit($expiration)) {
                $body .= ' Válida até ' . gmdate('d/m/Y H:i', (int) $expiration) . ' UTC.';
            }
        }

        if ($body === null) {
            $interactiveLines = waMessageValueLines($interactive, '', 0, true);
            if ($interactiveLines !== []) {
                $body = "Resposta interativa:\n" . implode("\n", $interactiveLines);
            }
        }
    }

    if ($body === null && is_array($message['reaction'] ?? null)) {
        $emoji = waMessageScalarText($message['reaction']['emoji'] ?? null);
        $body = $emoji !== null ? 'Reação: ' . $emoji : 'Reação removida.';
    }

    if ($body === null && in_array($type, ['location', 'live_location'], true) && is_array($message['location'] ?? $message['live_location'] ?? null)) {
        $location = is_array($message['location'] ?? null) ? $message['location'] : $message['live_location'];
        $parts = [$type === 'live_location' ? 'Localização ao vivo' : 'Localização enviada'];
        $name = waMessageScalarText($location['name'] ?? null);
        $address = waMessageScalarText($location['address'] ?? null);
        $latitude = waMessageScalarText($location['latitude'] ?? null);
        $longitude = waMessageScalarText($location['longitude'] ?? null);
        $url = waMessageScalarText($location['url'] ?? null);

        if ($name !== null) {
            $parts[] = $name;
        }
        if ($address !== null) {
            $parts[] = $address;
        }
        if ($latitude !== null && $longitude !== null) {
            $parts[] = 'Coordenadas: ' . $latitude . ', ' . $longitude;
        }
        if ($url !== null && str_starts_with(strtolower($url), 'https://')) {
            $parts[] = 'Mapa: ' . $url;
        }

        $body = implode("\n", $parts);
    }

    if ($body === null && $type === 'contacts' && is_array($message['contacts'] ?? null)) {
        $contacts = [];
        foreach ($message['contacts'] as $contact) {
            if (!is_array($contact)) {
                continue;
            }

            $name = is_array($contact['name'] ?? null)
                ? waMessageScalarText($contact['name']['formatted_name'] ?? null)
                : null;
            if ($name === null && is_array($contact['name'] ?? null)) {
                $name = trim(implode(' ', array_filter([
                    waMessageScalarText($contact['name']['first_name'] ?? null),
                    waMessageScalarText($contact['name']['middle_name'] ?? null),
                    waMessageScalarText($contact['name']['last_name'] ?? null),
                ])));
                $name = $name !== '' ? $name : null;
            }
            $lines = [$name !== null ? 'Contato: ' . $name : 'Contato compartilhado'];

            foreach ($contact['phones'] ?? [] as $phone) {
                if (!is_array($phone)) {
                    continue;
                }
                $number = waMessageScalarText($phone['phone'] ?? null)
                    ?? waMessageScalarText($phone['wa_id'] ?? null);
                if ($number !== null) {
                    $lines[] = 'Telefone: ' . $number;
                }
            }

            foreach ($contact['emails'] ?? [] as $email) {
                if (is_array($email) && ($address = waMessageScalarText($email['email'] ?? null)) !== null) {
                    $lines[] = 'E-mail: ' . $address;
                }
            }

            foreach ($contact['urls'] ?? [] as $url) {
                if (is_array($url) && ($address = waMessageScalarText($url['url'] ?? null)) !== null) {
                    $lines[] = 'Site: ' . $address;
                }
            }

            if (is_array($contact['org'] ?? null)) {
                $company = waMessageScalarText($contact['org']['company'] ?? null);
                if ($company !== null) {
                    $lines[] = 'Empresa: ' . $company;
                }
                foreach (['department' => 'Setor', 'title' => 'Cargo'] as $field => $label) {
                    $value = waMessageScalarText($contact['org'][$field] ?? null);
                    if ($value !== null) {
                        $lines[] = $label . ': ' . $value;
                    }
                }
            }

            foreach ($contact['addresses'] ?? [] as $address) {
                if (!is_array($address)) {
                    continue;
                }
                $addressParts = array_filter([
                    waMessageScalarText($address['street'] ?? null),
                    waMessageScalarText($address['city'] ?? null),
                    waMessageScalarText($address['state'] ?? null),
                    waMessageScalarText($address['zip'] ?? null),
                    waMessageScalarText($address['country'] ?? null),
                ]);
                if ($addressParts !== []) {
                    $lines[] = 'Endereço: ' . implode(', ', $addressParts);
                }
            }

            $birthday = waMessageScalarText($contact['birthday'] ?? null);
            if ($birthday !== null) {
                $lines[] = 'Aniversário: ' . $birthday;
            }

            $contacts[] = implode("\n", $lines);
        }

        if ($contacts !== []) {
            $body = implode("\n\n", $contacts);
        }
    }

    if ($body === null && $type === 'order' && is_array($message['order'] ?? null)) {
        $order = $message['order'];
        $lines = ['Pedido recebido'];
        $orderText = waMessageScalarText($order['text'] ?? null);
        if ($orderText !== null) {
            $lines[] = $orderText;
        }

        foreach ($order['product_items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $product = waMessageScalarText($item['product_retailer_id'] ?? null) ?? 'Produto';
            $quantity = waMessageScalarText($item['quantity'] ?? null);
            $price = waMessageScalarText($item['item_price'] ?? null);
            $currency = waMessageScalarText($item['currency'] ?? null);
            $line = $product;
            if ($quantity !== null) {
                $line .= ' — quantidade: ' . $quantity;
            }
            if ($price !== null) {
                $line .= ' — valor unitário: ' . $price . ($currency !== null ? ' ' . $currency : '');
            }
            $lines[] = $line;
        }

        $body = implode("\n", $lines);
    }

    if ($body === null && $type === 'system' && is_array($message['system'] ?? null)) {
        $system = $message['system'];
        $systemType = strtolower(trim((string) ($system['type'] ?? '')));
        $systemLabels = [
            'customer_changed_number' => 'O contato trocou o número do WhatsApp.',
            'customer_identity_changed' => 'A identidade do contato foi atualizada no WhatsApp.',
            'user_changed_user_id' => 'O identificador do contato foi atualizado no WhatsApp.',
            'user_changed_number' => 'O contato trocou o número do WhatsApp.',
            'group_create' => 'Um grupo foi criado.',
            'group_change_subject' => 'O nome do grupo foi alterado.',
            'group_change_icon' => 'A imagem do grupo foi alterada.',
            'group_change_description' => 'A descrição do grupo foi alterada.',
            'group_participant_add' => 'Alguém entrou no grupo.',
            'group_participant_remove' => 'Alguém saiu do grupo.',
            'group_participant_leave' => 'Alguém saiu do grupo.',
            'group_participant_invite' => 'Alguém foi convidado para o grupo.',
            'group_participant_promote' => 'Alguém virou administrador do grupo.',
            'group_participant_demote' => 'Alguém deixou de ser administrador do grupo.',
        ];
        $body = $systemLabels[$systemType]
            ?? waMessageScalarText($system['body'] ?? null)
            ?? 'Aviso do WhatsApp.';
        $newWaId = waMessageScalarText($system['wa_id'] ?? null) ?? waMessageScalarText($system['new_wa_id'] ?? null);
        if ($newWaId !== null && !str_contains((string) $body, $newWaId)) {
            $body = rtrim((string) $body, '.') . '. Novo número: ' . $newWaId . '.';
        }
    }

    if ($body === null && $type === 'request_welcome') {
        $body = 'A pessoa abriu a conversa por um link ou anúncio.';
    }

    if ($body === null && $type === 'edit' && is_array($message['edit'] ?? null)) {
        $edited = is_array($message['edit']['message'] ?? null) ? $message['edit']['message'] : [];
        $editedBody = $edited !== [] ? waExtractIncomingMessageBody($edited) : null;
        $body = $editedBody !== null
            ? 'Mensagem editada: ' . $editedBody
            : 'O contato editou uma mensagem.';
    }

    if ($body === null && $type === 'revoke') {
        $body = 'O contato apagou uma mensagem.';
    }

    if ($body === null && in_array($type, ['poll', 'poll_creation'], true)) {
        $poll = is_array($message['poll'] ?? null)
            ? $message['poll']
            : (is_array($message['poll_creation'] ?? null) ? $message['poll_creation'] : []);
        $title = waMessageScalarText($poll['title'] ?? null)
            ?? waMessageScalarText($poll['name'] ?? null)
            ?? waMessageScalarText($poll['question'] ?? null);
        $lines = [$title !== null ? 'Enquete: ' . $title : 'Enquete recebida'];
        foreach ($poll['options'] ?? [] as $option) {
            $label = is_array($option)
                ? (waMessageScalarText($option['option_name'] ?? null) ?? waMessageScalarText($option['name'] ?? null) ?? waMessageScalarText($option['text'] ?? null))
                : waMessageScalarText($option);
            if ($label !== null) {
                $lines[] = '• ' . $label;
            }
        }
        $body = implode("\n", $lines);
    }

    if ($body === null && in_array($type, ['poll_update', 'poll_vote'], true)) {
        $update = is_array($message['poll_update'] ?? null)
            ? $message['poll_update']
            : (is_array($message['poll_vote'] ?? null) ? $message['poll_vote'] : []);
        $lines = ['Voto em enquete'];
        foreach (['votes', 'selected_options', 'options'] as $listKey) {
            foreach ($update[$listKey] ?? [] as $vote) {
                $label = is_array($vote)
                    ? (waMessageScalarText($vote['option_name'] ?? null) ?? waMessageScalarText($vote['name'] ?? null) ?? waMessageScalarText($vote['text'] ?? null))
                    : waMessageScalarText($vote);
                if ($label !== null) {
                    $lines[] = '• ' . $label;
                }
            }
        }
        $body = implode("\n", array_values(array_unique($lines)));
    }

    if ($body === null && in_array($type, ['group_invite', 'groups_invite'], true)) {
        $invite = is_array($message['group_invite'] ?? null) ? $message['group_invite'] : [];
        $subject = waMessageScalarText($invite['group_subject'] ?? null)
            ?? waMessageScalarText($invite['subject'] ?? null)
            ?? waMessageScalarText($invite['name'] ?? null);
        $body = $subject !== null ? 'Convite para o grupo ' . $subject . '.' : 'Convite para um grupo.';
    }

    if ($body === null && $type === 'pin') {
        $body = 'Uma mensagem foi fixada na conversa.';
    }

    if ($body === null && in_array($type, ['keep_in_chat', 'keep'], true)) {
        $body = 'Uma mensagem foi salva na conversa.';
    }

    if ($body === null && $type === 'ephemeral') {
        $seconds = is_array($message['ephemeral'] ?? null)
            ? waMessageScalarText($message['ephemeral']['expiration'] ?? null)
            : null;
        $body = $seconds !== null && ctype_digit($seconds)
            ? 'Mensagens temporárias ativadas (' . waEphemeralDurationLabel((int) $seconds) . ').'
            : 'Mensagens temporárias foram ativadas nesta conversa.';
    }

    if ($body === null && in_array($type, ['template', 'hsm'], true)) {
        $template = is_array($message['template'] ?? null)
            ? $message['template']
            : (is_array($message['hsm'] ?? null) ? $message['hsm'] : []);
        $name = waMessageScalarText($template['name'] ?? null);
        $lines = [];
        foreach ($template['components'] ?? [] as $component) {
            if (!is_array($component)) {
                continue;
            }
            $text = waMessageScalarText($component['text'] ?? null);
            if ($text !== null) {
                $lines[] = $text;
            }
            foreach ($component['parameters'] ?? [] as $parameter) {
                if (!is_array($parameter)) {
                    continue;
                }
                $paramText = waMessageScalarText($parameter['text'] ?? null);
                if ($paramText !== null) {
                    $lines[] = $paramText;
                }
            }
        }
        $body = $lines !== []
            ? implode("\n", $lines)
            : ($name !== null ? 'Mensagem de template: ' . $name . '.' : 'Mensagem de template.');
    }

    if ($body === null && in_array($type, ['product', 'catalog', 'product_list'], true)) {
        $product = is_array($message[$type] ?? null) ? $message[$type] : [];
        $title = waMessageScalarText($product['title'] ?? null)
            ?? waMessageScalarText($product['name'] ?? null)
            ?? waMessageScalarText($product['product_retailer_id'] ?? null);
        $labels = [
            'product' => 'Produto',
            'catalog' => 'Catálogo',
            'product_list' => 'Lista de produtos',
        ];
        $body = $title !== null ? $labels[$type] . ': ' . $title : $labels[$type] . ' recebido.';
    }

    if ($body === null) {
        $gif = is_array($message['video'] ?? null) && ($message['video']['gif'] ?? false);
        $body = match ($type) {
            'image' => '[imagem]',
            'video' => $gif ? '[GIF]' : '[vídeo]',
            'gif' => '[GIF]',
            'audio' => is_array($message['audio'] ?? null) && ($message['audio']['voice'] ?? false)
                ? '[mensagem de voz]'
                : '[áudio]',
            'document' => is_array($message['document'] ?? null)
                ? '[documento' . (($filename = waMessageScalarText($message['document']['filename'] ?? null)) !== null ? ': ' . $filename : '') . ']'
                : '[documento]',
            'sticker' => is_array($message['sticker'] ?? null) && ($message['sticker']['animated'] ?? false)
                ? '[figurinha animada]'
                : '[figurinha]',
            'location', 'live_location' => '[localização]',
            'contacts' => '[contato]',
            'unsupported', 'unknown', 'errors' => waUnsupportedMessageBody($message),
            default => $type !== '' ? waUnknownMessageBody($message, $type) : null,
        };
    }

    if ($body === null && is_array($message['errors'] ?? null) && $message['errors'] !== []) {
        $body = waUnsupportedMessageBody($message);
    }

    $referral = is_array($message['referral'] ?? null) ? $message['referral'] : [];
    if ($referral !== []) {
        $origin = [];
        $source = waMessageScalarText($referral['source_type'] ?? null);
        $headline = waMessageScalarText($referral['headline'] ?? null);
        if ($source !== null) {
            $origin[] = $source === 'ad' ? 'anúncio' : $source;
        }
        if ($headline !== null) {
            $origin[] = $headline;
        }
        if ($origin !== []) {
            $body = trim((string) $body . "\nOrigem: " . implode(' — ', $origin));
        }
    }

    if ($body === null || trim($body) === '') {
        $body = 'Mensagem recebida.';
    }

    return mb_substr(trim($body), 0, 12000);
}

/** Duração de mensagem temporária em linguagem corrente. */
function waEphemeralDurationLabel(int $seconds): string
{
    if ($seconds % 86400 === 0 && $seconds >= 86400) {
        $days = intdiv($seconds, 86400);

        return $days === 1 ? '24 horas' : $days . ' dias';
    }
    if ($seconds % 3600 === 0 && $seconds >= 3600) {
        $hours = intdiv($seconds, 3600);

        return $hours === 1 ? '1 hora' : $hours . ' horas';
    }

    return $seconds . ' segundos';
}

/** Texto curto sem coerção de objetos nem espaços vazios. */
function waMessageScalarText($value): ?string
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return null;
    }

    $text = trim((string) $value);
    return $text !== '' ? $text : null;
}

/** Devolve linhas legíveis de uma resposta de Flow, limitada em profundidade. */
function waMessageValueLines(array $value, string $prefix = '', int $depth = 0, bool $skipMetadata = false): array
{
    if ($depth > 6) {
        return [];
    }

    $lines = [];
    foreach (array_slice($value, 0, 100, true) as $key => $item) {
        $metadataKey = strtolower((string) $key);
        if (
            $skipMetadata
            && (
                in_array($metadataKey, [
                    'id', 'from', 'to', 'wa_id', 'timestamp', 'context', 'sha256',
                    'mime_type', 'media_id', 'file_id', 'identity', 'signature',
                ], true)
                || preg_match('/(?:url|uri|token|secret|hash)$/', $metadataKey) === 1
            )
        ) {
            continue;
        }

        $label = $prefix !== '' ? $prefix . '.' . (string) $key : (string) $key;
        if (is_array($item)) {
            $lines = array_merge($lines, waMessageValueLines($item, $label, $depth + 1, $skipMetadata));
        } elseif (is_string($item) || is_int($item) || is_float($item)) {
            $text = trim((string) $item);
            if ($text !== '') {
                $lines[] = $label . ': ' . $text;
            }
        }
    }

    return $lines;
}

/** Fallback legível para tipos novos, sem devolver identificadores ou URLs de mídia. */
function waUnknownMessageBody(array $message, string $type): string
{
    $visible = $message;
    foreach (['from', 'id', 'timestamp', 'type', 'context', 'errors', 'referral', 'identity', 'unsupported'] as $key) {
        unset($visible[$key]);
    }

    $lines = waMessageValueLines($visible, '', 0, true);
    $label = waMessageTypeLabel($type);
    if ($lines === []) {
        return $label . '.';
    }

    return $label . ":\n" . implode("\n", $lines);
}

/** Nome em português de um tipo da Cloud API, inclusive os que ainda não têm renderer próprio. */
function waMessageTypeLabel(string $type): string
{
    $labels = [
        'text' => 'Texto',
        'image' => 'Imagem',
        'video' => 'Vídeo',
        'gif' => 'GIF',
        'audio' => 'Áudio',
        'document' => 'Documento',
        'sticker' => 'Figurinha',
        'location' => 'Localização',
        'live_location' => 'Localização ao vivo',
        'contacts' => 'Contato',
        'button' => 'Botão',
        'interactive' => 'Resposta interativa',
        'reaction' => 'Reação',
        'order' => 'Pedido',
        'system' => 'Aviso do WhatsApp',
        'request_welcome' => 'Abertura de conversa',
        'edit' => 'Mensagem editada',
        'revoke' => 'Mensagem apagada',
        'poll' => 'Enquete',
        'poll_creation' => 'Enquete',
        'poll_update' => 'Voto em enquete',
        'poll_vote' => 'Voto em enquete',
        'group_invite' => 'Convite de grupo',
        'groups_invite' => 'Convite de grupo',
        'pin' => 'Mensagem fixada',
        'keep_in_chat' => 'Mensagem salva',
        'keep' => 'Mensagem salva',
        'ephemeral' => 'Mensagem temporária',
        'template' => 'Template',
        'hsm' => 'Template',
        'product' => 'Produto',
        'catalog' => 'Catálogo',
        'product_list' => 'Lista de produtos',
        'unsupported' => 'Mensagem recebida',
        'unknown' => 'Mensagem recebida',
        'errors' => 'Mensagem recebida',
        'referral' => 'Origem da conversa',
        'address' => 'Endereço',
        'payment' => 'Pagamento',
        'call' => 'Ligação',
        'voice_call' => 'Ligação de voz',
        'video_call' => 'Ligação de vídeo',
    ];

    return $labels[$type] ?? ('Mensagem (' . $type . ')');
}

/** A Cloud API informa o subtipo quando consegue identificar a mensagem, mas não o conteúdo. */
function waUnsupportedMessageBody(array $message): string
{
    $unsupported = is_array($message['unsupported'] ?? null) ? $message['unsupported'] : [];
    $subtype = strtolower(trim((string) ($unsupported['type'] ?? '')));
    $labels = [
        'edit' => 'O contato editou uma mensagem. O texto novo não veio neste evento.',
        'revoke' => 'O contato apagou uma mensagem.',
        'poll_creation' => 'O contato criou uma enquete. As opções não vieram neste evento.',
        'poll_update' => 'O contato votou numa enquete. O voto não veio neste evento.',
        'pin' => 'Uma mensagem foi fixada na conversa.',
        'unpin' => 'Uma mensagem deixou de ficar fixada.',
        'keep_in_chat' => 'Uma mensagem foi salva na conversa.',
        'group_invite' => 'Convite para um grupo. O link não veio neste evento.',
        'gif' => 'GIF recebido. O arquivo não veio neste evento.',
        'media_placeholder' => 'Mídia recebida, ainda indisponível para download.',
        'sticker' => 'Figurinha recebida. O arquivo não veio neste evento.',
        'image' => 'Imagem recebida. O arquivo não veio neste evento.',
        'video' => 'Vídeo recebido. O arquivo não veio neste evento.',
        'audio' => 'Áudio recebido. O arquivo não veio neste evento.',
        'document' => 'Documento recebido. O arquivo não veio neste evento.',
        'location' => 'Localização recebida. As coordenadas não vieram neste evento.',
        'contact' => 'Contato compartilhado. Os dados não vieram neste evento.',
        'contacts' => 'Contato compartilhado. Os dados não vieram neste evento.',
        'ephemeral' => 'Mensagem temporária. O conteúdo já não está disponível.',
        'view_once' => 'Mídia de visualização única. O arquivo não pode ser aberto de novo.',
        'lto' => 'Oferta por tempo limitado. O conteúdo não veio neste evento.',
        'call' => 'Ligação. Os detalhes não vieram neste evento.',
        'payment' => 'Pagamento. Os detalhes não vieram neste evento.',
        'order' => 'Pedido. Os itens não vieram neste evento.',
    ];

    if (isset($labels[$subtype])) {
        return $labels[$subtype];
    }

    $error = is_array($message['errors'][0] ?? null) ? $message['errors'][0] : [];
    $title = waMessageScalarText($error['title'] ?? null);
    $details = is_array($error['error_data'] ?? null)
        ? waMessageScalarText($error['error_data']['details'] ?? null)
        : null;
    $hint = $details ?? $title;
    if ($hint !== null) {
        return 'Mensagem recebida. ' . $hint;
    }
    if ($subtype !== '') {
        return waMessageTypeLabel($subtype) . '.';
    }

    return 'Mensagem recebida.';
}

/**
 * Devolve o número normalizado e sua variante com/sem o 9º dígito (para celulares do Brasil).
 *
 * No Brasil, a Meta Webhook muitas vezes envia wa_id de contas antigas com 12 dígitos (sem o 9,
 * ex: 552199887766), enquanto cadastros no sistema possuem 13 dígitos (com o 9, ex: 5521999887766).
 * Considerar ambas as variantes evita conversas duplicadas e perda de vínculo com o cliente.
 *
 * @return string[]
 */
function waPhoneVariants(string $phone): array
{
    $phone = normalizePhone($phone);
    if ($phone === '') {
        return [];
    }

    $variants = [$phone];

    if (str_starts_with($phone, '55')) {
        $ddd = substr($phone, 2, 2);
        // 13 dígitos: 55 + DDD + 9 + 8 dígitos (celular padrão). Variante sem o 9 (12 dígitos).
        if (strlen($phone) === 13 && $phone[4] === '9') {
            $variants[] = '55' . $ddd . substr($phone, 5);
        }
        // 12 dígitos: 55 + DDD + 8 dígitos de celular (iniciados em 6, 7, 8 ou 9). Variante com o 9 (13 dígitos).
        elseif (strlen($phone) === 12 && in_array($phone[4], ['6', '7', '8', '9'], true)) {
            $variants[] = '55' . $ddd . '9' . substr($phone, 4);
        }
    }

    return array_values(array_unique($variants));
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

    $variants = waPhoneVariants($phone);
    $placeholders = implode(',', array_fill(0, count($variants), '?'));

    $stmt = $db->prepare("SELECT id FROM whatsapp_conversations WHERE phone IN ($placeholders) ORDER BY (last_message_at IS NOT NULL) DESC, id ASC LIMIT 1");
    $stmt->execute($variants);
    $id = $stmt->fetchColumn();

    if ($id !== false && $id !== null) {
        waUpdateConversationIdentity($db, (int) $id, $extra);

        return (int) $id;
    }

    $now = waNow();

    try {
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
    } catch (PDOException $e) {
        // 23000 = violação do índice único do telefone: duas mensagens
        // simultâneas de um número novo olharam, nenhuma achou a linha, as duas
        // inseriram. A que perdeu não pode descartar a mensagem dela, então
        // adota a conversa que a vencedora criou. O 23000 também cobre chave
        // estrangeira inválida; nesse caso a releitura não acha nada e o erro
        // original sobe.
        if ($e->getCode() !== '23000') {
            throw $e;
        }

        $stmt = $db->prepare("SELECT id FROM whatsapp_conversations WHERE phone IN ($placeholders) ORDER BY id ASC LIMIT 1");
        $stmt->execute($variants);
        $id = $stmt->fetchColumn();

        if ($id === false || $id === null) {
            throw $e;
        }

        waUpdateConversationIdentity($db, (int) $id, $extra);

        return (int) $id;
    }

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

    // O dono da conversa só é PREENCHIDO, nunca trocado. Dois clientes podem
    // cadastrar o mesmo telefone; se cada chamada (a sincronização da lista, o
    // robô, a OS) regravasse o client_id, a conversa mudaria de nome a cada
    // GET. COALESCE mantém o que já está lá e só age sobre NULL.
    if (isset($extra['client_id']) && $extra['client_id'] !== null) {
        $campos[] = 'client_id = COALESCE(client_id, ?)';
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
 * A comparação é feita sobre o valor normalizado dos dois lados e considera
 * variações com e sem o 9º dígito.
 */
function waFindClientIdByPhone(PDO $db, string $phone): ?int
{
    $phone = normalizePhone($phone);
    if ($phone === '') {
        return null;
    }

    $variants = waPhoneVariants($phone);

    $stmt = $db->query('SELECT id, whatsapp FROM clients WHERE whatsapp IS NOT NULL AND whatsapp <> \'\'');
    foreach ($stmt ? $stmt->fetchAll() : [] as $linha) {
        $clientPhone = normalizePhone((string) $linha['whatsapp']);
        if (in_array($clientPhone, $variants, true)) {
            return (int) $linha['id'];
        }
    }

    return null;
}

/**
 * `$candidato` é igual ou posterior a `$atual`? Sem valor atual, sempre.
 *
 * Comparação textual: os dois lados são DATETIME UTC no formato `Y-m-d H:i:s`,
 * que ordena lexicograficamente.
 */
function waInstantIsNotOlder(string $candidato, ?string $atual): bool
{
    $atual = trim((string) $atual);
    if ($atual === '' || str_starts_with($atual, '0000')) {
        return true;
    }

    return $candidato >= $atual;
}

/** O mais tardio de dois instantes UTC, ignorando os vazios. `null` quando os dois são vazios. */
function waLaterInstant(?string $a, ?string $b): ?string
{
    $a = trim((string) $a);
    $b = trim((string) $b);
    $a = ($a === '' || str_starts_with($a, '0000')) ? null : $a;
    $b = ($b === '' || str_starts_with($b, '0000')) ? null : $b;

    if ($a === null) {
        return $b;
    }

    return $b === null || $a >= $b ? $a : $b;
}

/**
 * Funde a conversa duplicada na que fica (12 e 13 dígitos do mesmo celular).
 *
 * Mantém a conversa de `$manterId` e apaga a de `$descartarId`, depois de levar
 * para a mantida TUDO que dava valor à outra. Jogar a descartada fora sem copiar
 * o estado fazia o cliente que escreveu há 4 horas voltar da unificação com a
 * janela fechada, zero não lidas e a conversa encerrada.
 *
 *  - janela e último inbound: o MAIOR dos dois (a janela é do cliente, e quem
 *    escreveu por último abriu a mais longa);
 *  - não lidas: a SOMA;
 *  - status: aberta se qualquer uma estava aberta;
 *  - perfil e cliente: os da mantida, e só na falta deles os da descartada;
 *  - prévia da lista: a da mensagem mais recente depois de juntar as mensagens.
 *
 * Tudo numa transação: o DELETE final só vale se as cópias valeram.
 */
function waMergeConversations(PDO $db, int $manterId, int $descartarId): void
{
    if ($manterId === $descartarId) {
        return;
    }

    $stmt = $db->prepare('SELECT * FROM whatsapp_conversations WHERE id IN (?, ?)');
    $stmt->execute([$manterId, $descartarId]);

    $linhas = [];
    foreach ($stmt->fetchAll() as $linha) {
        $linhas[(int) $linha['id']] = $linha;
    }

    // Outra requisição (ou uma execução anterior) já fundiu este par.
    if (!isset($linhas[$manterId], $linhas[$descartarId])) {
        return;
    }

    $manter = $linhas[$manterId];
    $descartar = $linhas[$descartarId];

    $abreTransacao = !$db->inTransaction();
    if ($abreTransacao) {
        $db->beginTransaction();
    }

    try {
        $db->prepare('UPDATE whatsapp_messages SET conversation_id = ? WHERE conversation_id = ?')
            ->execute([$manterId, $descartarId]);

        $stmt = $db->prepare('
            SELECT message_at, body, type, direction
              FROM whatsapp_messages
             WHERE conversation_id = ?
             ORDER BY message_at DESC, id DESC
             LIMIT 1
        ');
        $stmt->execute([$manterId]);
        $ultima = $stmt->fetch();

        if (is_array($ultima)) {
            $ultimaEm = (string) $ultima['message_at'];
            $previa = waPreview($ultima['body'], $ultima['type']);
            $direcao = $ultima['direction'];
        } else {
            // Sem mensagens para reler: vale o cabeçalho da conversa mais recente.
            $maisRecente = waLaterInstant($manter['last_message_at'], $descartar['last_message_at']) === ($manter['last_message_at'] ?? null)
                ? $manter
                : $descartar;
            $ultimaEm = $maisRecente['last_message_at'];
            $previa = $maisRecente['last_message_preview'];
            $direcao = $maisRecente['last_message_direction'];
        }

        $perfil = trim((string) ($manter['profile_name'] ?? '')) !== ''
            ? $manter['profile_name']
            : $descartar['profile_name'];

        $db->prepare('
            UPDATE whatsapp_conversations
               SET service_window_expires_at = ?, last_inbound_at = ?, unread_count = ?,
                   status = ?, profile_name = ?, client_id = ?,
                   last_message_at = ?, last_message_preview = ?, last_message_direction = ?,
                   updated_at = ?
             WHERE id = ?
        ')->execute([
            waLaterInstant($manter['service_window_expires_at'], $descartar['service_window_expires_at']),
            waLaterInstant($manter['last_inbound_at'], $descartar['last_inbound_at']),
            (int) $manter['unread_count'] + (int) $descartar['unread_count'],
            ($manter['status'] === 'open' || $descartar['status'] === 'open') ? 'open' : 'closed',
            $perfil,
            $manter['client_id'] ?? $descartar['client_id'],
            $ultimaEm,
            $previa,
            $direcao,
            waNow(),
            $manterId,
        ]);

        $db->prepare('DELETE FROM whatsapp_conversations WHERE id = ?')->execute([$descartarId]);

        if ($abreTransacao) {
            $db->commit();
        }
    } catch (\Throwable $e) {
        if ($abreTransacao && $db->inTransaction()) {
            $db->rollBack();
        }

        throw $e;
    }
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

    // O cabeçalho da conversa (prévia, direção, janela) só AVANÇA. A Meta
    // reentrega com atraso, e fora de ordem, eventos que não tínhamos visto:
    // uma mensagem das 09:00 chegando depois de uma das 10:00 não pode puxar a
    // janela de 24h para trás (o cliente passaria a parecer fora da janela, e
    // o envio seria tarifado sem necessidade) nem trocar a prévia da lista.
    //
    // A decisão é tomada AQUI, lendo o valor atual, e não com CASE no UPDATE: o
    // MySQL avalia as atribuições da esquerda para a direita usando as colunas
    // já alteradas, o SQLite usa as originais, e um CASE que compara uma coluna
    // com outra atribuída antes daria resultados diferentes nos dois bancos.
    $stmt = $db->prepare(
        'SELECT last_message_at, last_inbound_at, service_window_expires_at
           FROM whatsapp_conversations WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$conversationId]);
    $atual = $stmt->fetch();
    $atual = is_array($atual) ? $atual : [];

    $campos = ['updated_at = ?'];
    $valores = [$now];

    if (waInstantIsNotOlder($messageAt, $atual['last_message_at'] ?? null)) {
        $campos[] = 'last_message_at = ?';
        $valores[] = $messageAt;
        $campos[] = 'last_message_preview = ?';
        $valores[] = waPreview($mensagem['body'] ?? null, $mensagem['type'] ?? null);
        $campos[] = 'last_message_direction = ?';
        $valores[] = $direction;
    }

    if ($direction === 'incoming') {
        // Receber reabre a conversa e soma nas não lidas, mesmo atrasada: é uma
        // mensagem que a equipe ainda não viu.
        $campos[] = "status = 'open'";
        $campos[] = 'unread_count = unread_count + 1';

        if (waInstantIsNotOlder($messageAt, $atual['last_inbound_at'] ?? null)) {
            $campos[] = 'last_inbound_at = ?';
            $valores[] = $messageAt;
        }

        $novaJanela = gmdate('Y-m-d H:i:s', strtotime($messageAt . ' UTC') + WA_SERVICE_WINDOW_SECONDS);
        if (waInstantIsNotOlder($novaJanela, $atual['service_window_expires_at'] ?? null)) {
            $campos[] = 'service_window_expires_at = ?';
            $valores[] = $novaJanela;
        }
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

    $variants = waPhoneVariants($phone);
    $placeholders = implode(',', array_fill(0, count($variants), '?'));

    $stmt = $db->prepare("SELECT * FROM whatsapp_conversations WHERE phone IN ($placeholders) ORDER BY (last_message_at IS NOT NULL) DESC, id ASC LIMIT 1");
    $stmt->execute($variants);
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
    $mapaBusca = [];
    foreach ($phones as $phone) {
        $normalizado = normalizePhone((string) $phone);
        if ($normalizado !== '') {
            foreach (waPhoneVariants($normalizado) as $v) {
                $mapaBusca[$v] = $normalizado;
            }
        }
    }

    if ($mapaBusca === []) {
        return [];
    }

    $lista = array_keys($mapaBusca);
    $marcadores = implode(',', array_fill(0, count($lista), '?'));

    $stmt = $db->prepare(
        'SELECT phone, service_window_expires_at FROM whatsapp_conversations WHERE phone IN (' . $marcadores . ')'
    );
    $stmt->execute($lista);

    $agora = waNow();
    $janelas = [];
    foreach ($stmt->fetchAll() as $linha) {
        $dbPhone = (string) $linha['phone'];
        $original = $mapaBusca[$dbPhone] ?? $dbPhone;
        $estado = waWindowState(
            $linha['service_window_expires_at'] === null ? null : (string) $linha['service_window_expires_at'],
            $agora
        );
        $janelas[$dbPhone] = $estado;
        $janelas[$original] = $estado;
    }

    return $janelas;
}
