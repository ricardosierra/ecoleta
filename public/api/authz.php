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
 */

require_once __DIR__ . '/security.php';

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

/** Administradores veem o histórico de qualquer conta; os demais, só o próprio. */
function apiRoleCanViewUserLogs(?string $actorRole, int $actorId, int $targetId): bool
{
    if (apiNormalizeRole($actorRole) === null) {
        return false;
    }

    return apiRoleIsAdmin($actorRole) || $actorId === $targetId;
}

/**
 * Quem está agindo, lido da sessão. `null` quando não há sessão autenticada ou
 * quando o papel guardado não é conhecido — uma sessão com papel estranho é
 * tratada como não autenticada.
 */
function apiSessionActor(): ?array
{
    $id = $_SESSION['user_id'] ?? null;
    $role = apiNormalizeRole($_SESSION['role'] ?? null);

    if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
        return null;
    }

    if ($role === null) {
        return null;
    }

    return [
        'id' => (int) $id,
        'role' => $role,
        'login' => is_string($_SESSION['login'] ?? null) ? $_SESSION['login'] : 'admin',
    ];
}

/**
 * Exige uma sessão autenticada. Encerra a requisição com 401 quando não há.
 *
 * @return array{id:int,role:string,login:string}
 */
function apiRequireAuthenticated(): array
{
    $actor = apiSessionActor();
    if ($actor === null) {
        apiJsonResponse(401, ['error' => 'Não autenticado.']);
    }

    return $actor;
}

/**
 * Exige `root` ou `master`. Encerra a requisição com 403 caso contrário.
 *
 * O 403 é o mesmo para sessão ausente e para papel insuficiente — de propósito:
 * a resposta não diz se o problema foi "não logado" ou "logado sem permissão".
 *
 * @return array{id:int,role:string,login:string}
 */
function apiRequireAdmin(): array
{
    $actor = apiSessionActor();

    if ($actor === null || !apiRoleIsAdmin($actor['role'])) {
        apiJsonResponse(403, ['error' => API_ACCESS_DENIED]);
    }

    return $actor;
}
