<?php
declare(strict_types=1);

/**
 * Identidade de uma conta: o que pode ser login e quando login e e-mail colidem.
 *
 * O login do dashboard aceita login OU e-mail (`WHERE login = ? OR email = ?`).
 * Isso só é seguro enquanto nenhum texto aponta para duas contas ao mesmo
 * tempo; do contrário quem digitar aquele texto cai em uma delas, sem regra, e
 * a senha de uma conta abre a outra.
 */

const API_LOGIN_MIN_LENGTH = 3;
const API_LOGIN_MAX_LENGTH = 50;

/**
 * Formato aceito para um login NOVO: letras ASCII, números, ponto, hífen e
 * sublinhado, de 3 a 50 caracteres (o limite da coluna `users.login`).
 *
 * ASCII de propósito: o MySQL compara acento e caixa como iguais, então `joão`
 * e `joao` seriam a mesma conta para o banco e duas para quem lê, um par de
 * nomes que se confundem. Sem '@' e sem espaço, o que também impede um login de
 * ter a cara de um e-mail. Quebra de linha e aspas ficam de fora porque o login
 * vai para assunto de e-mail, mensagem de log e HTML.
 *
 * `\z` e não `$`: o `$` do PCRE aceita uma quebra de linha final.
 */
function apiLoginFormatIsValid(string $login): bool
{
    return preg_match('/^[A-Za-z0-9._-]{' . API_LOGIN_MIN_LENGTH . ',' . API_LOGIN_MAX_LENGTH . '}\z/', $login) === 1;
}

/**
 * Outra conta que já ocupa esta identidade, ou `null` se estiver livre.
 *
 * Compara em caixa baixa porque o MySQL da hospedagem compara assim, e olha os
 * quatro cruzamentos: login com login, e-mail com e-mail, e os dois que a busca
 * do login enxerga e as checagens antigas não: login igual ao e-mail de outra
 * conta, e e-mail igual ao login de outra.
 *
 * @param int|null $exceptUserId conta que está sendo editada (não colide consigo mesma)
 * @return array{kind:string, user_id:int}|null kind: login | email | login_is_email | email_is_login
 */
function apiIdentityClash(PDO $db, string $login, ?string $email, ?int $exceptUserId = null): ?array
{
    $stmt = $db->prepare('
        SELECT id, login, email
        FROM users
        WHERE id != ?
          AND (LOWER(login) = LOWER(?) OR LOWER(email) = LOWER(?) OR LOWER(login) = LOWER(?) OR LOWER(email) = LOWER(?))
        ORDER BY id
    ');
    // Com e-mail nulo, `LOWER(email) = LOWER(NULL)` é NULL e nunca casa: a
    // checagem de e-mail simplesmente não participa.
    $stmt->execute([$exceptUserId ?? 0, $login, $email, $email, $login]);

    $loginLower = strtolower($login);
    $emailLower = $email === null ? null : strtolower($email);

    // O primeiro cruzamento encontrado manda, na ordem de gravidade da mensagem.
    $found = null;
    foreach ($stmt->fetchAll() as $row) {
        $otherLogin = strtolower((string) $row['login']);
        $otherEmail = $row['email'] === null ? null : strtolower((string) $row['email']);

        if ($otherLogin === $loginLower) {
            return ['kind' => 'login', 'user_id' => (int) $row['id']];
        }
        if ($emailLower !== null && $otherEmail === $emailLower) {
            $found ??= ['kind' => 'email', 'user_id' => (int) $row['id']];
        } elseif ($otherEmail === $loginLower) {
            $found ??= ['kind' => 'login_is_email', 'user_id' => (int) $row['id']];
        } elseif ($emailLower !== null && $otherLogin === $emailLower) {
            $found ??= ['kind' => 'email_is_login', 'user_id' => (int) $row['id']];
        }
    }

    return $found;
}

/** Mensagem ao operador para cada tipo de colisão de apiIdentityClash(). */
function apiIdentityClashMessage(string $kind): string
{
    return match ($kind) {
        'login' => 'Este login já está em uso por outro usuário.',
        'email' => 'Este e-mail já está em uso por outro usuário.',
        'login_is_email' => 'Este login é igual ao e-mail de outro usuário. Escolha outro login.',
        default => 'Este e-mail é igual ao login de outro usuário. Informe outro e-mail.',
    };
}
