import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import UsuariosPage from "@/app/dashboard/configuracoes/usuarios/page";
import GruposPage from "@/app/dashboard/configuracoes/grupos/page";
import ConfiguracoesLayout from "@/app/dashboard/configuracoes/layout";
import { installApiMock, sessionOf } from "../support/api-mock";

const ME = "/api/auth/me.php";
const USERS = "/api/users/index.php";
const GROUPS = "/api/groups/index.php";
const LOGS = "/api/users/logs.php";

const listaVazia = { status: 200, body: { ok: true, users: [] } };
const gruposVazios = { status: 200, body: { ok: true, groups: [] } };

const useSearchParamsMock = vi.fn(() => new URLSearchParams("id=7"));

vi.mock("next/navigation", () => ({
  useSearchParams: () => useSearchParamsMock(),
  usePathname: () => "/dashboard/configuracoes/usuarios",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

beforeEach(() => {
  useSearchParamsMock.mockReturnValue(new URLSearchParams("id=7"));
});

function renderWithLayout(ui: React.ReactNode) {
  return render(<ConfiguracoesLayout>{ui}</ConfiguracoesLayout>);
}

describe("/dashboard/usuarios", () => {
  it.each(["root", "master"])("monta a gestão de usuários para %s", async (role) => {
    installApiMock({
      [ME]: { body: sessionOf(role) },
      [USERS]: listaVazia,
      [GROUPS]: gruposVazios,
    });

    renderWithLayout(<UsuariosPage />);

    expect(await screen.findByRole("heading", { name: "Gerenciar Usuários" })).toBeVisible();
    expect(screen.queryByText("Acesso Restrito")).not.toBeInTheDocument();
  });

  it("recusa um papel user antes de renderizar a gestão", async () => {
    installApiMock({
      [ME]: { body: sessionOf("user") },
      [USERS]: listaVazia,
      [GROUPS]: gruposVazios,
    });

    renderWithLayout(<UsuariosPage />);

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

    renderWithLayout(<UsuariosPage />);
    await screen.findByText("Acesso Restrito");

    expect(api.requested(USERS)).toBe(false);
    expect(api.requested(GROUPS)).toBe(false);
    expect(api.calls).toEqual([ME]);
  });
});

describe("/dashboard/grupos", () => {
  it.each(["root", "master"])("monta a gestão de grupos para %s", async (role) => {
    installApiMock({
      [ME]: { body: sessionOf(role) },
      [GROUPS]: gruposVazios,
    });

    renderWithLayout(<GruposPage />);

    expect(await screen.findByRole("heading", { name: /Gestão de Grupos/ })).toBeVisible();
  });

  it("recusa um papel user e não pede a lista de grupos", async () => {
    const api = installApiMock({
      [ME]: { body: sessionOf("user") },
      [GROUPS]: gruposVazios,
    });

    renderWithLayout(<GruposPage />);

    expect(await screen.findByText("Acesso Restrito")).toBeVisible();
    expect(api.requested(GROUPS)).toBe(false);
  });
});

describe("/dashboard/usuarios/ver", () => {
  it("recusa um papel user consultando o próprio id", async () => {
    const { default: VerUsuarioPage } = await import("@/app/dashboard/configuracoes/usuarios/ver/page");

    const api = installApiMock({
      [ME]: { body: sessionOf("user", { id: 7 }) },
      [LOGS]: { status: 200, body: { ok: true, user: { id: 7, login: "conta-user", role: "user" }, logs: [] } },
      [GROUPS]: gruposVazios,
    });

    renderWithLayout(<VerUsuarioPage />);

    expect(await screen.findByText("Acesso Restrito")).toBeVisible();
    expect(api.requested(LOGS)).toBe(false);
  });

  it("monta o histórico para root", async () => {
    const { default: VerUsuarioPage } = await import("@/app/dashboard/configuracoes/usuarios/ver/page");

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

    renderWithLayout(<VerUsuarioPage />);

    expect(await screen.findByText("conta-user")).toBeVisible();
    expect(screen.queryByText("Acesso Restrito")).not.toBeInTheDocument();
  });
});
