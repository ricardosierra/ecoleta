import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import FaturasPage from "@/app/dashboard/faturas/page";
import { sessionOf } from "../support/api-mock";

function mockApi(delivery: object = { email: "sent", whatsapp: "accepted", errors: {} }) {
  const posts: object[] = [];
  vi.stubGlobal("fetch", vi.fn(async (url: string, init?: RequestInit) => {
    if (url.includes("/auth/me.php")) return Response.json(sessionOf("root"));
    if (url.includes("/clients/")) return Response.json({ ok: true, clients: [{ id: 1, name: "Cliente QA", monthly_value: 7.5, status: "active" }] });
    if (init?.method === "POST") { posts.push(JSON.parse(String(init.body))); return Response.json({ ok: true, invoice: { id: 1 }, delivery }); }
    return Response.json({ ok: true, invoices: [{ id: 1, client_id: 1, client_name: "Cliente QA", value: "7.50", due_date: "2026-09-10", status: "PENDING", invoice_url: "https://example.com/invoice" }] });
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
});
