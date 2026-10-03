import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";
import WhatsAppPage from "@/app/dashboard/whatsapp/page";
import { installApiMock, sessionOf, type ApiRoutes } from "../support/api-mock";

const ME = "/api/auth/me.php";
const CONVERSATIONS = "/api/whatsapp/conversations.php";
const MESSAGES = "/api/whatsapp/messages.php";

const PERMITIDO = sessionOf("root", { email: "sierra.csi@gmail.com" });

/** Janela ainda aberta, montada relativa a agora para não vencer com o tempo. */
const janelaAberta = {
  open: true,
  expires_at: new Date(Date.now() + 6 * 3600_000).toISOString(),
  minutes_left: 360,
};

const conversa = {
  id: 1,
  phone: "5521999887766",
  name: "Heineken",
  profile_name: "João da Heineken",
  client_id: 7,
  client_name: "Heineken",
  status: "open",
  unread_count: 2,
  last_message_at: new Date().toISOString(),
  last_message_preview: "Bom dia, pode passar hoje?",
  last_message_direction: "incoming",
  window: janelaAberta,
};

function montar(rotas: ApiRoutes = {}, sessao = PERMITIDO) {
  return installApiMock({
    [ME]: { body: sessao },
    [CONVERSATIONS]: { body: { ok: true, conversations: [conversa] } },
    [MESSAGES]: {
      body: {
        ok: true,
        conversation: conversa,
        messages: [
          {
            id: 1,
            direction: "incoming",
            type: "text",
            status: null,
            body: "Bom dia, pode passar hoje?",
            message_at: new Date().toISOString(),
            service_order_id: null,
          },
          {
            id: 2,
            direction: "outgoing",
            type: "text",
            status: "read",
            body: "Ordem de Serviço Nº 00042",
            message_at: new Date().toISOString(),
            service_order_id: 42,
          },
        ],
      },
    },
    ...rotas,
  });
}

describe("/dashboard/whatsapp — acesso", () => {
  it("nega para root que não está na lista", async () => {
    montar({}, sessionOf("root", { email: "outro@exemplo.com" }));
    render(<WhatsAppPage />);

    expect(await screen.findByText("Acesso negado.")).toBeVisible();
  });

  it("autoriza conta com papel master", async () => {
    montar({}, sessionOf("master", { email: "cliente@ecolevaeco.com" }));
    render(<WhatsAppPage />);

    expect(await screen.findByText("Heineken")).toBeVisible();
  });

  it("nega para user mesmo com o e-mail da lista", async () => {
    montar({}, sessionOf("user", { email: "sierra.csi@gmail.com" }));
    render(<WhatsAppPage />);

    expect(await screen.findByText("Acesso negado.")).toBeVisible();
  });

  it("não chama a API de conversas quando o acesso é negado", async () => {
    const api = montar({}, sessionOf("user", { email: "sierra.csi@gmail.com" }));
    render(<WhatsAppPage />);

    await screen.findByText("Acesso negado.");
    expect(api.requested(CONVERSATIONS)).toBe(false);
  });
});

describe("/dashboard/whatsapp — conversas", () => {
  it("lista a conversa com nome, prévia e não lidas", async () => {
    montar();
    render(<WhatsAppPage />);

    expect(await screen.findByText("Heineken")).toBeVisible();
    expect(screen.getByText("Bom dia, pode passar hoje?")).toBeVisible();
    expect(screen.getByText("2")).toBeVisible();
  });

  it("abre a conversa e mostra as bolhas dos dois lados", async () => {
    montar();
    render(<WhatsAppPage />);

    const user = userEvent.setup();
    await user.click(await screen.findByText("Heineken"));

    await waitFor(() => {
      expect(screen.getByText("Ordem de Serviço Nº 00042")).toBeVisible();
    });

    // A bolha de saída carrega o número da OS que a originou.
    expect(screen.getByText("OS Nº 00042")).toBeVisible();
  });

  it("mostra a faixa de janela aberta com o tempo restante", async () => {
    montar();
    render(<WhatsAppPage />);

    const user = userEvent.setup();
    await user.click(await screen.findByText("Heineken"));

    expect(await screen.findByText(/Janela aberta/)).toBeVisible();
  });

  it("mostra janela fechada quando o cliente não escreve há mais de 24h", async () => {
    const vencida = {
      ...conversa,
      window: { open: false, expires_at: "2026-09-01T10:00:00Z", minutes_left: 0 },
    };

    montar({
      [CONVERSATIONS]: { body: { ok: true, conversations: [vencida] } },
      [MESSAGES]: { body: { ok: true, conversation: vencida, messages: [] } },
    });
    render(<WhatsAppPage />);

    const user = userEvent.setup();
    await user.click(await screen.findByText("Heineken"));

    expect(await screen.findByText("Janela fechada — só por template")).toBeVisible();
  });

  it("marca a conversa como lida ao abrir", async () => {
    const api = montar();
    render(<WhatsAppPage />);

    const user = userEvent.setup();
    await user.click(await screen.findByText("Heineken"));

    await waitFor(() => {
      const post = api.fetch.mock.calls.find(
        ([url, init]) => String(url) === MESSAGES && init?.method === "POST"
      );
      expect(post).toBeDefined();
      expect(JSON.parse(String(post![1]!.body))).toEqual({ conversation_id: 1 });
    });
  });

  it("filtra a lista pela busca", async () => {
    montar();
    render(<WhatsAppPage />);

    const user = userEvent.setup();
    await screen.findByText("Heineken");
    await user.type(screen.getByLabelText("Buscar conversa"), "ambev");

    expect(await screen.findByText("Nada encontrado.")).toBeVisible();
  });

  it("avisa quando ainda não há conversa nenhuma", async () => {
    montar({ [CONVERSATIONS]: { body: { ok: true, conversations: [] } } });
    render(<WhatsAppPage />);

    expect(await screen.findByText("Nenhuma conversa ainda.")).toBeVisible();
  });

  it("mostra o erro devolvido pela API", async () => {
    montar({ [CONVERSATIONS]: { status: 403, body: { error: "Acesso negado." } } });
    render(<WhatsAppPage />);

    const painel = await screen.findByRole("heading", { name: "WhatsApp" });
    expect(painel).toBeVisible();
    await waitFor(() => {
      expect(screen.getByText("Acesso negado.")).toBeVisible();
    });
  });
});

describe("/dashboard/whatsapp — mensagem que falhou", () => {
  it("mostra o motivo da falha na bolha", async () => {
    montar({
      [MESSAGES]: {
        body: {
          ok: true,
          conversation: conversa,
          messages: [
            {
              id: 3,
              direction: "outgoing",
              type: "template",
              status: "failed",
              body: "Ordem de Serviço Nº 00043",
              error_message: "Message undeliverable",
              message_at: new Date().toISOString(),
              service_order_id: 43,
            },
          ],
        },
      },
    });
    render(<WhatsAppPage />);

    const user = userEvent.setup();
    await user.click(await screen.findByText("Heineken"));

    const erro = await screen.findByText("Message undeliverable");
    expect(erro).toBeVisible();
    expect(within(erro.parentElement as HTMLElement).getByText("!")).toBeVisible();
  });
});

describe("/dashboard/whatsapp — composer e ações", () => {
  it("permite digitar e enviar resposta de texto na janela aberta", async () => {
    const novaMensagem = {
      id: 99,
      direction: "outgoing",
      type: "text",
      status: "sent",
      body: "Olá!",
      message_at: new Date().toISOString(),
      service_order_id: null,
    };

    const api = montar();
    const defaultFetch = api.fetch.getMockImplementation();
    api.fetch.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : input.toString();
      const path = url.split("?")[0];
      if (path === MESSAGES && init?.method === "POST") {
        const body = JSON.parse(String(init.body || "{}"));
        if (body.body) {
          return new Response(JSON.stringify({ ok: true, message: novaMensagem }), {
            status: 200,
            headers: { "Content-Type": "application/json" },
          });
        }
      }
      return defaultFetch!(input, init);
    });

    render(<WhatsAppPage />);

    const user = userEvent.setup();
    await user.click(await screen.findByText("Heineken"));

    const input = await screen.findByPlaceholderText("Digite uma mensagem...");
    expect(input).toBeVisible();
    fireEvent.change(input, { target: { value: "Olá!" } });

    const botaoEnviar = await screen.findByRole("button", { name: /Enviar mensagem/i });
    await waitFor(() => {
      expect(botaoEnviar).not.toBeDisabled();
    });
    fireEvent.click(botaoEnviar);

    await waitFor(() => {
      const posts = api.fetch.mock.calls.filter(
        ([url, init]) => String(url) === MESSAGES && init?.method === "POST"
      );
      const replyCall = posts.find(([, init]) => {
        const payload = JSON.parse(String(init?.body || "{}"));
        return payload.body === "Olá!";
      });
      expect(replyCall).toBeDefined();
    });

    const matches = await screen.findAllByText("Olá!");
    expect(matches.length).toBeGreaterThanOrEqual(1);
  });

  it("abre o modal de nova conversa ao clicar em Nova conversa", async () => {
    montar();
    render(<WhatsAppPage />);

    const user = userEvent.setup();
    const btnNovo = await screen.findByTitle("Nova conversa");
    await user.click(btnNovo);

    expect(await screen.findByText("Iniciar Nova Conversa")).toBeVisible();
    expect(screen.getByPlaceholderText("Ex: 21 99919-3898")).toBeVisible();
  });

  it("oferece botão de template quando a janela está fechada", async () => {
    const vencida = {
      ...conversa,
      window: { open: false, expires_at: "2026-09-01T10:00:00Z", minutes_left: 0 },
    };

    montar({
      [CONVERSATIONS]: { body: { ok: true, conversations: [vencida] } },
      [MESSAGES]: { body: { ok: true, conversation: vencida, messages: [] } },
    });
    render(<WhatsAppPage />);

    const user = userEvent.setup();
    await user.click(await screen.findByText("Heineken"));

    const btnTemplate = await screen.findByRole("button", { name: /Retomar com template/i });
    expect(btnTemplate).toBeVisible();
  });
});

// ── Revisão: modal de template, busca, paginação e falhas silenciosas ─────────

const TEMPLATES = "/api/whatsapp/templates.php";

const vencida = {
  ...conversa,
  window: { open: false, expires_at: "2026-09-01T10:00:00Z", minutes_left: 0 },
};

const templateOs = {
  name: "ordem_servico",
  language: "pt_BR",
  category: "UTILITY",
  status: "APPROVED",
  body_text: "Olá {{1}}, sua OS {{2}} está em {{3}}",
  params_count: 3,
  param_labels: ["Nome do Cliente", "Número da OS", "Link da OS"],
  param_kinds: ["client_name", "os_number", "os_link"],
};

const templateFatura = {
  name: "fatura_mensal",
  language: "pt_BR",
  category: "UTILITY",
  status: "APPROVED",
  body_text: "Olá {{1}}, fatura de R$ {{2}} vence em {{3}}: {{4}}",
  params_count: 4,
  param_labels: ["Nome do Cliente", "Valor (R$)", "Vencimento", "Link da Fatura"],
  param_kinds: ["client_name", "amount", "due_date", "invoice_link"],
};

/** Monta a tela com a conversa de janela fechada e as rotas de template dadas. */
function montarComJanelaFechada(templates: { status?: number; body: unknown }) {
  return montar({
    [CONVERSATIONS]: { body: { ok: true, conversations: [vencida] } },
    [MESSAGES]: { body: { ok: true, conversation: vencida, messages: [] } },
    [TEMPLATES]: templates,
  });
}

async function abrirModalDeTemplate() {
  render(<WhatsAppPage />);
  const user = userEvent.setup();
  await user.click(await screen.findByText("Heineken"));
  await user.click(await screen.findByRole("button", { name: /Retomar com template/i }));

  return user;
}

describe("/dashboard/whatsapp — modal de template (W1)", () => {
  it("pré-preenche só o nome do cliente; número da OS e link ficam vazios", async () => {
    montarComJanelaFechada({ body: { ok: true, templates: [templateOs, templateFatura] } });
    await abrirModalDeTemplate();

    expect(await screen.findByLabelText("Nome do Cliente")).toHaveValue("Heineken");
    expect(screen.getByLabelText("Número da OS")).toHaveValue("");
    expect(screen.getByLabelText("Link da OS")).toHaveValue("");
  });

  it("mostra o rótulo de cada variável do template", async () => {
    montarComJanelaFechada({ body: { ok: true, templates: [templateFatura] } });
    await abrirModalDeTemplate();

    expect(await screen.findByLabelText("Nome do Cliente")).toBeVisible();
    expect(screen.getByLabelText("Valor (R$)")).toBeVisible();
    expect(screen.getByLabelText("Vencimento")).toBeVisible();
    expect(screen.getByLabelText("Link da Fatura")).toBeVisible();
  });

  it("ao trocar de template, limpa os valores e reconstrói a partir do novo", async () => {
    montarComJanelaFechada({ body: { ok: true, templates: [templateOs, templateFatura] } });
    const user = await abrirModalDeTemplate();

    await user.type(await screen.findByLabelText("Número da OS"), "00042");
    await user.type(screen.getByLabelText("Link da OS"), "https://exemplo.com/os/abc");

    await user.selectOptions(screen.getByLabelText("Modelo de mensagem homologado"), "fatura_mensal");

    // Quatro variáveis, só o nome preenchido; nada do template anterior sobrou.
    expect(await screen.findByLabelText("Valor (R$)")).toHaveValue("");
    expect(screen.getByLabelText("Nome do Cliente")).toHaveValue("Heineken");
    expect(screen.getByLabelText("Vencimento")).toHaveValue("");
    expect(screen.getByLabelText("Link da Fatura")).toHaveValue("");
  });

  it("não envia enquanto houver variável em branco", async () => {
    const api = montarComJanelaFechada({ body: { ok: true, templates: [templateOs] } });
    const user = await abrirModalDeTemplate();

    await user.type(await screen.findByLabelText("Número da OS"), "   ");
    await user.type(screen.getByLabelText("Link da OS"), "https://exemplo.com/os/abc");
    await user.click(screen.getByRole("button", { name: "Enviar Template" }));

    expect(await screen.findByText(/Preencha todas as variáveis/)).toBeVisible();
    const envios = api.fetch.mock.calls.filter(
      ([url, init]) => String(url) === TEMPLATES && init?.method === "POST"
    );
    expect(envios).toHaveLength(0);
  });

  it("envia exatamente o que foi preenchido", async () => {
    const api = montarComJanelaFechada({ body: { ok: true, templates: [templateOs] } });
    const defaultFetch = api.fetch.getMockImplementation();
    api.fetch.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      const path = (typeof input === "string" ? input : input.toString()).split("?")[0];
      if (path === TEMPLATES && init?.method === "POST") {
        return new Response(
          JSON.stringify({
            ok: true,
            message: {
              id: 50,
              direction: "outgoing",
              type: "template",
              status: "accepted",
              body: "[Template: ordem_servico]",
              message_at: new Date().toISOString(),
              service_order_id: null,
            },
          }),
          { status: 200, headers: { "Content-Type": "application/json" } }
        );
      }
      return defaultFetch!(input, init);
    });

    const user = await abrirModalDeTemplate();
    await user.type(await screen.findByLabelText("Número da OS"), "00042");
    await user.type(screen.getByLabelText("Link da OS"), "https://exemplo.com/os/abc");
    await user.click(screen.getByRole("button", { name: "Enviar Template" }));

    await waitFor(() => {
      const envio = api.fetch.mock.calls.find(
        ([url, init]) => String(url) === TEMPLATES && init?.method === "POST"
      );
      expect(envio).toBeDefined();
      expect(JSON.parse(String(envio![1]!.body))).toEqual({
        conversation_id: 1,
        template_name: "ordem_servico",
        template_language: "pt_BR",
        parameters: ["Heineken", "00042", "https://exemplo.com/os/abc"],
      });
    });
  });
});

describe("/dashboard/whatsapp — status e erros dos templates (W9, W10)", () => {
  it("mostra o status real e não deixa enviar template em análise", async () => {
    montarComJanelaFechada({
      body: { ok: true, templates: [{ ...templateOs, status: "PENDING" }] },
    });
    await abrirModalDeTemplate();

    const modal = await screen.findByRole("dialog");
    expect(within(modal).getAllByText(/Em análise na Meta/).length).toBeGreaterThan(0);
    expect(within(modal).getByRole("button", { name: "Enviar Template" })).toBeDisabled();
  });

  it("template aprovado fica liberado para envio", async () => {
    montarComJanelaFechada({ body: { ok: true, templates: [templateOs] } });
    await abrirModalDeTemplate();

    const modal = await screen.findByRole("dialog");
    expect(within(modal).getByRole("button", { name: "Enviar Template" })).toBeEnabled();
  });

  it("mostra o erro da Meta dentro do modal, sem depender de toast", async () => {
    montarComJanelaFechada({
      body: {
        ok: true,
        source: "config",
        error: "O token do WhatsApp expirou ou foi revogado. Gere um token permanente.",
        error_detail: "Error validating access token: Session has expired",
        templates: [{ ...templateOs, status: "UNVERIFIED", body_text: "" }],
      },
    });
    await abrirModalDeTemplate();

    const modal = await screen.findByRole("dialog");
    const alerta = within(modal).getByRole("alert");
    expect(alerta).toHaveTextContent("O token do WhatsApp expirou ou foi revogado");
    expect(alerta).toHaveTextContent("Error validating access token");
    // Não verificado envia, mas avisa que a aprovação é desconhecida.
    expect(within(modal).getAllByText(/Não verificado na Meta/).length).toBeGreaterThan(0);
    expect(within(modal).getByRole("button", { name: "Enviar Template" })).toBeEnabled();
  });

  it("o modal abre com o erro mesmo quando a consulta de templates falha", async () => {
    montarComJanelaFechada({ status: 500, body: { error: "Falha interna." } });
    await abrirModalDeTemplate();

    const modal = await screen.findByRole("dialog");
    expect(within(modal).getByRole("alert")).toHaveTextContent("Falha interna.");
    expect(within(modal).getByRole("button", { name: "Enviar Template" })).toBeDisabled();
  });

  it("sem nenhum template, o modal explica em vez de ficar sem abrir", async () => {
    montarComJanelaFechada({
      body: { ok: true, source: "config", error: "A Meta recusou a consulta de templates (HTTP 500).", templates: [] },
    });
    await abrirModalDeTemplate();

    const modal = await screen.findByRole("dialog");
    expect(within(modal).getByRole("alert")).toHaveTextContent("A Meta recusou a consulta de templates");
    expect(within(modal).getByText(/Nenhum template disponível/)).toBeVisible();
  });

  it("erro de envio do template fica no modal, onde a pessoa está olhando", async () => {
    const api = montarComJanelaFechada({ body: { ok: true, templates: [templateOs] } });
    const defaultFetch = api.fetch.getMockImplementation();
    api.fetch.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      const path = (typeof input === "string" ? input : input.toString()).split("?")[0];
      if (path === TEMPLATES && init?.method === "POST") {
        return new Response(JSON.stringify({ error: "Falha ao enviar template: template não aprovado." }), {
          status: 502,
          headers: { "Content-Type": "application/json" },
        });
      }
      return defaultFetch!(input, init);
    });

    const user = await abrirModalDeTemplate();
    await user.type(await screen.findByLabelText("Número da OS"), "00042");
    await user.type(screen.getByLabelText("Link da OS"), "https://exemplo.com/os/abc");
    await user.click(screen.getByRole("button", { name: "Enviar Template" }));

    const modal = await screen.findByRole("dialog");
    await waitFor(() => {
      expect(within(modal).getByRole("alert")).toHaveTextContent("template não aprovado");
    });
  });
});

describe("/dashboard/whatsapp — falhas que não podem ser silenciosas (W10)", () => {
  it("falha ao carregar as mensagens mostra o erro, e não 'Nenhuma mensagem.'", async () => {
    montar({ [MESSAGES]: { status: 500, body: { error: "Banco indisponível." } } });
    render(<WhatsAppPage />);

    const user = userEvent.setup();
    await user.click(await screen.findByText("Heineken"));

    expect(await screen.findByText(/Não foi possível carregar as mensagens/)).toBeVisible();
    expect(screen.queryByText("Nenhuma mensagem.")).not.toBeInTheDocument();
  });

  it("não mostra 'Nenhuma mensagem.' enquanto ainda está carregando", async () => {
    const api = montar();
    const defaultFetch = api.fetch.getMockImplementation();
    let liberar!: () => void;
    const portao = new Promise<void>(resolve => {
      liberar = resolve;
    });
    api.fetch.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      const path = (typeof input === "string" ? input : input.toString()).split("?")[0];
      if (path === MESSAGES && (init?.method ?? "GET") === "GET") {
        await portao;
      }
      return defaultFetch!(input, init);
    });

    render(<WhatsAppPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("Heineken"));

    expect(screen.getByText(/Carregando mensagens/)).toBeVisible();
    expect(screen.queryByText("Nenhuma mensagem.")).not.toBeInTheDocument();

    liberar();
    expect(await screen.findByText("Ordem de Serviço Nº 00042")).toBeVisible();
  });

  /** POSTs de mensagens.php que a API recusa, para exercitar o caminho de erro dos botões. */
  function recusarPostsDeMensagens(api: ReturnType<typeof montar>, soAcao?: string) {
    const defaultFetch = api.fetch.getMockImplementation();
    api.fetch.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      const path = (typeof input === "string" ? input : input.toString()).split("?")[0];
      if (path === MESSAGES && init?.method === "POST") {
        const corpo = JSON.parse(String(init.body || "{}"));
        if (soAcao === undefined || corpo.action === soAcao) {
          return new Response(JSON.stringify({ error: "Banco indisponível." }), {
            status: 500,
            headers: { "Content-Type": "application/json" },
          });
        }
      }
      return defaultFetch!(input, init);
    });
  }

  it("marcar como não lida não anuncia sucesso quando a API recusa", async () => {
    const lida = { ...conversa, unread_count: 0 };
    const api = montar({
      [CONVERSATIONS]: { body: { ok: true, conversations: [lida] } },
      [MESSAGES]: { body: { ok: true, conversation: lida, messages: [] } },
    });
    recusarPostsDeMensagens(api, "mark_unread");

    render(<WhatsAppPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("Heineken"));
    await user.click(await screen.findByTitle("Marcar como não lida"));

    expect(await screen.findByText("Banco indisponível.")).toBeVisible();
    expect(screen.queryByText("Conversa marcada como não lida.")).not.toBeInTheDocument();
    // E a tela continua dizendo a verdade: a conversa segue lida.
    expect(screen.getByTitle("Marcar como não lida")).toBeVisible();
  });

  it("marcar como lida não anuncia sucesso quando a API recusa", async () => {
    const api = montar();
    recusarPostsDeMensagens(api);

    render(<WhatsAppPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("Heineken"));
    await user.click(await screen.findByTitle("Marcar como lida"));

    expect(await screen.findByText("Banco indisponível.")).toBeVisible();
    expect(screen.queryByText("Conversa marcada como lida.")).not.toBeInTheDocument();
  });

  it("nome com dígito ('Posto 3') não casa com telefone que contém o dígito", async () => {
    const padaria = { ...conversa, id: 2, name: "Padaria", client_name: null, phone: "5521933333333", last_message_preview: "oi" };
    const posto = { ...conversa, id: 3, name: "Posto 3", client_name: null, phone: "5521988887777", last_message_preview: "oi" };

    montar({ [CONVERSATIONS]: { body: { ok: true, conversations: [padaria, posto] } } });
    render(<WhatsAppPage />);

    const user = userEvent.setup();
    await screen.findByText("Padaria");
    await user.type(screen.getByLabelText("Buscar conversa"), "Posto 3");

    expect(await screen.findByText("Posto 3")).toBeVisible();
    await waitFor(() => {
      expect(screen.queryByText("Padaria")).not.toBeInTheDocument();
    });
  });

  it("busca só de número continua achando pelo telefone, com máscara", async () => {
    const padaria = { ...conversa, id: 2, name: "Padaria", client_name: null, phone: "5521933333333", last_message_preview: "oi" };
    const posto = { ...conversa, id: 3, name: "Posto", client_name: null, phone: "5521988887777", last_message_preview: "oi" };

    montar({ [CONVERSATIONS]: { body: { ok: true, conversations: [padaria, posto] } } });
    render(<WhatsAppPage />);

    const user = userEvent.setup();
    await screen.findByText("Padaria");
    await user.type(screen.getByLabelText("Buscar conversa"), "(21) 98888-7777");

    expect(await screen.findByText("Posto")).toBeVisible();
    await waitFor(() => {
      expect(screen.queryByText("Padaria")).not.toBeInTheDocument();
    });
  });
});

describe("/dashboard/whatsapp — listas cortadas e busca no servidor (W7)", () => {
  it("manda o termo da busca para o servidor", async () => {
    const api = montar();
    render(<WhatsAppPage />);

    const user = userEvent.setup();
    await screen.findByText("Heineken");
    await user.type(screen.getByLabelText("Buscar conversa"), "zeta");

    await waitFor(() => {
      expect(api.calls.some(url => url.startsWith(CONVERSATIONS) && url.includes("q=zeta"))).toBe(true);
    });
  });

  it("acha a conversa que só o servidor devolve (fora das 300 mais recentes)", async () => {
    const antiga = { ...conversa, id: 301, name: "Zeta Mais Antiga", client_name: null, last_message_preview: "oi", unread_count: 0 };
    const api = montar();
    const defaultFetch = api.fetch.getMockImplementation();
    api.fetch.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === "string" ? input : input.toString();
      if (url.startsWith(CONVERSATIONS) && url.includes("q=Zeta")) {
        return new Response(JSON.stringify({ ok: true, conversations: [antiga], total: 1, truncated: false }), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        });
      }
      return defaultFetch!(input, init);
    });

    render(<WhatsAppPage />);
    const user = userEvent.setup();
    await screen.findByText("Heineken");
    await user.type(screen.getByLabelText("Buscar conversa"), "Zeta");

    expect(await screen.findByText("Zeta Mais Antiga")).toBeVisible();
  });

  it("avisa que a lista foi cortada, com o total", async () => {
    montar({
      [CONVERSATIONS]: { body: { ok: true, conversations: [conversa], total: 305, truncated: true } },
    });
    render(<WhatsAppPage />);

    expect(await screen.findByText(/Mostrando as 1 conversas mais recentes de 305/)).toBeVisible();
    expect(screen.getByText(/Use a busca/)).toBeVisible();
  });

  it("não mostra aviso de corte quando a lista está inteira", async () => {
    montar({
      [CONVERSATIONS]: { body: { ok: true, conversations: [conversa], total: 1, truncated: false } },
    });
    render(<WhatsAppPage />);

    await screen.findByText("Heineken");
    expect(screen.queryByText(/Mostrando as/)).not.toBeInTheDocument();
  });

  it("avisa quando a conversa tem mensagens mais antigas que as exibidas", async () => {
    montar({
      [MESSAGES]: {
        body: {
          ok: true,
          conversation: conversa,
          truncated: true,
          messages: [
            {
              id: 9,
              direction: "incoming",
              type: "text",
              status: null,
              body: "Mensagem recente",
              message_at: new Date().toISOString(),
              service_order_id: null,
            },
          ],
        },
      },
    });
    render(<WhatsAppPage />);

    const user = userEvent.setup();
    await user.click(await screen.findByText("Heineken"));

    expect(await screen.findByText(/Mostrando as 500 mensagens mais recentes/)).toBeVisible();
  });
});

describe("/dashboard/whatsapp — gravação de áudio (W8)", () => {
  class GravadorFalso {
    static suportados: string[] = [];

    static opcoes: MediaRecorderOptions | undefined;

    mimeType = "audio/mp4";

    ondataavailable: ((e: { data: Blob }) => void) | null = null;

    onstop: (() => void) | null = null;

    constructor(_stream: MediaStream, options?: MediaRecorderOptions) {
      GravadorFalso.opcoes = options;
    }

    static isTypeSupported(tipo: string) {
      return GravadorFalso.suportados.includes(tipo);
    }

    start() {}

    stop() {}
  }

  function liberarMicrofone() {
    const stream = { getTracks: () => [{ stop: () => {} }] } as unknown as MediaStream;
    Object.defineProperty(window.navigator, "mediaDevices", {
      configurable: true,
      value: { getUserMedia: async () => stream },
    });
    GravadorFalso.opcoes = undefined;
    vi.stubGlobal("MediaRecorder", GravadorFalso);
  }

  afterEach(() => {
    // Remove o que o teste definiu no navigator do jsdom.
    Reflect.deleteProperty(window.navigator, "mediaDevices");
  });

  it("no Chrome grava em mp4, que a Meta aceita, e não em webm", async () => {
    GravadorFalso.suportados = ["audio/webm;codecs=opus", "audio/mp4"];
    liberarMicrofone();
    montar();
    render(<WhatsAppPage />);

    const user = userEvent.setup();
    await user.click(await screen.findByText("Heineken"));
    await user.click(await screen.findByLabelText("Gravar áudio"));

    await waitFor(() => {
      expect(GravadorFalso.opcoes).toEqual({ mimeType: "audio/mp4" });
    });
  });

  it("se o navegador só grava webm, avisa antes de gravar", async () => {
    GravadorFalso.suportados = ["audio/webm;codecs=opus"];
    liberarMicrofone();
    montar();
    render(<WhatsAppPage />);

    const user = userEvent.setup();
    await user.click(await screen.findByText("Heineken"));
    await user.click(await screen.findByLabelText("Gravar áudio"));

    expect(await screen.findByText(/não grava áudio em formato aceito pelo WhatsApp/)).toBeVisible();
    expect(GravadorFalso.opcoes).toBeUndefined();
    expect(screen.queryByText(/Gravando:/)).not.toBeInTheDocument();
  });
});
