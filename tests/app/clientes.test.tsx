import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import ClientesPage from "@/app/dashboard/clientes/page";
import { installApiMock, sessionOf } from "../support/api-mock";

const ME = "/api/auth/me.php";
const CLIENTS = "/api/clients/index.php";

// O mock devolve o mesmo corpo para GET (lista) e POST (create): cada caminho
// lê só a chave que lhe interessa.
const rotaClientes = {
  status: 200,
  body: {
    ok: true,
    clients: [],
    client: {
      id: 1,
      name: "Cliente Novo",
      email: null,
      whatsapp: "5521999887766",
      document: null,
      monthly_value: 0,
      due_day: 10,
      status: "active",
    },
  },
};

describe("/dashboard/clientes — WhatsApp", () => {
  it("completa DDI e DDD ao sair do campo", async () => {
    installApiMock({ [ME]: { body: sessionOf("root") }, [CLIENTS]: rotaClientes });
    render(<ClientesPage />);

    const campo = await screen.findByLabelText("WhatsApp");
    const user = userEvent.setup();
    await user.type(campo, "99988-7766");
    await user.tab();

    expect(campo).toHaveValue("5521999887766");
  });

  it("envia o número normalizado no cadastro mesmo sem blur", async () => {
    const api = installApiMock({ [ME]: { body: sessionOf("root") }, [CLIENTS]: rotaClientes });
    render(<ClientesPage />);

    const user = userEvent.setup();
    await user.type(await screen.findByLabelText("Nome / Empresa *"), "Cliente Novo");
    await user.type(screen.getByLabelText("WhatsApp"), "21 99988-7766");
    await user.click(screen.getByRole("button", { name: "Salvar Cliente" }));

    await waitFor(() => {
      expect(screen.getByText("Cliente cadastrado com sucesso.")).toBeVisible();
    });

    const post = api.fetch.mock.calls.find(
      ([url, init]) => String(url).startsWith(CLIENTS) && init?.method === "POST"
    );
    expect(post).toBeDefined();
    const corpo = JSON.parse(String(post![1]!.body));
    expect(corpo.whatsapp).toBe("5521999887766");
  });
});
