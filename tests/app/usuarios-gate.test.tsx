import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import UsuariosPage from "@/app/dashboard/usuarios/page";
import GruposPage from "@/app/dashboard/grupos/page";
import { installApiMock, sessionOf } from "../support/api-mock";

/**
 * As três telas administrativas montadas de verdade, com a sessão vindo de
 * `api/auth/me.php` como no navegador.
 *
 * O que se cobra aqui é o que a P08 encontrou aberto: as telas de usuários
 * renderizavam a gestão para qualquer sessão autenticada e dependiam do 403 da
 * API para não mostrar dados. A recusa precisa acontecer antes do render — e
 * antes da chamada.
 */

const ME = "/api/auth/me.php";
const USERS = "/api/users/index.php";
const GROUPS = "/api/groups/index.php";
const LOGS = "/api/users/logs.php";

const listaVazia = { status: 200, body: { ok: true, users: [] } };
const gruposVazios = { status: 200, body: { ok: true, groups: [] } };

const useSearchParamsMock = vi.fn(() => new URLSearchParams("id=7"));

vi.mock("next/navigation", () => ({
  useSearchParams: () => useSearchParamsMock(),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

beforeEach(() => {
  useSearchParamsMock.mockReturnValue(new URLSearchParams("id=7"));
});

describe("/dashboard/usuarios", () => {
  it.each(["root", "master"])("monta a gestão de usuários para %s", async (role) => {
    installApiMock({
      [ME]: { body: sessionOf(role) },
      [USERS]: listaVazia,
      [GROUPS]: gruposVazios,
    });

    render(<UsuariosPage />);

    expect(await screen.findByRole("heading", { name: "Gerenciar Usuários" })).toBeVisible();
    expect(screen.queryByText("Acesso Restrito")).not.toBeInTheDocument();
  });

  it("recusa um papel user antes de renderizar a gestão", async () => {
    installApiMock({
      [ME]: { body: sessionOf("user") },
      [USERS]: listaVazia,
      [GROUPS]: gruposVazios,
    });

    render(<UsuariosPage />);

    expect(await screen.findByText("Acesso Restrito")).toBeVisible();
    expect(screen.queryByRole("heading", { name: "Gerenciar Usuários" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Novo Usuário/ })).not.toBeInTheDocument();
  });

  it("não chega a pedir a lista de usuários para um papel user", async () => {
    const api = installApiMock({
      [ME]: { body: sessionOf("user") },
      [USERS]: listaVazia,
      [GROUPS]: gruposVazios,
    });

    render(<UsuariosPage />);
    await screen.findByText("Acesso Restrito");

    expect(api.requested(USERS)).toBe(false);
    expect(api.requested(GROUPS)).toBe(false);
    expect(api.calls).toEqual([ME]);
  });

  it("esconde os links administrativos do cabeçalho para um papel user", async () => {
    installApiMock({ [ME]: { body: sessionOf("user") } });

    render(<UsuariosPage />);
    await screen.findByText("Acesso Restrito");

    expect(screen.queryByRole("link", { name: "Usuários" })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Grupos" })).not.toBeInTheDocument();
  });
});

describe("/dashboard/grupos", () => {
  it.each(["root", "master"])("monta a gestão de grupos para %s", async (role) => {
    installApiMock({
      [ME]: { body: sessionOf(role) },
      [GROUPS]: gruposVazios,
    });

    render(<GruposPage />);

    expect(await screen.findByRole("heading", { name: /Gestão de Grupos/ })).toBeVisible();
  });

  it("recusa um papel user e não pede a lista de grupos", async () => {
    const api = installApiMock({
      [ME]: { body: sessionOf("user") },
      [GROUPS]: gruposVazios,
    });

    render(<GruposPage />);

    expect(await screen.findByText("Acesso Restrito")).toBeVisible();
    expect(api.requested(GROUPS)).toBe(false);
  });
});

describe("/dashboard/usuarios/ver", () => {
  it("recusa um papel user consultando o próprio id", async () => {
    // users/logs.php libera o próprio histórico para qualquer papel: sem o
    // bloqueio na tela, esta requisição voltava 200 e a gestão de contas
    // aparecia montada em cima dos dados da própria pessoa.
    const { default: VerUsuarioPage } = await import("@/app/dashboard/usuarios/ver/page");

    const api = installApiMock({
      [ME]: { body: sessionOf("user", { id: 7 }) },
      [LOGS]: { status: 200, body: { ok: true, user: { id: 7, login: "conta-user", role: "user" }, logs: [] } },
      [GROUPS]: gruposVazios,
    });

    render(<VerUsuarioPage />);

    expect(await screen.findByText("Acesso Restrito")).toBeVisible();
    expect(api.requested(LOGS)).toBe(false);
  });

  it("monta o histórico para root", async () => {
    const { default: VerUsuarioPage } = await import("@/app/dashboard/usuarios/ver/page");

    installApiMock({
      [ME]: { body: sessionOf("root", { id: 1 }) },
      [LOGS]: {
        status: 200,
        body: {
          ok: true,
          user: {
            id: 7,
            login: "conta-user",
            email: "user@exemplo.com",
            role: "user",
            group_id: 1,
            group_name: "Operação",
            created_at: "2026-01-01 10:00:00",
          },
          logs: [],
        },
      },
      [GROUPS]: gruposVazios,
    });

    render(<VerUsuarioPage />);

    expect(await screen.findByText("conta-user")).toBeVisible();
    expect(screen.queryByText("Acesso Restrito")).not.toBeInTheDocument();
  });
});
