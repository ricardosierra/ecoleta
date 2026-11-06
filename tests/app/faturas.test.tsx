import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import FaturasPage from "@/app/dashboard/faturas/page";
import { sessionOf } from "../support/api-mock";

/** Texto que a API devolve em `whatsapp_text` (o mesmo que o robô manda como texto). */
const TEXTO_COBRANCA = "Olá, Cliente QA.\n\nA ECOLEVA gerou uma cobrança para você. Valor: R$ 7,50. Vencimento: 10/09/2026.\n\nBoleto: https://example.com/invoice";

const FATURA_ABERTA = { id: 1, client_id: 1, client_name: "Cliente QA", client_whatsapp: "5521999193898", value: "7.50", due_date: "2026-09-10", created_at: "2026-09-01 14:30:00", status: "PENDING", invoice_url: "https://example.com/invoice", origin: "manual", delivery: { email: "sent", whatsapp: "sent" }, whatsapp_text: TEXTO_COBRANCA };

function mockApi(
  delivery: object = { email: "sent", whatsapp: "accepted", errors: {} },
  billingCron: object | null = null,
  listing: { billing_missing?: object[]; today?: string; invoices?: object[] } = {},
) {
  const posts: object[] = [];
  vi.stubGlobal("fetch", vi.fn(async (url: string, init?: RequestInit) => {
    if (url.includes("/auth/me.php")) return Response.json(sessionOf("root"));
    if (url.includes("/clients/")) return Response.json({ ok: true, clients: [{ id: 1, name: "Cliente QA", monthly_value: 7.5, status: "active" }] });
    if (init?.method === "POST") { posts.push(JSON.parse(String(init.body))); return Response.json({ ok: true, invoice: { id: 1 }, delivery }); }
    return Response.json({ ok: true, billing_cron: billingCron, ...listing, invoices: listing.invoices ?? [FATURA_ABERTA] });
  }));
  return posts;
}

describe("Faturas", () => {
  it("preserva o dia e envia os dados da cobrança escolhida", async () => {
    const posts = mockApi(); const user = userEvent.setup(); render(<FaturasPage />);
    const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(true);
    expect(await screen.findByText("10 de setembro de 2026")).toBeVisible();
    await user.click(screen.getByRole("button", { name: "Nova fatura avulsa" }));
    await user.selectOptions(screen.getByLabelText("Cliente"), "1");
    expect(screen.getByLabelText("Valor (R$)")).toHaveValue(7.5);
    await user.type(screen.getByLabelText("Vencimento"), "2026-09-12");
    await user.click(screen.getByRole("button", { name: "Gerar e enviar fatura" }));
    await waitFor(() => expect(posts).toEqual([{ action: "create", client_id: 1, value: 7.5, due_date: "2026-09-12", allow_duplicate_month: true }]));
    confirmSpy.mockRestore();
  });
  it("gera sem perguntar quando o cliente ainda não tem fatura no mês", async () => {
    const posts = mockApi(); const user = userEvent.setup(); render(<FaturasPage />);
    const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(true);
    await screen.findByText("10 de setembro de 2026");
    await user.click(screen.getByRole("button", { name: "Nova fatura avulsa" }));
    await user.selectOptions(screen.getByLabelText("Cliente"), "1");
    await user.type(screen.getByLabelText("Vencimento"), "2026-10-05");
    await user.click(screen.getByRole("button", { name: "Gerar e enviar fatura" }));
    await waitFor(() => expect(posts).toEqual([{ action: "create", client_id: 1, value: 7.5, due_date: "2026-10-05" }]));
    expect(confirmSpy).not.toHaveBeenCalled();
    confirmSpy.mockRestore();
  });
  it("não cria a mesma fatura duas vezes e orienta a reenviar a existente", async () => {
    const posts = mockApi(); const user = userEvent.setup(); render(<FaturasPage />);
    await screen.findByText("10 de setembro de 2026");
    await user.click(screen.getByRole("button", { name: "Nova fatura avulsa" }));
    await user.selectOptions(screen.getByLabelText("Cliente"), "1");
    await user.type(screen.getByLabelText("Vencimento"), "2026-09-10");
    await user.click(screen.getByRole("button", { name: "Gerar e enviar fatura" }));
    expect(posts).toEqual([]);
    expect(await screen.findByRole("alert")).toHaveTextContent(/já existe uma fatura com este vencimento e valor.*reenvie/i);
  });
  it("pergunta antes de gerar uma segunda fatura no mesmo mês e não gera se a operadora recusa", async () => {
    const posts = mockApi(); const user = userEvent.setup(); render(<FaturasPage />);
    const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(false);
    await screen.findByText("10 de setembro de 2026");
    await user.click(screen.getByRole("button", { name: "Nova fatura avulsa" }));
    await user.selectOptions(screen.getByLabelText("Cliente"), "1");
    await user.type(screen.getByLabelText("Vencimento"), "2026-09-20");
    await user.click(screen.getByRole("button", { name: "Gerar e enviar fatura" }));
    expect(confirmSpy).toHaveBeenCalledTimes(1);
    expect(confirmSpy.mock.calls[0][0]).toMatch(/Cliente QA já tem uma fatura neste mês, com vencimento em 10 de setembro de 2026 \(R\$ 7,50\)/);
    expect(posts).toEqual([]);
    confirmSpy.mockRestore();
  });
  it("lista quem ficou sem fatura no mês e preenche o formulário com vencimento hoje", async () => {
    mockApi(undefined, null, {
      today: "2026-10-06",
      billing_missing: [{ client_id: 1, name: "Cliente QA", due_day: 5, value: 170, due_date: "2026-10-05" }],
    });
    const user = userEvent.setup(); render(<FaturasPage />);
    const painel = await screen.findByRole("region", { name: "Clientes sem fatura neste mês" });
    expect(painel).toHaveTextContent("Cliente QA, vencia dia 5, R$ 170,00");
    expect(painel).toHaveTextContent("o faturamento automático não emite data passada");
    await user.click(screen.getByRole("button", { name: "Preencher fatura de Cliente QA" }));
    expect(screen.getByLabelText("Cliente")).toHaveValue("1");
    expect(screen.getByLabelText("Valor (R$)")).toHaveValue(170);
    expect(screen.getByLabelText("Vencimento")).toHaveValue("2026-10-06");
    expect(screen.getByRole("dialog", { name: "Nova fatura avulsa" })).toBeVisible();
  });
  it("reenvia só o e-mail da linha, sem gerar outra cobrança", async () => {
    const posts = mockApi();
    const user = userEvent.setup();
    const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(true);
    render(<FaturasPage />);
    await user.click(await screen.findByRole("button", { name: "Reenviar e-mail" }));
    expect(confirmSpy).toHaveBeenCalledWith(expect.stringMatching(/reenviar.*e-mail/i));
    await waitFor(() => expect(posts).toEqual([{ action: "deliver", id: 1, channel_actions: { email: "resend" } }]));
    expect(await screen.findByRole("status")).toHaveTextContent("E-mail reenviado.");
    confirmSpy.mockRestore();
  });
  it("não reenvia o e-mail quando a operadora cancela a confirmação", async () => {
    const posts = mockApi();
    const user = userEvent.setup();
    const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(false);
    render(<FaturasPage />);
    await user.click(await screen.findByRole("button", { name: "Reenviar e-mail" }));
    expect(confirmSpy).toHaveBeenCalled();
    expect(posts).toEqual([]);
    confirmSpy.mockRestore();
  });
  it("reenvia o WhatsApp da fatura após confirmação sem criar nova cobrança", async () => {
    const posts = mockApi();
    const user = userEvent.setup();
    const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(true);
    render(<FaturasPage />);
    await user.click(await screen.findByRole("button", { name: "Reenviar WhatsApp" }));
    expect(confirmSpy).toHaveBeenCalledWith(expect.stringMatching(/reenviar.*whatsapp/i));
    await waitFor(() => expect(posts).toEqual([{ action: "deliver", id: 1, channel_actions: { whatsapp: "resend" } }]));
    expect(await screen.findByRole("status")).toHaveTextContent(/WhatsApp enviado/);
    confirmSpy.mockRestore();
  });
  it("reenvia e-mail e WhatsApp juntos depois de uma confirmação", async () => {
    const posts = mockApi();
    const user = userEvent.setup();
    const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(true);
    render(<FaturasPage />);
    await user.click(await screen.findByRole("button", { name: "Reenviar e-mail e WhatsApp" }));
    expect(confirmSpy).toHaveBeenCalledWith(expect.stringMatching(/reenviar.*e-mail.*whatsapp/i));
    await waitFor(() => expect(posts).toEqual([{ action: "deliver", id: 1, channel_actions: { email: "resend", whatsapp: "resend" } }]));
    expect(await screen.findByRole("status")).toHaveTextContent(/E-mail reenviado.*WhatsApp enviado/);
    confirmSpy.mockRestore();
  });
  it("não mostra o painel quando todo cliente ativo tem fatura no mês", async () => {
    mockApi(undefined, null, { today: "2026-10-06", billing_missing: [] });
    render(<FaturasPage />);
    await screen.findByText("10 de setembro de 2026");
    expect(screen.queryByRole("region", { name: "Clientes sem fatura neste mês" })).not.toBeInTheDocument();
  });
  it("mostra falha de entrega mesmo quando a cobrança foi criada", async () => {
    mockApi({ email: "sent", whatsapp: "failed", errors: { whatsapp: "Template pendente." } });
    const user = userEvent.setup(); render(<FaturasPage />);
    await user.click(await screen.findByRole("button", { name: "Tentar envios pendentes" }));
    expect(await screen.findByRole("status")).toHaveTextContent("Fatura registrada. Há falha no envio: Template pendente.");
  });
  it("cancela a fatura pendente com confirmação e envia action cancel", async () => {
    const posts = mockApi();
    const user = userEvent.setup();
    const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(true);
    render(<FaturasPage />);
    const cancelBtn = await screen.findByRole("button", { name: "Cancelar" });
    await user.click(cancelBtn);
    expect(confirmSpy).toHaveBeenCalled();
    await waitFor(() => expect(posts).toEqual([{ action: "cancel", id: 1 }]));
    confirmSpy.mockRestore();
  });

  describe("faturamento automático", () => {
    const hoursAgo = (hours: number) => new Date(Date.now() - hours * 3_600_000).toISOString();

    it("avisa que o agendamento nunca rodou, para a operadora não esperar um boleto que não vem", async () => {
      mockApi(undefined, null);
      render(<FaturasPage />);

      const aviso = await screen.findByRole("region", { name: "Faturamento automático" });
      expect(aviso).toHaveTextContent("ainda não registrou nenhuma execução");
    });

    it("mostra a última execução quando o agendamento está em dia", async () => {
      mockApi(undefined, { at: hoursAgo(2), generated: 3, reminders: 0, errors: 0 });
      render(<FaturasPage />);

      const aviso = await screen.findByRole("region", { name: "Faturamento automático" });
      expect(aviso).toHaveTextContent("Em dia.");
      expect(aviso).toHaveTextContent("3 faturas geradas");
    });

    it("avisa quando o agendamento parou há mais de um dia e meio", async () => {
      mockApi(undefined, { at: hoursAgo(80), generated: 0, reminders: 0, errors: 0 });
      render(<FaturasPage />);

      const aviso = await screen.findByRole("region", { name: "Faturamento automático" });
      expect(aviso).toHaveTextContent("não roda desde");
    });

    it("não atrapalha o aviso de status da fatura gerada à mão", async () => {
      mockApi(undefined, { at: hoursAgo(2), generated: 0, reminders: 0, errors: 0 });
      const user = userEvent.setup();
      render(<FaturasPage />);

      await user.click(await screen.findByRole("button", { name: "Tentar envios pendentes" }));
      expect(await screen.findByRole("status")).toHaveTextContent("Fatura registrada e enviada.");
    });
  });
});

describe("Faturas: tabela e cores de status", () => {
  const fatura = (id: number, status: string) => ({
    id,
    client_id: 1,
    client_name: `Cliente ${status}`,
    value: "10.00",
    due_date: "2026-09-10",
    created_at: "2026-09-01 08:15:00",
    status,
    invoice_url: "https://example.com/invoice",
    origin: status === "PENDING" ? "manual" : "monthly",
  });

  /** Devolve as faturas dadas, para a tela mostrar vários selos de uma vez. */
  function mockComFaturas(invoices: object[]) {
    vi.stubGlobal("fetch", vi.fn(async (url: string) => {
      if (url.includes("/auth/me.php")) return Response.json(sessionOf("root"));
      if (url.includes("/clients/")) return Response.json({ ok: true, clients: [] });
      return Response.json({ ok: true, billing_cron: null, invoices });
    }));
  }

  /**
   * O amarelo é o de "pendente", o estado em que ainda se espera o dinheiro. Um
   * estorno ou uma contestação no mesmo amarelo parecia uma cobrança em aberto.
   */
  it("só a fatura pendente usa o amarelo de pendente", async () => {
    mockComFaturas(["RECEIVED", "CONFIRMED", "OVERDUE", "PENDING", "DELETED", "REFUNDED", "CHARGEBACK_REQUESTED"].map((s, i) => fatura(i + 1, s)));
    render(<FaturasPage />);

    const selos: Record<string, string> = {};
    for (const rotulo of ["PAGO", "CONFIRMADO", "VENCIDA", "PENDENTE", "CANCELADA", "ESTORNADA", "CONTESTADA"]) {
      selos[rotulo] = (await screen.findByText(rotulo)).className;
    }

    expect(selos.PENDENTE).toContain("yellow");
    for (const rotulo of ["PAGO", "CONFIRMADO", "VENCIDA", "CANCELADA", "ESTORNADA", "CONTESTADA"]) {
      expect(selos[rotulo], rotulo).not.toContain("yellow");
    }
    // Confirmado é dinheiro garantido, como pago.
    expect(selos.CONFIRMADO).toBe(selos.PAGO);
    // Estornada e contestada não se confundem com as outras.
    const distintos = new Set([selos.PAGO, selos.VENCIDA, selos.PENDENTE, selos.CANCELADA, selos.ESTORNADA, selos.CONTESTADA]);
    expect(distintos.size).toBe(6);
  });

  it("usa a tabela de dados do dashboard, com o cabeçalho de cada célula para a tela estreita", async () => {
    mockComFaturas([fatura(1, "PENDING")]);
    render(<FaturasPage />);

    const tabela = await screen.findByRole("table");
    expect(tabela).toHaveClass("data-table");
    expect(tabela.parentElement).toHaveClass("data-table-wrap");

    const linha = (await screen.findByText("Cliente PENDING")).closest("tr")!;
    const rotulos = Array.from(linha.querySelectorAll("td[data-label]")).map(td => td.getAttribute("data-label"));
    expect(rotulos).toEqual(["Cliente", "Criada em", "Vencimento", "Valor", "Tipo", "Status"]);
    expect(linha).toHaveTextContent("1 de setembro de 2026 às 08:15");
    expect(linha).toHaveTextContent("10 de setembro de 2026");
    expect(linha).toHaveTextContent("Avulsa");
    expect(linha.querySelector("[title='Cobrança avulsa']")).not.toBeNull();
    expect(linha.querySelector("td.data-table-actions")).not.toBeNull();
  });

  it("avisa na linha quando o WhatsApp não chegou", async () => {
    mockComFaturas([{ ...fatura(1, "PENDING"), delivery: { email: "sent", whatsapp: "failed", whatsapp_error: "Número sem WhatsApp." } }]);
    render(<FaturasPage />);
    expect(await screen.findByRole("alert")).toHaveTextContent("WhatsApp não chegou: Número sem WhatsApp.");
    expect(screen.getByRole("button", { name: "Tentar WhatsApp de novo" })).toBeVisible();
  });

  it("atalho da conversa só aparece para quem vê o painel de WhatsApp", async () => {
    mockComFaturas([{ ...fatura(1, "PENDING"), whatsapp_conversation_id: 9, client_whatsapp: "5511999999999" }]);
    render(<FaturasPage />);
    const atalho = await screen.findByRole("link", { name: "Abrir conversa no WhatsApp" });
    expect(atalho).toHaveAttribute("href", "/dashboard/whatsapp?conversation_id=9");
    expect(atalho).toHaveAttribute("title", "Abrir conversa no WhatsApp");
  });

  it("filtra por status e ordena pelo cliente", async () => {
    mockComFaturas([fatura(1, "PENDING"), fatura(2, "RECEIVED")]);
    const user = userEvent.setup();
    render(<FaturasPage />);
    expect(await screen.findByText("Cliente PENDING")).toBeVisible();
    expect(screen.getByText("Cliente RECEIVED")).toBeVisible();
    await user.selectOptions(screen.getByRole("combobox", { name: "Status" }), "PENDING");
    expect(screen.getByText("Cliente PENDING")).toBeVisible();
    expect(screen.queryByText("Cliente RECEIVED")).not.toBeInTheDocument();
    await user.selectOptions(screen.getByRole("combobox", { name: "Status" }), "");
    await user.click(screen.getByRole("button", { name: /Cliente/ }));
    const nomes = screen.getAllByText(/Cliente /).map(el => el.textContent);
    expect(nomes[0]).toBe("Cliente PENDING");
  });

  it("centraliza a mensagem de tabela vazia como as outras telas", async () => {
    mockComFaturas([]);
    render(<FaturasPage />);

    const vazio = await screen.findByText("Nenhuma fatura encontrada.");
    expect(vazio).toHaveClass("data-table-empty");
  });
});

describe("Meu WhatsApp pelo botão direito da linha", () => {
  it("abre o WhatsApp do operador com a cobrança escrita, sem chamar o servidor", async () => {
    const posts = mockApi(); const user = userEvent.setup(); render(<FaturasPage />);
    const open = vi.spyOn(window, "open").mockImplementation(() => null);
    await screen.findByText("10 de setembro de 2026");

    fireEvent.contextMenu(screen.getByText("Cliente QA").closest("tr")!);
    await user.click(within(screen.getByRole("menu", { name: "Ações" })).getByRole("menuitem", { name: "Enviar pelo Meu WhatsApp" }));

    expect(open).toHaveBeenCalledWith(`https://wa.me/5521999193898?text=${encodeURIComponent(TEXTO_COBRANCA)}`, "_blank", "noopener,noreferrer");
    expect(posts).toEqual([]);
    expect(screen.queryByRole("menu")).not.toBeInTheDocument();
    open.mockRestore();
  });

  it("Esc fecha o menu sem abrir o WhatsApp", async () => {
    mockApi(); const user = userEvent.setup(); render(<FaturasPage />);
    const open = vi.spyOn(window, "open").mockImplementation(() => null);
    await screen.findByText("10 de setembro de 2026");

    fireEvent.contextMenu(screen.getByText("Cliente QA").closest("tr")!);
    expect(screen.getByRole("menu", { name: "Ações" })).toBeVisible();
    await user.keyboard("{Escape}");

    expect(screen.queryByRole("menu")).not.toBeInTheDocument();
    expect(open).not.toHaveBeenCalled();
    open.mockRestore();
  });

  it("fatura já paga não oferece o Meu WhatsApp, só o que a linha mostra", async () => {
    mockApi(undefined, null, { invoices: [{ ...FATURA_ABERTA, id: 2, client_name: "Cliente PAGO", status: "RECEIVED", whatsapp_text: null }] });
    render(<FaturasPage />);
    await screen.findByText("Cliente PAGO");

    fireEvent.contextMenu(screen.getByText("Cliente PAGO").closest("tr")!);

    const menu = screen.getByRole("menu", { name: "Ações" });
    expect(within(menu).getByRole("menuitem", { name: "Ver fatura" })).toBeInTheDocument();
    expect(within(menu).queryByRole("menuitem", { name: "Enviar pelo Meu WhatsApp" })).not.toBeInTheDocument();
  });
});
