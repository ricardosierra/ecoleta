import { fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import { DashboardGate } from "@/components/DashboardGate";
import { installApiMock, sessionOf } from "../support/api-mock";

const ME = "/api/auth/me.php";
const CHANGE_PASSWORD = "/api/auth/change_password.php";

describe("DashboardGate - Troca de senha e visibilidade", () => {
  it("exibe campos de nova senha e confirmação com alternância de visibilidade", async () => {
    installApiMock({
      [ME]: { body: sessionOf("root", { force_password_change: true }) },
    });

    render(<DashboardGate><div>Conteúdo Protegido</div></DashboardGate>);

    expect(await screen.findByRole("heading", { name: "Definir Nova Senha" })).toBeVisible();

    const newPassInput = screen.getByLabelText("Nova Senha");
    const confirmPassInput = screen.getByLabelText("Confirmar Nova Senha");

    expect(newPassInput).toHaveAttribute("type", "password");
    expect(confirmPassInput).toHaveAttribute("type", "password");

    const toggleButtons = screen.getAllByRole("button", { name: /Ver senha|Ver confirmação de senha/ });
    expect(toggleButtons).toHaveLength(2);

    // Clica para mostrar a nova senha
    fireEvent.click(toggleButtons[0]);
    expect(newPassInput).toHaveAttribute("type", "text");

    // Clica para ocultar novamente
    fireEvent.click(screen.getByRole("button", { name: "Ocultar senha" }));
    expect(newPassInput).toHaveAttribute("type", "password");

    // Clica para mostrar a confirmação
    fireEvent.click(toggleButtons[1]);
    expect(confirmPassInput).toHaveAttribute("type", "text");
  });

  it("recusa envio quando confirmação não confere e não chama o backend", async () => {
    const user = userEvent.setup();
    const api = installApiMock({
      [ME]: { body: sessionOf("root", { force_password_change: true }) },
      [CHANGE_PASSWORD]: { body: { ok: true, csrf_token: "b".repeat(64) } },
    });

    render(<DashboardGate><div>Conteúdo Protegido</div></DashboardGate>);

    await screen.findByRole("heading", { name: "Definir Nova Senha" });

    const newPassInput = screen.getByLabelText("Nova Senha");
    const confirmPassInput = screen.getByLabelText("Confirmar Nova Senha");

    await user.type(newPassInput, "senhaValida123");
    await user.type(confirmPassInput, "senhaDiferente456");

    await user.click(screen.getByRole("button", { name: "Salvar nova senha" }));

    expect(await screen.findByText("A confirmação não confere com a nova senha digitada.")).toBeVisible();
    expect(api.requested(CHANGE_PASSWORD)).toBe(false);
  });

  it("permite salvar quando as senhas coincidem", async () => {
    const user = userEvent.setup();
    const api = installApiMock({
      [ME]: { body: sessionOf("root", { force_password_change: true }) },
      [CHANGE_PASSWORD]: { body: { ok: true, csrf_token: "b".repeat(64) } },
    });

    render(<DashboardGate><div>Conteúdo Protegido</div></DashboardGate>);

    await screen.findByRole("heading", { name: "Definir Nova Senha" });

    await user.type(screen.getByLabelText("Nova Senha"), "minhaSenhaForte123");
    await user.type(screen.getByLabelText("Confirmar Nova Senha"), "minhaSenhaForte123");

    await user.click(screen.getByRole("button", { name: "Salvar nova senha" }));

    expect(api.requested(CHANGE_PASSWORD)).toBe(true);
  });
});
