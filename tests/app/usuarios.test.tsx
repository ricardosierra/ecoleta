import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import UsuariosPage from "@/app/dashboard/configuracoes/usuarios/page";
import ConfiguracoesLayout from "@/app/dashboard/configuracoes/layout";
import { installApiMock, sessionOf } from "../support/api-mock";

const ME = "/api/auth/me.php";
const USERS = "/api/users/index.php";
const GROUPS = "/api/groups/index.php";

vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => "/dashboard/configuracoes/usuarios",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

const usuarios = {
  ok: true,
  users: [
    {
      id: 7,
      login: "conta-root",
      email: "root@exemplo.com",
      role: "root",
      group_id: null,
      group_name: null,
      force_password_change: false,
      created_at: "2026-01-01 10:00:00",
      last_login: "2026-09-01 10:30:00",
    },
    {
      id: 12,
      login: "maria.silva",
      email: "maria@exemplo.com",
      role: "user",
      group_id: 1,
      group_name: "Operação",
      force_password_change: true,
      created_at: "2026-02-01 10:00:00",
      last_login: null,
    },
  ],
};

const grupos = { ok: true, groups: [{ id: 1, name: "Operação" }, { id: 2, name: "Diretoria" }] };

function montar() {
  installApiMock({
    [ME]: { body: sessionOf("root", { id: 7 }) },
    [USERS]: { body: usuarios },
    [GROUPS]: { body: grupos },
  });

  return render(
    <ConfiguracoesLayout>
      <UsuariosPage />
    </ConfiguracoesLayout>
  );
}

describe("/dashboard/configuracoes/usuarios — lista", () => {
  it("mostra cada conta com e-mail, nível, grupo, status e último acesso", async () => {
    montar();

    // O header também escreve "Olá, conta-root": as buscas por conta ficam
    // dentro da tabela.
    const tabela = await screen.findByRole("table");
    const linha = within(tabela).getByText("maria.silva").closest("tr")!;
    expect(within(linha).getByText("maria@exemplo.com")).toBeInTheDocument();
    expect(within(linha).getByText("Usuário Padrão")).toBeInTheDocument();
    expect(within(linha).getByText("Operação")).toBeInTheDocument();
    expect(within(linha).getByText("Troca de senha pendente")).toBeInTheDocument();
    expect(within(linha).getByText("Nunca acessou")).toBeInTheDocument();

    const propria = within(tabela).getByText("conta-root").closest("tr")!;
    expect(within(propria).getByText("você")).toBeInTheDocument();
    expect(within(propria).getByText("Último acesso 01/09/2026 10:30")).toBeInTheDocument();
    // Ninguém exclui a própria conta: o botão nem é desenhado.
    expect(within(propria).queryByRole("button", { name: /Excluir/ })).toBeNull();
    expect(within(linha).getByRole("button", { name: "Excluir maria.silva" })).toBeInTheDocument();
  });

  it("a busca filtra por login, e-mail ou grupo", async () => {
    montar();
    const tabela = await screen.findByRole("table");
    await within(tabela).findByText("maria.silva");

    const user = userEvent.setup();
    await user.type(screen.getByRole("searchbox", { name: "Buscar usuário" }), "operação");

    expect(within(tabela).getByText("maria.silva")).toBeInTheDocument();
    expect(within(tabela).queryByText("conta-root")).toBeNull();

    await user.clear(screen.getByRole("searchbox", { name: "Buscar usuário" }));
    await user.type(screen.getByRole("searchbox", { name: "Buscar usuário" }), "ninguém");
    expect(screen.getByText(/Nenhum usuário encontrado para/)).toBeInTheDocument();
  });

  it("o botão de editar abre o modal já preenchido", async () => {
    montar();
    await screen.findByRole("button", { name: "Editar maria.silva" });

    const user = userEvent.setup();
    await user.click(screen.getByRole("button", { name: "Editar maria.silva" }));

    const dialogo = screen.getByRole("dialog", { name: "Editar Usuário" });
    expect(within(dialogo).getByDisplayValue("maria.silva")).toBeInTheDocument();
    expect(within(dialogo).getByDisplayValue("maria@exemplo.com")).toBeInTheDocument();
    expect(within(dialogo).getByRole("combobox", { name: "Grupo (Power BI)" })).toHaveValue("1");

    await user.keyboard("{Escape}");
    expect(screen.queryByRole("dialog")).toBeNull();
  });
});
