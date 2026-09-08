import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it, vi } from "vitest";
import { DashboardModal } from "@/components/DashboardModal";

/** Tela mínima com o gatilho fora do diálogo, como nas telas do painel. */
function TelaComModal() {
  const [aberto, setAberto] = useState(false);

  return (
    <>
      <button type="button" onClick={() => setAberto(true)}>
        Abrir
      </button>
      {aberto && (
        <DashboardModal title="Confirmar" onClose={() => setAberto(false)}>
          <button type="button">Primeiro</button>
          <button type="button">Último</button>
        </DashboardModal>
      )}
    </>
  );
}

describe("DashboardModal", () => {
  it("prende o Tab dentro do diálogo, nos dois sentidos", async () => {
    const user = userEvent.setup();
    render(<TelaComModal />);
    await user.click(screen.getByRole("button", { name: "Abrir" }));

    const fechar = screen.getByRole("button", { name: "Fechar" });
    const primeiro = screen.getByRole("button", { name: "Primeiro" });
    const ultimo = screen.getByRole("button", { name: "Último" });

    // Do painel, o primeiro Tab entra na sequência do diálogo.
    await user.tab();
    expect(fechar).toHaveFocus();
    await user.tab();
    expect(primeiro).toHaveFocus();
    await user.tab();
    expect(ultimo).toHaveFocus();

    // No fim da sequência o foco volta ao começo, sem escapar para a página.
    await user.tab();
    expect(fechar).toHaveFocus();

    // E Shift+Tab a partir do começo vai para o fim, não para o botão "Abrir".
    await user.tab({ shift: true });
    expect(ultimo).toHaveFocus();
  });

  it("devolve o foco a quem abriu quando fecha", async () => {
    const user = userEvent.setup();
    render(<TelaComModal />);
    const abrir = screen.getByRole("button", { name: "Abrir" });

    await user.click(abrir);
    expect(abrir).not.toHaveFocus();

    await user.click(screen.getByRole("button", { name: "Fechar" }));

    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(abrir).toHaveFocus();
  });

  it("Esc fecha o diálogo", async () => {
    const onClose = vi.fn();
    const user = userEvent.setup();
    render(
      <DashboardModal title="Confirmar" onClose={onClose}>
        <p>corpo</p>
      </DashboardModal>
    );

    await user.keyboard("{Escape}");

    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
