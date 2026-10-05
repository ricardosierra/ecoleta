<?php
declare(strict_types=1);

/**
 * Regras de papel do dashboard — o lado que decide de verdade.
 *
 * `lib/authz.ts` tem as mesmas regras no cliente, mas só para escolher o que
 * desenhar. Nenhuma decisão aqui pode depender do que o navegador enviou:
 * papel, id e login saem da sessão, nunca do corpo da requisição.
 *
 * Papéis, do mais para o menos privilegiado:
 *
 *   root   — administra tudo, inclusive outros administradores.
 *   master — administra apenas contas `user`, e a gestão de grupos.
 *   user   — só o próprio painel e o próprio histórico.
 *
 * QUEM É A PESSOA, QUEM DECIDE É O BANCO. A sessão guarda só o id (e, por
 * conveniência, papel e login); a cada requisição autenticada
 * apiRequireAuthenticated() e apiRequireAdmin() reconsultam o usuário por id.
 * Antes o papel era gravado no login e nunca mais conferido, e como o timeout de
 * inatividade renova a sessão a cada requisição, revogar acesso não revogava:
 * master rebaixado seguia administrando, master excluído seguia lendo clientes.
 */

require_once __DIR__ . '/security.php';
// Reconsultar o usuário exige conexão. Todo endpoint que usa este arquivo já
// carrega db.php antes; o require_once aqui só garante isso para quem esquecer.
require_once __DIR__ . '/db.php';

const API_ROLE_ROOT = 'root';
const API_ROLE_MASTER = 'master';
const API_ROLE_USER = 'user';

/** Mensagem única de recusa: não conta ao cliente o que faltou. */
const API_ACCESS_DENIED = 'Acesso negado.';

function apiKnownRoles(): array
{
    return [API_ROLE_ROOT, API_ROLE_MASTER, API_ROLE_USER];
}

/**
 * Papel conhecido ou `null`.
 *
 * Comparação estrita e sem normalização: `'Root'`, `' root '` e `0` viram
 * `null`. Aparar espaço ou baixar caixa aqui aceitaria papéis que o resto do
 * código compara com `===` e trataria como desconhecidos — e `null` nunca
 * autoriza nada.
 */
function apiNormalizeRole($value): ?string
{
    return is_string($value) && in_array($value, apiKnownRoles(), true) ? $value : null;
}

/** `root` ou `master`. */
function apiRoleIsAdmin(?string $role): bool
{
    $role = apiNormalizeRole($role);

    return $role === API_ROLE_ROOT || $role === API_ROLE_MASTER;
}

function apiRoleCanManageUsers(?string $role): bool
{
    return apiRoleIsAdmin($role);
}

function apiRoleCanManageGroups(?string $role): bool
{
    return apiRoleIsAdmin($role);
}

/**
 * Um administrador só age sobre outro administrador se for `root`.
 * `master` age exclusivamente sobre contas `user`.
 */
function apiRoleCanActOnUser(?string $actorRole, ?string $targetRole): bool
{
    $actorRole = apiNormalizeRole($actorRole);

    if ($actorRole === API_ROLE_ROOT) {
        return true;
    }

    if ($actorRole === API_ROLE_MASTER) {
        return apiNormalizeRole($targetRole) === API_ROLE_USER;
    }

    return false;
}

/** Editar e gerar senha seguem exatamente a mesma regra. */
function apiRoleCanEditUser(?string $actorRole, ?string $targetRole): bool
{
    return apiRoleCanActOnUser($actorRole, $targetRole);
}

function apiRoleCanGeneratePassword(?string $actorRole, ?string $targetRole): bool
{
    return apiRoleCanActOnUser($actorRole, $targetRole);
}

function apiRoleCanTogglePasswordLock(?string $actorRole): bool
{
    return apiNormalizeRole($actorRole) === API_ROLE_ROOT;
}

/**
 * Esta edição pode deixar a troca de senha da conta TRAVADA?
 *
 * Senha temporária e trava nunca ficam juntas: `force_password_change` manda a
 * pessoa trocar a senha e `password_locked` faz auth/change_password.php
 * responder 403, então ela ficaria presa na temporária, que nunca expira.
 * Barra-se apenas ATIVAR a trava (a conta não estava travada e passa a ficar):
 * conta já travada segue editável (quem tenta redefinir a senha dela é
 * recusado em outro ponto, com alerta), e destravar nunca é problema.
 *
 * Espelho de `canTogglePasswordLock(actor, alvo)` em lib/authz.ts, que só decide
 * se o botão aparece.
 *
 * @param bool $currentlyLocked       a conta já está travada
 * @param bool $wantsLocked           a edição pede a conta travada
 * @param bool $resettingPassword     a mesma chamada gera ou define uma senha
 * @param bool $passwordChangePending a conta ainda não trocou a senha temporária
 */
function apiPasswordLockAllowed(
    bool $currentlyLocked,
    bool $wantsLocked,
    bool $resettingPassword,
    bool $passwordChangePending
): bool {
    if (!$wantsLocked || $currentlyLocked) {
        return true;
    }

    return !$resettingPassword && !$passwordChangePending;
}

/** Excluir exige o mesmo que editar, mais a trava de não apagar a si próprio. */
function apiRoleCanDeleteUser(?string $actorRole, int $actorId, ?string $targetRole, int $targetId): bool
{
    if ($actorId === $targetId) {
        return false;
    }

    return apiRoleCanActOnUser($actorRole, $targetRole);
}

/**
 * Papéis que este ator pode atribuir ao CRIAR uma conta.
 * `root` não entra: a conta root nasce uma única vez, por `install.php`.
 */
function apiAssignableRolesOnCreate(?string $actorRole): array
{
    $actorRole = apiNormalizeRole($actorRole);

    if ($actorRole === API_ROLE_ROOT) {
        return [API_ROLE_USER, API_ROLE_MASTER];
    }

    if ($actorRole === API_ROLE_MASTER) {
        return [API_ROLE_USER];
    }

    return [];
}

function apiRoleCanAssignOnCreate(?string $actorRole, $requestedRole): bool
{
    $requested = apiNormalizeRole($requestedRole);

    return $requested !== null && in_array($requested, apiAssignableRolesOnCreate($actorRole), true);
}

/**
 * Papel que uma edição realmente grava.
 *
 * `master` nunca promove ninguém: o resultado é sempre `user`, mesmo que o
 * corpo peça outra coisa. `root` grava o que pediu, e o papel atual quando o
 * pedido não é um papel conhecido.
 *
 * Ninguém muda o próprio papel. `apiRoleCanDeleteUser()` já impede apagar a
 * própria conta, e sem esta trava a mesma perda acontecia pela porta do lado:
 * o único `root` se rebaixava a `user`, a instalação ficava com zero root e
 * `install.php` — autodesativado pelo `.install-lock` — responde 404 desde a
 * criação da conta. A volta só existiria por acesso direto ao banco.
 */
function apiEffectiveRoleOnEdit(
    ?string $actorRole,
    $requestedRole,
    string $currentRole,
    bool $editingSelf = false
): string {
    if ($editingSelf) {
        return $currentRole;
    }

    if (apiNormalizeRole($actorRole) === API_ROLE_MASTER) {
        return API_ROLE_USER;
    }

    return apiNormalizeRole($requestedRole) ?? $currentRole;
}

/** Contas `user` são obrigatoriamente de um grupo. */
function apiRoleRequiresGroup($role): bool
{
    return apiNormalizeRole($role) === API_ROLE_USER;
}

/**
 * Contas que enxergam o painel de conversas do WhatsApp.
 *
 * A lista é curta e fica no código, e não no env, porque a mesma regra precisa
 * existir no cliente (`lib/authz.ts`) para o menu não desenhar um link que a
 * API vai recusar — e um valor de ambiente não atravessa para o navegador. Não
 * é segredo: é o endereço de uma pessoa, e quem decide continua sendo o
 * servidor, que confere o e-mail gravado na conta e não o que o navegador diz.
 *
 * Comparação em caixa baixa: e-mail não diferencia maiúscula de minúscula na
 * parte do domínio, e o cadastro pode ter entrado de qualquer jeito.
 */
const API_WHATSAPP_PANEL_EMAILS = ['sierra.csi@gmail.com'];

/**
 * Painel de WhatsApp: liberado para `master` (cliente) e `root` na lista.
 */
function apiRoleCanViewWhatsAppPanel(?string $role, ?string $email): bool
{
    $role = apiNormalizeRole($role);
    if ($role === API_ROLE_MASTER) {
        return true;
    }

    if ($role !== API_ROLE_ROOT) {
        return false;
    }

    $email = strtolower(trim((string) $email));

    return $email !== '' && in_array($email, API_WHATSAPP_PANEL_EMAILS, true);
}

/** Administradores veem o histórico de qualquer conta; os demais, só o próprio. */
function apiRoleCanViewUserLogs(?string $actorRole, int $actorId, int $targetId): bool
{
    if (apiNormalizeRole($actorRole) === null) {
        return false;
    }

    return apiRoleIsAdmin($actorRole) || $actorId === $targetId;
}

/**
 * Chave da sessão com a impressão digital do hash de senha vigente. Ver
 * apiPasswordFingerprint().
 */
const API_SESSION_PASSWORD_KEY = 'pwd_fp';

/** Código de erro que a tela reconhece como "defina uma senha nova antes". */
const API_PASSWORD_CHANGE_REQUIRED = 'password_change_required';

/**
 * Impressão digital do hash de senha: o que a sessão guarda no lugar do hash.
 *
 * É o que faz trocar a senha derrubar as sessões que já estavam abertas: o
 * hash muda, a impressão não bate mais, a sessão é esvaziada. Sem isto, resetar
 * a senha de uma conta suspeita deixava o invasor logado.
 */
function apiPasswordFingerprint(string $passwordHash): string
{
    return hash('sha256', $passwordHash);
}

/**
 * Amarra a sessão atual ao hash de senha vigente. Chamar no login e logo depois
 * de a PRÓPRIA pessoa trocar a senha (quem trocou não pode ser derrubado pela
 * troca que acabou de fazer).
 */
function apiBindSessionToPassword(string $passwordHash): void
{
    $_SESSION[API_SESSION_PASSWORD_KEY] = apiPasswordFingerprint($passwordHash);
}

/**
 * Quem está agindo, lido da sessão. `null` quando não há sessão autenticada ou
 * quando o papel guardado não é conhecido — uma sessão com papel estranho é
 * tratada como não autenticada.
 *
 * Só LÊ a sessão: não confirma nada no banco. Quem decide é
 * apiRequireAuthenticated()/apiRequireAdmin(), que reconsultam o usuário.
 */
function apiSessionActor(): ?array
{
    $id = apiSessionUserId();
    $role = apiNormalizeRole($_SESSION['role'] ?? null);

    if ($id === null) {
        return null;
    }

    if ($role === null) {
        return null;
    }

    return [
        'id' => $id,
        'role' => $role,
        'login' => is_string($_SESSION['login'] ?? null) ? $_SESSION['login'] : 'admin',
    ];
}

/** Id de usuário guardado na sessão, ou `null` quando não há um id válido. */
function apiSessionUserId(): ?int
{
    $id = $_SESSION['user_id'] ?? null;

    if (is_int($id)) {
        return $id;
    }

    if (is_string($id) && ctype_digit($id)) {
        return (int) $id;
    }

    return null;
}

/**
 * Concilia a linha do banco com a sessão. Pura: não toca em sessão nem em banco.
 *
 * `null` quer dizer "esta sessão não vale mais": a conta não existe, tem papel
 * desconhecido, ou a senha mudou desde que a sessão foi aberta.
 *
 * Sessão sem impressão digital (aberta antes desta regra existir) é ADOTADA, não
 * derrubada: derrubar derrubaria todo mundo que está logado no deploy.
 *
 * @param array<string,mixed>|null $row linha de `users`: id, login, role, password_hash, force_password_change
 * @param mixed $sessionFingerprint o que a sessão tinha em API_SESSION_PASSWORD_KEY
 * @return array{id:int,role:string,login:string,force_password_change:bool,fingerprint:string}|null
 */
function apiReconcileActor(?array $row, $sessionFingerprint): ?array
{
    if ($row === null) {
        return null;
    }

    $role = apiNormalizeRole($row['role'] ?? null);
    if ($role === null) {
        return null;
    }

    $fingerprint = apiPasswordFingerprint((string) ($row['password_hash'] ?? ''));
    if (is_string($sessionFingerprint) && $sessionFingerprint !== '' && !hash_equals($sessionFingerprint, $fingerprint)) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'role' => $role,
        'login' => (string) $row['login'],
        'force_password_change' => !empty($row['force_password_change']),
        'fingerprint' => $fingerprint,
    ];
}

/**
 * Reconsulta o usuário da sessão no banco e a acerta por ele.
 *
 * - conta inexistente, papel desconhecido ou senha trocada desde o login: a
 *   sessão é destruída e o retorno é `null` (quem chama responde como se não
 *   houvesse sessão);
 * - papel ou login mudaram: a sessão é atualizada, e o papel que vale daqui em
 *   diante é o do banco.
 *
 * É um SELECT por id por requisição. Falha fechada: se o banco não responde, a
 * requisição termina em 500, e nunca segue adiante apoiada no que a sessão diz.
 *
 * @return array{id:int,role:string,login:string,force_password_change:bool}|null
 */
function apiResolveSessionActor(int $userId): ?array
{
    $db = getDbConnection();

    try {
        $stmt = $db->prepare('SELECT id, login, role, password_hash, force_password_change FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
    } catch (\Throwable $e) {
        error_log('Falha ao reconsultar o usuário da sessão: ' . $e->getMessage());
        apiJsonResponse(500, ['error' => 'Não foi possível validar a sessão.']);
    }

    $stored = $_SESSION[API_SESSION_PASSWORD_KEY] ?? null;
    $actor = apiReconcileActor(is_array($row) ? $row : null, $stored);

    if ($actor === null) {
        apiDestroySession();

        return null;
    }

    $_SESSION['user_id'] = $actor['id'];
    $_SESSION['role'] = $actor['role'];
    $_SESSION['login'] = $actor['login'];
    $_SESSION[API_SESSION_PASSWORD_KEY] = $actor['fingerprint'];

    unset($actor['fingerprint']);

    return $actor;
}

/**
 * Recusa a requisição enquanto a conta tem senha temporária.
 *
 * A tela de troca já barrava no navegador, mas a API não: quem tem a flag em 1
 * e chama o endpoint direto trabalhava normalmente, e a senha temporária nunca
 * expirava. Só me.php, change_password.php e logout.php ficam de fora.
 */
function apiRefuseWhilePasswordChangePending(array $actor): void
{
    if (!empty($actor['force_password_change'])) {
        apiJsonResponse(403, [
            'error' => 'É necessário definir uma nova senha antes de continuar.',
            'code' => API_PASSWORD_CHANGE_REQUIRED,
        ]);
    }
}

/**
 * Exige uma sessão autenticada. Encerra a requisição com 401 quando não há.
 *
 * O ator devolvido vem do BANCO (ver apiResolveSessionActor()), não do que a
 * sessão guardou no login.
 *
 * @param bool $allowPasswordChangePending só change_password.php passa `true`:
 *        é por onde a senha temporária deixa de existir.
 * @return array{id:int,role:string,login:string,force_password_change:bool}
 */
function apiRequireAuthenticated(bool $allowPasswordChangePending = false): array
{
    $sessionActor = apiSessionActor();
    if ($sessionActor === null) {
        apiJsonResponse(401, ['error' => 'Não autenticado.']);
    }

    $actor = apiResolveSessionActor($sessionActor['id']);
    if ($actor === null) {
        apiJsonResponse(401, ['error' => 'Não autenticado.']);
    }

    if (!$allowPasswordChangePending) {
        apiRefuseWhilePasswordChangePending($actor);
    }

    return $actor;
}

/**
 * Exige `root` ou `master`. Encerra a requisição com 403 caso contrário.
 *
 * O 403 é o mesmo para sessão ausente e para papel insuficiente — de propósito:
 * a resposta não diz se o problema foi "não logado" ou "logado sem permissão".
 *
 * Sessão cujo papel gravado já não é de administrador é recusada sem consultar
 * o banco: ela só pode estar defasada "para baixo" (a pessoa foi promovida
 * depois do login), e recusar é a escolha segura: me.php, que a tela chama ao
 * abrir, traz a sessão para o papel do banco. Já sessão de administrador sempre
 * confere o banco, e é o papel de lá que vale.
 *
 * @return array{id:int,role:string,login:string,force_password_change:bool}
 */
function apiRequireAdmin(): array
{
    $sessionActor = apiSessionActor();

    if ($sessionActor === null || !apiRoleIsAdmin($sessionActor['role'])) {
        apiJsonResponse(403, ['error' => API_ACCESS_DENIED]);
    }

    $actor = apiResolveSessionActor($sessionActor['id']);

    if ($actor === null || !apiRoleIsAdmin($actor['role'])) {
        apiJsonResponse(403, ['error' => API_ACCESS_DENIED]);
    }

    apiRefuseWhilePasswordChangePending($actor);

    return $actor;
}
