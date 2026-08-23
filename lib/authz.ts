/**
 * Regras de autorização do dashboard — módulo puro, sem React e sem rede.
 *
 * Estas mesmas regras existem no backend PHP (`public/api/authz.php`), que é a
 * fonte de verdade: o navegador só decide o que desenhar. Manter a decisão aqui,
 * fora dos componentes de 500–800 linhas, é o que torna possível testá-la —
 * antes ela estava copiada em quatro telas, com uma variação em cada uma.
 *
 * Papéis (do mais para o menos privilegiado):
 *
 *   root   — administra tudo, inclusive outros administradores.
 *   master — administra apenas contas de papel `user` e a gestão de grupos.
 *   user   — só enxerga o próprio painel do Power BI.
 */

export const DASHBOARD_ROLES = ["root", "master", "user"] as const;

export type DashboardRole = (typeof DASHBOARD_ROLES)[number];

/** Rótulos exibidos nos formulários, na língua da interface. */
export const ROLE_LABELS: Record<DashboardRole, string> = {
  root: "Root",
  master: "Master",
  user: "Usuário Padrão",
};

/** O mínimo que uma decisão de permissão precisa saber sobre quem age. */
export type Actor = {
  id?: number | null;
  role?: string | null;
} | null | undefined;

/** O mínimo que uma decisão de permissão precisa saber sobre o alvo. */
export type TargetUser = {
  id?: number | null;
  role?: string | null;
};

export type NavLink = {
  href: string;
  label: string;
};

/**
 * Converte um papel vindo da API para o conjunto conhecido.
 *
 * A comparação é exata de propósito: `"Root"`, `" root "` ou `"admin"` viram
 * `null`, e `null` nunca autoriza nada. O backend também compara com `===`, e
 * normalizar aqui (baixar caixa, aparar espaço) deixaria o cliente mais
 * permissivo que o servidor — exatamente a divergência que abre buraco.
 */
export function normalizeRole(value: unknown): DashboardRole | null {
  return typeof value === "string" && (DASHBOARD_ROLES as readonly string[]).includes(value)
    ? (value as DashboardRole)
    : null;
}

export function isDashboardRole(value: unknown): value is DashboardRole {
  return normalizeRole(value) !== null;
}

/** Papel efetivo do ator. `null` para não autenticado ou papel desconhecido. */
export function actorRole(actor: Actor): DashboardRole | null {
  return normalizeRole(actor?.role);
}

/** `root` ou `master`. É o corte que separa "administra" de "só consome". */
export function isAdmin(actor: Actor): boolean {
  const role = actorRole(actor);

  return role === "root" || role === "master";
}

export function isRoot(actor: Actor): boolean {
  return actorRole(actor) === "root";
}

export function isMaster(actor: Actor): boolean {
  return actorRole(actor) === "master";
}

/** Acesso à tela de gestão de usuários (`/dashboard/usuarios` e `.../ver`). */
export function canManageUsers(actor: Actor): boolean {
  return isAdmin(actor);
}

/** Acesso à tela de gestão de grupos (`/dashboard/grupos`). */
export function canManageGroups(actor: Actor): boolean {
  return isAdmin(actor);
}

/** Seletor de grupos no topo do painel — só quem enxerga mais de um grupo. */
export function canSwitchGroupPanel(actor: Actor): boolean {
  return isAdmin(actor);
}

/**
 * Um administrador só age sobre outro administrador se for `root`.
 * `master` age exclusivamente sobre contas `user`.
 */
function canActOnTarget(actor: Actor, target: TargetUser): boolean {
  const role = actorRole(actor);
  if (role === "root") {
    return true;
  }
  if (role === "master") {
    return normalizeRole(target.role) === "user";
  }

  return false;
}

export function canEditUser(actor: Actor, target: TargetUser): boolean {
  return canActOnTarget(actor, target);
}

export function canGeneratePassword(actor: Actor, target: TargetUser): boolean {
  return canActOnTarget(actor, target);
}

/**
 * Excluir exige tudo o que editar exige, mais uma trava: ninguém apaga a
 * própria conta ativa (o backend também recusa, em `users/delete.php`).
 */
export function canDeleteUser(actor: Actor, target: TargetUser): boolean {
  if (!canActOnTarget(actor, target)) {
    return false;
  }

  const actorId = actor?.id;
  const targetId = target.id;

  return !(typeof actorId === "number" && typeof targetId === "number" && actorId === targetId);
}

/**
 * Histórico de atividades: administradores veem o de qualquer conta, os demais
 * apenas o próprio (`users/logs.php` aplica a mesma regra).
 */
export function canViewUserLogs(actor: Actor, targetUserId: unknown): boolean {
  if (!actorRole(actor)) {
    return false;
  }
  if (isAdmin(actor)) {
    return true;
  }

  const actorId = actor?.id;
  const targetId = typeof targetUserId === "string" ? Number(targetUserId) : targetUserId;

  return (
    typeof actorId === "number" &&
    typeof targetId === "number" &&
    Number.isFinite(targetId) &&
    actorId === targetId
  );
}

/**
 * Papéis oferecidos no formulário de criação, na ordem em que aparecem no
 * `<select>` — do menos para o mais privilegiado.
 *
 * `root` nunca aparece: a conta root nasce por `api/install.php`, uma vez só.
 * `users/index.php` recusa qualquer POST com `role: "root"`.
 */
export function assignableRolesOnCreate(actor: Actor): DashboardRole[] {
  const role = actorRole(actor);
  if (role === "root") {
    return ["user", "master"];
  }
  if (role === "master") {
    return ["user"];
  }

  return [];
}

/**
 * Papéis oferecidos ao editar uma conta existente. `root` só entra aqui — um
 * root pode promover outra conta a root, mas isso não é oferecido na criação.
 */
export function assignableRolesOnEdit(actor: Actor, target: TargetUser): DashboardRole[] {
  if (!canEditUser(actor, target)) {
    return [];
  }
  if (isRoot(actor)) {
    return ["user", "master", "root"];
  }

  return ["user"];
}

/** Um papel `user` obrigatoriamente pertence a um grupo. */
export function requiresGroup(role: unknown): boolean {
  return normalizeRole(role) === "user";
}

/** Itens de navegação do cabeçalho, na ordem em que aparecem. */
export function dashboardNavLinks(actor: Actor): NavLink[] {
  const links: NavLink[] = [{ href: "/dashboard", label: "Painel" }];

  if (canManageUsers(actor)) {
    links.push({ href: "/dashboard/usuarios", label: "Usuários" });
  }
  if (canManageGroups(actor)) {
    links.push({ href: "/dashboard/grupos", label: "Grupos" });
  }

  return links;
}
