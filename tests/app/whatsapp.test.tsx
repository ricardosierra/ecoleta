import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
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

  it("nega para master mesmo com o e-mail da lista", async () => {
    montar({}, sessionOf("master", { email: "sierra.csi@gmail.com" }));
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
