import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import OSPage from "@/app/dashboard/os/page";
import { installApiMock, sessionOf, type ApiRoutes } from "../support/api-mock";

const ME = "/api/auth/me.php";
const CLIENTS = "/api/clients/index.php";
const OS = "/api/os/index.php";
const SEND = "/api/os/send.php";
const WHATSAPP = "/api/os/whatsapp.php";

const ordem = {
  id: 42,
  client_id: 1,
  client_name: "Heineken",
  client_email: "contato@heineken.exemplo",
  client_whatsapp: "5521999887766",
  weight: "150 kg",
  collection_date: "2026-09-03",
  bags_count: 12,
  containers_count: 2,
  responsible: "Equipe A",
  signature_text: "Responsável Técnica - ECOLEVA",
  sent_at: null,
  sent_to: null,
  whatsapp_sent_at: null,
  whatsapp_sent_to: null,
  share_url: "https://ecolevaeco.com/api/os/view.php?id=42&t=abc",
};

function montar(rotas: ApiRoutes = {}) {
  return installApiMock({
    [ME]: { body: sessionOf("root") },
    [CLIENTS]: { body: { ok: true, clients: [{ id: 1, name: "Heineken" }] } },
    [OS]: { body: { ok: true, service_orders: [ordem] } },
    ...rotas,
  });
}

/** Abre a OS do histórico na pré-visualização. */
async function abrirOS(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole("button", { name: "Visualizar" }));
}

describe("/dashboard/os — encaminhamento", () => {
  beforeEach(() => {
    vi.unstubAllGlobals();
  });

  it("mostra a assinatura da responsável no documento", async () => {
    montar();
    render(<OSPage />);
    await abrirOS(userEvent.setup());

    const assinatura = document.querySelector('img[src*="assinatura-responsavel"]');
    expect(assinatura).not.toBeNull();
    expect(screen.getByText("Responsável Técnica - ECOLEVA")).toBeVisible();
  });

  it("formata a data da coleta sem deslocar o dia", async () => {
    montar();
    render(<OSPage />);
    await abrirOS(userEvent.setup());

    // A data aparece no documento e na linha do histórico: as duas leem o mesmo
    // formatador, e nenhuma pode voltar para 02/09 por conta do fuso.
    await waitFor(() => {
      expect(screen.getAllByText(/03\/09\/2026/)).toHaveLength(2);
    });

    const documento = document.querySelector("#os-print-area");
    expect(documento?.textContent).toContain("Data da Coleta: 03/09/2026");
  });

  it("envia por e-mail para o endereço do cliente, já preenchido", async () => {
    const api = montar({
      [SEND]: { body: { ok: true, sent_to: "contato@heineken.exemplo", sent_at: "2026-09-03 14:22:00" } },
    });
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);

    expect(screen.getByLabelText("E-mail do destinatário")).toHaveValue("contato@heineken.exemplo");
    await user.click(screen.getByRole("button", { name: "✉️ E-mail" }));

    await waitFor(() => {
      expect(screen.getByRole("status")).toHaveTextContent("Enviada para contato@heineken.exemplo.");
    });

    const post = api.fetch.mock.calls.find(([url]) => String(url) === SEND);
    expect(JSON.parse(String(post![1]!.body))).toEqual({ id: 42, email: "contato@heineken.exemplo" });
  });

  it("mostra o erro devolvido pelo servidor no envio por e-mail", async () => {
    montar({ [SEND]: { status: 400, body: { error: "E-mail de destino inválido." } } });
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);
    await user.click(screen.getByRole("button", { name: "✉️ E-mail" }));

    await waitFor(() => {
      expect(screen.getByRole("status")).toHaveTextContent("E-mail de destino inválido.");
    });
  });

  it("abre o WhatsApp pessoal com o texto e o link prontos", async () => {
    montar();
    const open = vi.fn();
    vi.stubGlobal("open", open);
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);
    await user.click(screen.getByRole("button", { name: "📱 Meu WhatsApp" }));

    expect(open).toHaveBeenCalledTimes(1);
    const url = String(open.mock.calls[0][0]);
    expect(url.startsWith("https://wa.me/5521999887766?text=")).toBe(true);
    expect(decodeURIComponent(url)).toContain("https://ecolevaeco.com/api/os/view.php?id=42&t=abc");
  });

  it("dispara o WhatsApp do robô sem confirmar quando a OS ainda não foi enviada", async () => {
    const api = montar({
      [WHATSAPP]: {
        body: { ok: true, whatsapp_sent_to: "5521999887766", whatsapp_sent_at: "2026-09-03 14:22:00" },
      },
    });
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);
    await user.click(screen.getByRole("button", { name: "🤖 WhatsApp do robô" }));

    await waitFor(() => {
      expect(screen.getByRole("status")).toHaveTextContent("Enviada pelo robô para 5521999887766.");
    });

    const post = api.fetch.mock.calls.find(([url]) => String(url) === WHATSAPP);
    expect(JSON.parse(String(post![1]!.body))).toEqual({ id: 42, confirm: false });
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("pede confirmação antes de reenviar uma OS que o robô já mandou", async () => {
    const api = montar({
      [WHATSAPP]: {
        status: 409,
        body: {
          error: "Esta OS já foi enviada pelo WhatsApp do robô.",
          code: "whatsapp_already_sent",
          whatsapp_sent_at: "2026-09-03 14:22:00",
          whatsapp_sent_to: "5521999887766",
        },
      },
    });
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);
    await user.click(screen.getByRole("button", { name: "🤖 WhatsApp do robô" }));

    const dialogo = await screen.findByRole("dialog");
    expect(dialogo).toHaveTextContent("OS Nº 00042 já enviada");
    expect(dialogo).toHaveTextContent("5521999887766");
    expect(dialogo).toHaveTextContent("03/09/2026 14:22");

    // O reenvio só sai com `confirm: true` — a primeira chamada não gravou nada.
    await user.click(screen.getByRole("button", { name: "Enviar novamente" }));

    await waitFor(() => {
      const posts = api.fetch.mock.calls.filter(([url]) => String(url) === WHATSAPP);
      expect(JSON.parse(String(posts[1]![1]!.body))).toEqual({ id: 42, confirm: true });
    });
  });

  it("fecha a confirmação sem reenviar quando o operador cancela", async () => {
    const api = montar({
      [WHATSAPP]: {
        status: 409,
        body: { code: "whatsapp_already_sent", whatsapp_sent_at: "2026-09-03 14:22:00", whatsapp_sent_to: "5521999887766" },
      },
    });
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);
    await user.click(screen.getByRole("button", { name: "🤖 WhatsApp do robô" }));
    await user.click(await screen.findByRole("button", { name: "Cancelar" }));

    expect(screen.queryByRole("dialog")).toBeNull();
    expect(api.fetch.mock.calls.filter(([url]) => String(url) === WHATSAPP)).toHaveLength(1);
  });

  it("avisa quando o robô não está configurado no servidor", async () => {
    montar({
      [WHATSAPP]: {
        status: 503,
        body: { error: "O WhatsApp do robô não está configurado neste servidor.", code: "whatsapp_not_configured" },
      },
    });
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);
    await user.click(screen.getByRole("button", { name: "🤖 WhatsApp do robô" }));

    await waitFor(() => {
      expect(screen.getByRole("status")).toHaveTextContent("não está configurado");
    });
    expect(screen.queryByRole("dialog")).toBeNull();
  });
});

describe("/dashboard/os — janela de 24h do WhatsApp", () => {
  const comJanela = (window: unknown) => ({
    ...ordem,
    whatsapp_window: window,
  });

  const abrirCom = async (window: unknown) => {
    installApiMock({
      [ME]: { body: sessionOf("root") },
      [CLIENTS]: { body: { ok: true, clients: [{ id: 1, name: "Heineken" }] } },
      [OS]: { body: { ok: true, service_orders: [comJanela(window)] } },
    });
    render(<OSPage />);
    await abrirOS(userEvent.setup());

    return screen.getByRole("button", { name: "🤖 WhatsApp do robô" });
  };

  /**
   * Verde forte = janela aberta = envio gratuito. É o sinal que o usuário pediu
   * para conseguir decidir de longe se vale apertar agora.
   */
  it("pinta o botão de verde dentro da janela", async () => {
    const botao = await abrirCom({ open: true, expires_at: null, minutes_left: 300 });

    expect(botao.className).toContain("bg-[var(--color-accent)]");
    expect(botao).toHaveAttribute("title", expect.stringContaining("Dentro da janela de 24 horas"));
    expect(botao.getAttribute("title")).toContain("não cobra");
  });

  it("mantém o botão neutro fora da janela", async () => {
    const botao = await abrirCom({ open: false, expires_at: null, minutes_left: 0 });

    expect(botao.className).not.toContain("bg-[var(--color-accent)]");
    expect(botao.getAttribute("title")).toContain("Fora da janela de 24 horas");
    expect(botao.getAttribute("title")).toContain("cobrado");
  });

  it("trata cliente que nunca escreveu como fora da janela", async () => {
    const botao = await abrirCom(null);

    expect(botao.className).not.toContain("bg-[var(--color-accent)]");
    expect(botao.getAttribute("title")).toContain("nunca escreveu");
  });

  it("avisa quando a Meta recusa por estar fora da janela", async () => {
    montar({
      [WHATSAPP]: {
        status: 422,
        body: {
          error: "O cliente não escreve para este número há mais de 24 horas — fora da janela, a Meta só entrega template aprovado.",
          code: "whatsapp_outside_window",
        },
      },
    });
    render(<OSPage />);

    const user = userEvent.setup();
    await abrirOS(user);
    await user.click(screen.getByRole("button", { name: "🤖 WhatsApp do robô" }));

    await waitFor(() => {
      expect(screen.getByRole("status")).toHaveTextContent("mais de 24 horas");
    });
    expect(screen.queryByRole("dialog")).toBeNull();
  });
});
