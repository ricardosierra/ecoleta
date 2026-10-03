import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import FaturasPage from "@/app/dashboard/faturas/page";
import { sessionOf } from "../support/api-mock";

function mockApi(delivery: object = { email: "sent", whatsapp: "accepted", errors: {} }, billingCron: object | null = null) {
  const posts: object[] = [];
  vi.stubGlobal("fetch", vi.fn(async (url: string, init?: RequestInit) => {
    if (url.includes("/auth/me.php")) return Response.json(sessionOf("root"));
    if (url.includes("/clients/")) return Response.json({ ok: true, clients: [{ id: 1, name: "Cliente QA", monthly_value: 7.5, status: "active" }] });
    if (init?.method === "POST") { posts.push(JSON.parse(String(init.body))); return Response.json({ ok: true, invoice: { id: 1 }, delivery }); }
    return Response.json({ ok: true, billing_cron: billingCron, invoices: [{ id: 1, client_id: 1, client_name: "Cliente QA", value: "7.50", due_date: "2026-09-10", status: "PENDING", invoice_url: "https://example.com/invoice" }] });
  }));
  return posts;
}

describe("Faturas", () => {
  it("preserva o dia e envia os dados da cobrança escolhida", async () => {
    const posts = mockApi(); const user = userEvent.setup(); render(<FaturasPage />);
    expect(await screen.findByText("10/09/2026")).toBeVisible();
    await user.selectOptions(screen.getByLabelText("Cliente"), "1");
    expect(screen.getByLabelText("Valor (R$)")).toHaveValue(7.5);
    await user.type(screen.getByLabelText("Vencimento"), "2026-09-12");
    await user.click(screen.getByRole("button", { name: "Gerar e enviar fatura" }));
    await waitFor(() => expect(posts).toEqual([{ action: "create", client_id: 1, value: 7.5, due_date: "2026-09-12" }]));
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
      expect(await screen.findByRole("status")).toHaveTextContent("Fatura registrada e notificações processadas.");
    });
  });
});
