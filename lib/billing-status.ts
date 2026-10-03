/**
 * O que a tela de Faturas diz sobre o faturamento automático.
 *
 * `api/invoices/index.php` devolve a última execução registrada pelo cron
 * (`billing_cron`, ou null se nunca houve). Esta peça traduz esse registro em um
 * aviso: é a única forma de a operadora saber, sem abrir o servidor, que o
 * agendamento existe e está rodando.
 */

/** A última execução do cron, como a API a devolve. */
export type BillingCronRun = {
  /** Instante da execução, em UTC, no formato ISO 8601. */
  at: string;
  generated: number;
  reminders: number;
  errors: number;
};

export type BillingCronStatus = {
  tone: "ok" | "warning";
  message: string;
};

/**
 * Sem execução há mais que isto, o agendamento é dado como parado. O cron é
 * diário: 36 horas deixam passar um atraso de horário sem acusar falso alarme.
 */
export const BILLING_CRON_STALE_HOURS = 36;

const TIME_ZONE = "America/Sao_Paulo";

function plural(count: number, singular: string, pluralForm: string): string {
  return `${count} ${count === 1 ? singular : pluralForm}`;
}

/** "03/10/2026 às 08:00", sempre no horário de Brasília. */
function formatWhen(date: Date): string {
  const day = new Intl.DateTimeFormat("pt-BR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    timeZone: TIME_ZONE,
  }).format(date);
  const time = new Intl.DateTimeFormat("pt-BR", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
    timeZone: TIME_ZONE,
  }).format(date);

  return `${day} às ${time}`;
}

export function describeBillingRun(run: BillingCronRun | null | undefined, now: Date): BillingCronStatus {
  const ranAt = run ? new Date(run.at) : null;

  if (!run || !ranAt || Number.isNaN(ranAt.getTime())) {
    return {
      tone: "warning",
      message:
        "O faturamento automático ainda não registrou nenhuma execução. Enquanto isso, as cobranças mensais não saem sozinhas: gere as faturas à mão abaixo e peça ao suporte técnico para conferir o agendamento diário no servidor.",
    };
  }

  const hoursSince = (now.getTime() - ranAt.getTime()) / 3_600_000;
  const when = formatWhen(ranAt);

  if (hoursSince > BILLING_CRON_STALE_HOURS) {
    return {
      tone: "warning",
      message: `O faturamento automático não roda desde ${when}. As cobranças mensais podem estar atrasadas: gere à mão as que forem urgentes e peça ao suporte técnico para conferir o agendamento no servidor.`,
    };
  }

  const summary = `${plural(run.generated, "fatura gerada", "faturas geradas")} e ${plural(run.reminders, "lembrete enviado", "lembretes enviados")}`;

  if (run.errors > 0) {
    return {
      tone: "warning",
      message: `Última execução em ${when}: ${summary}, com ${plural(run.errors, "falha", "falhas")}. Confira o cadastro dos clientes sem fatura (CPF/CNPJ, e-mail ou WhatsApp) e use "Tentar envios pendentes". O sistema tenta de novo na próxima execução.`,
    };
  }

  return {
    tone: "ok",
    message: `Em dia. Última execução em ${when}: ${summary}.`,
  };
}
