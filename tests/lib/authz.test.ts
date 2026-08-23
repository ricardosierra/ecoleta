import { describe, expect, it } from "vitest";
import {
  DASHBOARD_ROLES,
  ROLE_LABELS,
  actorRole,
  assignableRolesOnCreate,
  assignableRolesOnEdit,
  canDeleteUser,
  canEditUser,
  canGeneratePassword,
  canManageGroups,
  canManageUsers,
  canSwitchGroupPanel,
  canViewUserLogs,
  dashboardNavLinks,
  isAdmin,
  isDashboardRole,
  normalizeRole,
  requiresGroup,
} from "@/lib/authz";

/**
 * As regras de papel do dashboard. Um `true` a mais aqui é uma tela de gestão
 * de usuários aberta para quem não deveria vê-la.
 *
 * Espelho de public/api/authz.php — os mesmos casos existem em
 * tests/php/AuthorizationTest.php, do outro lado da rede.
 */

const root = { id: 1, role: "root" };
const master = { id: 2, role: "master" };
const comum = { id: 3, role: "user" };
const outroComum = { id: 4, role: "user" };

describe("normalizeRole", () => {
  it.each(DASHBOARD_ROLES)("reconhece o papel %s", (papel) => {
    expect(normalizeRole(papel)).toBe(papel);
    expect(isDashboardRole(papel)).toBe(true);
  });

  it.each([
    { motivo: "caixa diferente", valor: "Root" },
    { motivo: "espaço nas bordas", valor: " root " },
    { motivo: "papel que não existe", valor: "admin" },
    { motivo: "string vazia", valor: "" },
    { motivo: "nulo", valor: null },
    { motivo: "ausente", valor: undefined },
    { motivo: "número", valor: 1 },
    { motivo: "objeto", valor: { role: "root" } },
  ])("recusa papel com $motivo", ({ valor }) => {
    expect(normalizeRole(valor)).toBeNull();
    expect(isDashboardRole(valor)).toBe(false);
  });

  it("não normaliza caixa nem espaço — seria mais permissivo que o backend", () => {
    // O PHP compara com === contra 'root'. Aceitar 'Root' aqui deixaria a tela
    // aberta para uma sessão que a API recusaria.
    expect(isAdmin({ id: 9, role: "Root" })).toBe(false);
    expect(isAdmin({ id: 9, role: "MASTER" })).toBe(false);
  });
});

describe("actorRole", () => {
  it("devolve null para sessão ausente", () => {
    expect(actorRole(null)).toBeNull();
    expect(actorRole(undefined)).toBeNull();
    expect(actorRole({})).toBeNull();
  });
});

describe("acesso às áreas administrativas", () => {
  it("root e master administram usuários e grupos", () => {
    for (const ator of [root, master]) {
      expect(canManageUsers(ator)).toBe(true);
      expect(canManageGroups(ator)).toBe(true);
      expect(canSwitchGroupPanel(ator)).toBe(true);
      expect(isAdmin(ator)).toBe(true);
    }
  });

  it("user não administra usuários nem grupos", () => {
    expect(canManageUsers(comum)).toBe(false);
    expect(canManageGroups(comum)).toBe(false);
    expect(canSwitchGroupPanel(comum)).toBe(false);
    expect(isAdmin(comum)).toBe(false);
  });

  it("sessão ausente não administra nada", () => {
    for (const ator of [null, undefined, {}, { id: 5, role: "desconhecido" }]) {
      expect(canManageUsers(ator)).toBe(false);
      expect(canManageGroups(ator)).toBe(false);
      expect(canSwitchGroupPanel(ator)).toBe(false);
    }
  });
});

describe("dashboardNavLinks", () => {
  it("mostra Painel, Usuários e Grupos para root e master", () => {
    for (const ator of [root, master]) {
      expect(dashboardNavLinks(ator).map((l) => l.label)).toEqual([
        "Painel",
        "Usuários",
        "Grupos",
      ]);
    }
  });

  it("mostra só o Painel para user", () => {
    expect(dashboardNavLinks(comum).map((l) => l.href)).toEqual(["/dashboard"]);
  });

  it("mostra só o Painel para sessão sem papel conhecido", () => {
    expect(dashboardNavLinks(null).map((l) => l.href)).toEqual(["/dashboard"]);
    expect(dashboardNavLinks({ id: 7, role: "Root" }).map((l) => l.href)).toEqual(["/dashboard"]);
  });
});

describe("ações sobre uma conta", () => {
  it("root age sobre qualquer conta", () => {
    for (const alvo of [master, comum, { id: 8, role: "root" }]) {
      expect(canEditUser(root, alvo)).toBe(true);
      expect(canGeneratePassword(root, alvo)).toBe(true);
    }
  });

  it("master age apenas sobre contas user", () => {
    expect(canEditUser(master, comum)).toBe(true);
    expect(canGeneratePassword(master, comum)).toBe(true);

    for (const alvo of [root, { id: 9, role: "master" }]) {
      expect(canEditUser(master, alvo)).toBe(false);
      expect(canGeneratePassword(master, alvo)).toBe(false);
      expect(canDeleteUser(master, alvo)).toBe(false);
    }
  });

  it("user não age sobre conta nenhuma, nem sobre a própria", () => {
    for (const alvo of [root, master, outroComum, comum]) {
      expect(canEditUser(comum, alvo)).toBe(false);
      expect(canGeneratePassword(comum, alvo)).toBe(false);
      expect(canDeleteUser(comum, alvo)).toBe(false);
    }
  });

  it("ninguém exclui a própria conta ativa", () => {
    expect(canDeleteUser(root, { id: root.id, role: "root" })).toBe(false);
    expect(canDeleteUser(master, { id: master.id, role: "user" })).toBe(false);
  });

  it("root exclui as outras contas", () => {
    expect(canDeleteUser(root, master)).toBe(true);
    expect(canDeleteUser(root, comum)).toBe(true);
  });

  it("alvo com papel desconhecido é negado para master e permitido para root", () => {
    const estranho = { id: 20, role: "superuser" };

    expect(canEditUser(master, estranho)).toBe(false);
    // root já pode tudo; o servidor ainda revalida o papel enviado.
    expect(canEditUser(root, estranho)).toBe(true);
  });
});

describe("canViewUserLogs", () => {
  it("root e master veem o histórico de qualquer conta", () => {
    expect(canViewUserLogs(root, 99)).toBe(true);
    expect(canViewUserLogs(master, 99)).toBe(true);
  });

  it("user vê apenas o próprio histórico", () => {
    expect(canViewUserLogs(comum, comum.id)).toBe(true);
    expect(canViewUserLogs(comum, outroComum.id)).toBe(false);
  });

  it("aceita o id vindo da querystring como string", () => {
    expect(canViewUserLogs(comum, "3")).toBe(true);
    expect(canViewUserLogs(comum, "4")).toBe(false);
  });

  it("recusa id não numérico e sessão sem papel", () => {
    expect(canViewUserLogs(comum, "abc")).toBe(false);
    expect(canViewUserLogs(comum, null)).toBe(false);
    expect(canViewUserLogs(null, 3)).toBe(false);
  });
});

describe("papéis oferecidos nos formulários", () => {
  it("root cria master e user, nunca root", () => {
    expect(assignableRolesOnCreate(root)).toEqual(["user", "master"]);
  });

  it("master cria apenas user", () => {
    expect(assignableRolesOnCreate(master)).toEqual(["user"]);
  });

  it("user não cria ninguém", () => {
    expect(assignableRolesOnCreate(comum)).toEqual([]);
    expect(assignableRolesOnCreate(null)).toEqual([]);
  });

  it("root promove a qualquer papel ao editar", () => {
    expect(assignableRolesOnEdit(root, comum)).toEqual(["user", "master", "root"]);
  });

  it("master não promove ninguém: a única opção é manter user", () => {
    expect(assignableRolesOnEdit(master, comum)).toEqual(["user"]);
  });

  it("sem permissão de editar, não há papel a oferecer", () => {
    expect(assignableRolesOnEdit(master, root)).toEqual([]);
    expect(assignableRolesOnEdit(comum, outroComum)).toEqual([]);
  });
});

describe("requiresGroup", () => {
  it("exige grupo só para user", () => {
    expect(requiresGroup("user")).toBe(true);
    expect(requiresGroup("master")).toBe(false);
    expect(requiresGroup("root")).toBe(false);
    expect(requiresGroup("")).toBe(false);
  });
});

describe("ROLE_LABELS", () => {
  it("tem rótulo para todo papel conhecido", () => {
    for (const papel of DASHBOARD_ROLES) {
      expect(ROLE_LABELS[papel]).toBeTruthy();
    }
  });
});
