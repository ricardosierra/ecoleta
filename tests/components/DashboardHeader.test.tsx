import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { DashboardHeader } from "@/components/DashboardHeader";

const linkAdministrativo = ["Configurações"] as const;

function renderHeader(user?: { id: number; login: string; role: string }) {
  return render(<DashboardHeader onLogout={vi.fn()} user={user} />);
}

describe("DashboardHeader", () => {
  it.each(["root", "master"])("mostra Configurações para %s", (role) => {
    renderHeader({ id: 1, login: "admin", role });

    for (const label of linkAdministrativo) {
      expect(screen.getByRole("link", { name: label })).toBeInTheDocument();
    }
  });

  it("esconde Configurações de um papel user", () => {
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

    expect(screen.getByRole("link", { name: "Configurações" })).toHaveAttribute(
      "href",
      "/dashboard/configuracoes"
    );
  });
});

describe("DashboardHeader — menu do celular", () => {
  it("abre com os mesmos links e o botão Sair, e fecha pelo mesmo botão", async () => {
    const { default: userEvent } = await import("@testing-library/user-event");
    const onLogout = vi.fn();
    render(<DashboardHeader onLogout={onLogout} user={{ id: 1, login: "admin", role: "root" }} />);

    // Fechado: os links existem uma vez só (a navegação de tela grande).
    expect(screen.getAllByRole("link", { name: "Configurações" })).toHaveLength(1);
    expect(document.getElementById("dashboard-mobile-menu")).toBeNull();

    const user = userEvent.setup();
    await user.click(screen.getByRole("button", { name: "Abrir menu" }));

    const menu = document.getElementById("dashboard-mobile-menu");
    expect(menu).not.toBeNull();
    const { within } = await import("@testing-library/react");
    expect(within(menu!).getByRole("link", { name: "Configurações" })).toHaveAttribute("href", "/dashboard/configuracoes");
    expect(within(menu!).getByRole("link", { name: "OS Eletrônica" })).toBeInTheDocument();

    await user.click(within(menu!).getByRole("button", { name: "Sair" }));
    expect(onLogout).toHaveBeenCalledTimes(1);

    await user.click(screen.getByRole("button", { name: "Fechar menu" }));
    expect(document.getElementById("dashboard-mobile-menu")).toBeNull();
  });
});
