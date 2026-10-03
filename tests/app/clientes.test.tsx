import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import ClientesPage from "@/app/dashboard/clientes/page";
import NovoClientePage from "@/app/dashboard/clientes/novo/page";
import EditarClientePage from "@/app/dashboard/clientes/editar/page";
import { installApiMock, sessionOf } from "../support/api-mock";

const ME = "/api/auth/me.php";
const CLIENTS = "/api/clients/index.php";
const CLIENTS_EDIT = "/api/clients/edit.php";

const useSearchParamsMock = vi.fn(() => new URLSearchParams("id=42"));

vi.mock("next/navigation", () => ({
  useSearchParams: () => useSearchParamsMock(),
  usePathname: () => "/dashboard/clientes",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

beforeEach(() => {
  useSearchParamsMock.mockReturnValue(new URLSearchParams("id=42"));
});

const rotaClientes = {
  status: 200,
  body: {
    ok: true,
    clients: [
      {
        id: 42,
        name: "Bia Associação",
        email: "biabrasil@garciadavila.rio.br",
        whatsapp: "5521994077572",
        document: "40207218000136",
        monthly_value: 600,
        due_day: 10,
        status: "active",
      },
    ],
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

describe("/dashboard/clientes — Listagem", () => {
  it("renderiza a listagem e link para cadastrar novo cliente", async () => {
    installApiMock({ [ME]: { body: sessionOf("root") }, [CLIENTS]: rotaClientes });
    render(<ClientesPage />);

    expect(await screen.findByRole("heading", { name: "Clientes" })).toBeVisible();
    const btnNovo = screen.getByRole("link", { name: /Novo Cliente/i });
    expect(btnNovo).toHaveAttribute("href", "/dashboard/clientes/novo");

    expect(await screen.findByText("Bia Associação")).toBeVisible();
    const linkEditar = screen.getByRole("link", { name: "Editar Bia Associação" });
    expect(linkEditar).toHaveAttribute("href", "/dashboard/clientes/editar?id=42");
  });
});

describe("/dashboard/clientes/novo — Cadastro e Cobrança Mensal", () => {
  it("completa DDI e DDD do WhatsApp ao sair do campo", async () => {
    installApiMock({ [ME]: { body: sessionOf("root") }, [CLIENTS]: rotaClientes });
    render(<NovoClientePage />);

    const campo = await screen.findByLabelText("WhatsApp");
    const user = userEvent.setup();
    await user.type(campo, "99988-7766");
    await user.tab();

    expect(campo).toHaveValue("5521999887766");
  });

  it("cadastra cliente com cobrança desabilitada enviando monthly_value: 0", async () => {
    const api = installApiMock({ [ME]: { body: sessionOf("root") }, [CLIENTS]: rotaClientes });
    render(<NovoClientePage />);

    const user = userEvent.setup();
    await user.type(await screen.findByLabelText("Nome / Empresa *"), "Cliente Avulso");
    await user.type(screen.getByLabelText("WhatsApp"), "21 99988-7766");

    // Cobrança mensal está desabilitada por padrão na criação
    expect(screen.getByText("Cobrança mensal desabilitada")).toBeVisible();
    expect(screen.queryByLabelText("Valor Mensal Fixo (R$) *")).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Salvar Cliente" }));

    await waitFor(() => {
      expect(screen.getByText("Cliente cadastrado com sucesso.")).toBeVisible();
    });

    const post = api.fetch.mock.calls.find(
      ([url, init]) => String(url).startsWith(CLIENTS) && init?.method === "POST"
    );
    expect(post).toBeDefined();
    const corpo = JSON.parse(String(post![1]!.body));
    expect(corpo.name).toBe("Cliente Avulso");
    expect(corpo.whatsapp).toBe("5521999887766");
    expect(corpo.monthly_value).toBe(0);
  });

  it("permite habilitar cobrança mensal via toggle e exige valor e documento", async () => {
    const api = installApiMock({ [ME]: { body: sessionOf("root") }, [CLIENTS]: rotaClientes });
    render(<NovoClientePage />);

    const user = userEvent.setup();
    await user.type(await screen.findByLabelText("Nome / Empresa *"), "Mensalista");

    const switchBtn = screen.getByRole("switch", { name: /cobrança mensal/i });
    expect(switchBtn).toHaveAttribute("aria-checked", "false");

    // Habilita cobrança
    await user.click(switchBtn);
    expect(switchBtn).toHaveAttribute("aria-checked", "true");
    expect(screen.getByText("Cobrança mensal habilitada")).toBeVisible();

    // Aguarda o timeout de foco do componente assentar
    await new Promise((resolve) => setTimeout(resolve, 200));

    // Campos aparecem
    const campoValor = screen.getByLabelText("Valor Mensal Fixo (R$) *");
    expect(campoValor).toBeVisible();
    await user.type(campoValor, "450");

    // Preenche documento
    const campoDoc = screen.getByLabelText(/CPF\/CNPJ \*/i);
    await user.type(campoDoc, "12345678000199");

    await user.click(screen.getByRole("button", { name: "Salvar Cliente" }));

    await waitFor(() => {
      expect(screen.getByText("Cliente cadastrado com sucesso.")).toBeVisible();
    });

    const post = api.fetch.mock.calls.find(
      ([url, init]) => String(url).startsWith(CLIENTS) && init?.method === "POST"
    );
    expect(post).toBeDefined();
    const corpo = JSON.parse(String(post![1]!.body));
    expect(corpo.monthly_value).toBe(450);
    expect(corpo.document).toBe("12345678000199");
  }, 15_000);
});

describe("/dashboard/clientes/novo — valor mínimo da cobrança mensal", () => {
  it("barra valor abaixo de R$ 5,00 no próprio campo, sem chamar a API", async () => {
    const api = installApiMock({ [ME]: { body: sessionOf("root") }, [CLIENTS]: rotaClientes });
    render(<NovoClientePage />);

    const user = userEvent.setup();
    await user.type(await screen.findByLabelText("Nome / Empresa *"), "Valor Baixo");
    await user.click(screen.getByRole("switch", { name: /cobrança mensal/i }));
    const campoValor = screen.getByLabelText("Valor Mensal Fixo (R$) *");
    await user.type(campoValor, "4.99");
    await user.type(screen.getByLabelText(/CPF\/CNPJ \*/i), "12345678000199");
    await user.click(screen.getByRole("button", { name: "Salvar Cliente" }));

    expect(campoValor).toBeInvalid();
    const post = api.fetch.mock.calls.find(
      ([url, init]) => String(url).startsWith(CLIENTS) && init?.method === "POST"
    );
    expect(post).toBeUndefined();
  }, 15_000);

  it("explica o mínimo em texto mesmo quando a validação nativa do navegador é contornada", async () => {
    const api = installApiMock({ [ME]: { body: sessionOf("root") }, [CLIENTS]: rotaClientes });
    render(<NovoClientePage />);

    const user = userEvent.setup();
    await user.type(await screen.findByLabelText("Nome / Empresa *"), "Valor Baixo");
    await user.click(screen.getByRole("switch", { name: /cobrança mensal/i }));
    await user.type(screen.getByLabelText("Valor Mensal Fixo (R$) *"), "4.99");
    await user.type(screen.getByLabelText(/CPF\/CNPJ \*/i), "12345678000199");
    fireEvent.submit(screen.getByRole("button", { name: "Salvar Cliente" }).closest("form")!);

    expect(await screen.findByText(/valor mensal mínimo é R\$ 5,00/i)).toBeVisible();
    const post = api.fetch.mock.calls.find(
      ([url, init]) => String(url).startsWith(CLIENTS) && init?.method === "POST"
    );
    expect(post).toBeUndefined();
  }, 15_000);

  it("explica quando a primeira fatura sai, em vez de prometer uma assinatura no Asaas", async () => {
    installApiMock({ [ME]: { body: sessionOf("root") }, [CLIENTS]: rotaClientes });
    render(<NovoClientePage />);

    const user = userEvent.setup();
    await user.click(await screen.findByRole("switch", { name: /cobrança mensal/i }));

    expect(screen.getByText(/a primeira sai no próximo ciclo diário/i)).toBeVisible();
    expect(screen.getByText(/a partir do dia 30/i)).toBeVisible();
    expect(screen.queryByText(/ativada no Asaas todo mês/i)).not.toBeInTheDocument();
  });
});

describe("/dashboard/clientes/editar — Edição e Toggle", () => {
  it("carrega dados do cliente na tela de edição e reflete cobrança ativa", async () => {
    const rotaClienteIndividual = {
      status: 200,
      body: {
        ok: true,
        client: {
          id: 42,
          name: "Bia Associação",
          email: "biabrasil@garciadavila.rio.br",
          whatsapp: "5521994077572",
          document: "40207218000136",
          monthly_value: 600,
          due_day: 15,
          status: "active",
        },
      },
    };

    installApiMock({
      [ME]: { body: sessionOf("root") },
      [CLIENTS]: rotaClienteIndividual,
    });

    render(<EditarClientePage />);

    expect(await screen.findByRole("heading", { name: "Editar Cliente: Bia Associação" })).toBeVisible();
    expect(screen.getByLabelText("Nome / Empresa *")).toHaveValue("Bia Associação");
    expect(screen.getByLabelText("E-mail")).toHaveValue("biabrasil@garciadavila.rio.br");
    expect(screen.getByLabelText("Valor Mensal Fixo (R$) *")).toHaveValue(600);
    expect(screen.getByLabelText("Dia de Vencimento *")).toHaveValue(15);
    expect(screen.getByRole("switch", { name: /cobrança mensal/i })).toHaveAttribute("aria-checked", "true");
  });

  /**
   * Desativar o cliente o tira da cobrança automática, mas não toca nas faturas
   * que já existem: o boleto emitido continua pagável. Quem desativa precisa ler
   * isso antes de achar que a cobrança acabou.
   */
  it("avisa na edição que as faturas já emitidas continuam valendo depois de inativar", async () => {
    installApiMock({
      [ME]: { body: sessionOf("root") },
      [CLIENTS]: {
        status: 200,
        body: {
          ok: true,
          client: {
            id: 42,
            name: "Bia Associação",
            email: null,
            whatsapp: "5521994077572",
            document: "40207218000136",
            monthly_value: 600,
            due_day: 10,
            status: "active",
          },
        },
      },
    });

    render(<EditarClientePage />);

    expect(await screen.findByRole("heading", { name: "Editar Cliente: Bia Associação" })).toBeVisible();
    expect(screen.getByText(/faturas já emitidas continuam valendo/i)).toBeVisible();
    expect(screen.getByText(/cancele-as em Faturas/i)).toBeVisible();
  });

  it("permite desabilitar cobrança mensal na edição enviando monthly_value: 0", async () => {
    const rotaClienteIndividual = {
      status: 200,
      body: {
        ok: true,
        client: {
          id: 42,
          name: "Bia Associação",
          email: "biabrasil@garciadavila.rio.br",
          whatsapp: "5521994077572",
          document: "40207218000136",
          monthly_value: 600,
          due_day: 10,
          status: "active",
        },
      },
    };

    const api = installApiMock({
      [ME]: { body: sessionOf("root") },
      [CLIENTS]: rotaClienteIndividual,
      [CLIENTS_EDIT]: {
        status: 200,
        body: {
          ok: true,
          client: {
            id: 42,
            name: "Bia Associação",
            monthly_value: 0,
            status: "active",
          },
        },
      },
    });

    render(<EditarClientePage />);

    expect(await screen.findByRole("heading", { name: "Editar Cliente: Bia Associação" })).toBeVisible();

    const switchBtn = screen.getByRole("switch", { name: /cobrança mensal/i });
    const user = userEvent.setup();

    // Desabilita cobrança
    await user.click(switchBtn);
    expect(switchBtn).toHaveAttribute("aria-checked", "false");
    expect(screen.getByText("Cobrança mensal desabilitada")).toBeVisible();

    await user.click(screen.getByRole("button", { name: "Atualizar Cliente" }));

    await waitFor(() => {
      expect(screen.getByText("Cliente atualizado com sucesso!")).toBeVisible();
    });

    const post = api.fetch.mock.calls.find(
      ([url, init]) => String(url).startsWith(CLIENTS_EDIT) && init?.method === "POST"
    );
    expect(post).toBeDefined();
    const corpo = JSON.parse(String(post![1]!.body));
    expect(corpo.client_id).toBe(42);
    expect(corpo.monthly_value).toBe(0);
  });
});
