import { describe, expect, it } from "vitest";
import { BILLING_CRON_STALE_HOURS, describeBillingRun } from "@/lib/billing-status";

const NOW = new Date("2026-10-03T15:00:00Z");

function ranHoursAgo(hours: number, overrides: Partial<{ generated: number; reminders: number; errors: number }> = {}) {
  return {
    at: new Date(NOW.getTime() - hours * 3_600_000).toISOString(),
    generated: 0,
    reminders: 0,
    errors: 0,
    ...overrides,
  };
}

describe("describeBillingRun", () => {
  it("avisa que o faturamento automático nunca rodou quando não há registro", () => {
    const status = describeBillingRun(null, NOW);

    expect(status.tone).toBe("warning");
    expect(status.message).toMatch(/ainda não registrou nenhuma execução/);
    expect(status.message).toMatch(/gere as faturas à mão/);
  });

  it("trata registro com data ilegível como se nunca tivesse rodado", () => {
    const status = describeBillingRun({ at: "ontem", generated: 1, reminders: 0, errors: 0 }, NOW);

    expect(status.tone).toBe("warning");
    expect(status.message).toMatch(/ainda não registrou nenhuma execução/);
  });

  it("mostra a execução recente no horário de Brasília", () => {
    const status = describeBillingRun(
      { at: "2026-10-03T11:00:00Z", generated: 3, reminders: 1, errors: 0 },
      NOW
    );

    expect(status.tone).toBe("ok");
    expect(status.message).toBe("Em dia. Última execução em 03/10/2026 às 08:00: 3 faturas geradas e 1 lembrete enviado.");
  });

  it("usa o singular e o plural certos", () => {
    const um = describeBillingRun(ranHoursAgo(2, { generated: 1, reminders: 1 }), NOW);
    const zero = describeBillingRun(ranHoursAgo(2), NOW);

    expect(um.message).toMatch(/1 fatura gerada e 1 lembrete enviado/);
    expect(zero.message).toMatch(/0 faturas geradas e 0 lembretes enviados/);
  });

  it("avisa das falhas da última execução e diz o que conferir", () => {
    const status = describeBillingRun(ranHoursAgo(3, { generated: 2, errors: 2 }), NOW);

    expect(status.tone).toBe("warning");
    expect(status.message).toMatch(/com 2 falhas/);
    expect(status.message).toMatch(/CPF\/CNPJ, e-mail ou WhatsApp/);
  });

  it("acusa o agendamento parado depois de 36 horas, e não antes", () => {
    const parado = describeBillingRun(ranHoursAgo(BILLING_CRON_STALE_HOURS + 1), NOW);
    const noLimite = describeBillingRun(ranHoursAgo(BILLING_CRON_STALE_HOURS - 1), NOW);

    expect(parado.tone).toBe("warning");
    expect(parado.message).toMatch(/não roda desde/);
    expect(noLimite.tone).toBe("ok");
  });
});
