import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { DashboardHeader } from "@/components/DashboardHeader";

/**
 * O cabeçalho é o único caminho de navegação para as áreas administrativas.
 * Se ele mostrar "Usuários" e "Grupos" para um papel `user`, a pessoa clica e
 * bate numa tela que não deveria nem existir para ela.
 */

const linkAdministrativo = ["Usuários", "Grupos"] as const;

function renderHeader(user?: { id: number; login: string; role: string }) {
  return render(<DashboardHeader onLogout={vi.fn()} user={user} />);
}

describe("DashboardHeader", () => {
  it.each(["root", "master"])("mostra Usuários e Grupos para %s", (role) => {
    renderHeader({ id: 1, login: "admin", role });

    for (const label of linkAdministrativo) {
      expect(screen.getByRole("link", { name: label })).toBeInTheDocument();
    }
  });

  it("esconde Usuários e Grupos de um papel user", () => {
    renderHeader({ id: 3, login: "operacao", role: "user" });

    expect(screen.getByRole("link", { name: "Painel" })).toBeInTheDocument();
    for (const label of linkAdministrativo) {
      expect(screen.queryByRole("link", { name: label })).not.toBeInTheDocument();
    }
  });

  it("esconde as áreas administrativas quando não há sessão", () => {
    renderHeader(undefined);

    for (const label of linkAdministrativo) {
      expect(screen.queryByRole("link", { name: label })).not.toBeInTheDocument();
    }
  });

  it("esconde as áreas administrativas de um papel que a API não deveria enviar", () => {
    renderHeader({ id: 4, login: "estranho", role: "Root" });

    for (const label of linkAdministrativo) {
      expect(screen.queryByRole("link", { name: label })).not.toBeInTheDocument();
    }
  });

  it("aponta cada link administrativo para a rota certa", () => {
    renderHeader({ id: 1, login: "admin", role: "root" });

    expect(screen.getByRole("link", { name: "Usuários" })).toHaveAttribute(
      "href",
      "/dashboard/usuarios"
    );
    expect(screen.getByRole("link", { name: "Grupos" })).toHaveAttribute(
      "href",
      "/dashboard/grupos"
    );
  });
});
