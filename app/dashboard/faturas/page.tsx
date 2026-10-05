"use client";

import { useEffect, useState } from "react";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import { apiPostJson } from "@/lib/dashboard-api";
import { describeBillingRun, type BillingCronRun, type BillingCronStatus } from "@/lib/billing-status";
import { formatOsDate } from "@/lib/os-share";
import { isAdmin } from "@/lib/authz";

type Invoice = {
  id: number;
  client_id: number;
  client_name: string;
  value: string;
  due_date: string;
  status: string;
  invoice_url: string;
};

/** Cliente ativo com valor mensal que ficou sem fatura no mês e cujo vencimento já passou. */
type MissingClient = {
  client_id: number;
  name: string;
  due_day: number;
  value: number;
  due_date: string;
};

/** Texto do selo de cada status que o Asaas pode devolver. */
const STATUS_LABEL: Record<string, string> = {
  RECEIVED: "PAGO",
  CONFIRMED: "CONFIRMADO",
  OVERDUE: "VENCIDA",
  PENDING: "PENDENTE",
  DELETED: "CANCELADA",
  REFUNDED: "ESTORNADA",
  CHARGEBACK_REQUESTED: "CONTESTADA",
};

/**
 * Cor do selo. O amarelo é só de PENDENTE, o estado em que ainda se espera o
 * dinheiro: estorno e contestação no mesmo amarelo pareciam cobrança em aberto.
 * CONFIRMADO é dinheiro garantido e fica verde, como PAGO.
 */
const STATUS_STYLE: Record<string, string> = {
  RECEIVED: "bg-green-500/20 text-green-400",
  CONFIRMED: "bg-green-500/20 text-green-400",
  OVERDUE: "bg-red-500/20 text-red-400",
  PENDING: "bg-yellow-500/20 text-yellow-400",
  DELETED: "bg-zinc-500/20 text-zinc-400",
  REFUNDED: "bg-sky-500/20 text-sky-300",
  CHARGEBACK_REQUESTED: "bg-orange-500/20 text-orange-300",
};

/** Status que a tela não conhece: neutro, para não parecer pendente. */
const STATUS_STYLE_DEFAULT = "bg-zinc-500/20 text-zinc-400";

export default function FaturasPage() {
  return (
    <DashboardGate>
      <FaturasMain />
    </DashboardGate>
  );
}

function FaturasMain() {
  const { user } = useDashboardAuth();
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [loading, setLoading] = useState(true);
  const isUserAdmin = isAdmin(user);
  const [clients, setClients] = useState<{ id: number; name: string; monthly_value: number }[]>([]);
  const [clientId, setClientId] = useState("");
  const [value, setValue] = useState("");
  const [dueDate, setDueDate] = useState("");
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState("");
  const [cronStatus, setCronStatus] = useState<BillingCronStatus | null>(null);
  const [missing, setMissing] = useState<MissingClient[]>([]);
  const [today, setToday] = useState("");

  /** Aplica a listagem da API: as faturas, o faturamento automático e quem ficou sem fatura. */
  function applyListing(data: {
    invoices: Invoice[];
    billing_cron?: BillingCronRun | null;
    billing_missing?: MissingClient[];
    today?: string;
  }) {
    setInvoices(data.invoices);
    setCronStatus(describeBillingRun(data.billing_cron ?? null, new Date()));
    setMissing(data.billing_missing ?? []);
    setToday(data.today ?? "");
  }

  /** Leva o cliente sem fatura para o formulário, com vencimento hoje (data de Brasília, vinda da API). */
  function prefill(m: MissingClient) {
    setClientId(String(m.client_id));
    setValue(String(m.value));
    setDueDate(today);
    document.getElementById("gerar-fatura")?.scrollIntoView({ block: "center" });
  }

  /**
   * Antes de gerar, avisa se o cliente já tem fatura no mesmo mês: gerar uma segunda por
   * engano (outra data no mesmo mês) cobra o cliente em dobro. O servidor permite, porque
   * uma cobrança extra é legítima, então quem confirma é a operadora.
   */
  function handleCreate() {
    const id = Number(clientId);
    const existing = invoices.find(
      inv => Number(inv.client_id) === id && inv.status !== "DELETED" && inv.due_date.slice(0, 7) === dueDate.slice(0, 7),
    );
    if (existing) {
      const valor = `R$ ${Number(existing.value).toFixed(2).replace(".", ",")}`;
      const ok = window.confirm(
        `${existing.client_name} já tem uma fatura neste mês, com vencimento em ${formatOsDate(existing.due_date)} (${valor}). Gerar outra mesmo assim?`,
      );
      if (!ok) return;
    }
    void submit({ action: "create", client_id: id, value: Number(value), due_date: dueDate });
  }

  async function submit(body: Record<string, unknown>) {
    setBusy(true); setFeedback("");
    try {
      const res = await apiPostJson("/api/invoices/index.php", body);
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || "Não foi possível processar a fatura.");
      if (body.action === "cancel") {
        setFeedback(data.message || "Fatura cancelada com sucesso.");
      } else {
        const problems = Object.values(data.delivery?.errors ?? {}).join(" ");
        setFeedback(problems ? `Fatura registrada. Há falha no envio: ${problems}` : "Fatura registrada e notificações processadas.");
      }
      const list = await fetch("/api/invoices/index.php");
      const result = await list.json();
      if (list.ok && result.ok) applyListing(result);
    } catch (e) { setFeedback(e instanceof Error ? e.message : "Erro de conexão."); }
    finally { setBusy(false); }
  }

  async function handleCancel(id: number) {
    if (!window.confirm("Deseja realmente cancelar esta fatura? A cobrança será cancelada no Asaas.")) return;
    await submit({ action: "cancel", id });
  }

  useEffect(() => {
    if (!isUserAdmin) return;
    
    fetch("/api/clients/index.php").then(res => res.json()).then(data => {
      if (data.ok) setClients(data.clients.filter((c: { status: string }) => c.status === "active"));
    }).catch(() => setFeedback("Erro ao carregar clientes."));
    fetch("/api/invoices/index.php")
      .then(res => res.json())
      .then(data => {
        if (data.ok) applyListing(data);
        else throw new Error(data.error || "Erro ao carregar faturas.");
      })
      .catch(e => setFeedback(e.message || "Erro ao carregar faturas."))
      .finally(() => setLoading(false));
  }, [isUserAdmin]);

  if (!isUserAdmin) return <div className="p-8 text-white">Acesso negado.</div>;

  return (
    <div className="max-w-6xl mx-auto p-6 sm:p-8 space-y-8 text-white">
      <div>
        <h1 className="text-3xl font-bold">Faturas</h1>
        <p className="text-[var(--color-text-on-dark)] mt-2">
          Acompanhamento das cobranças geradas. O status é sincronizado automaticamente via Webhook do Asaas.
        </p>
      </div>

      {cronStatus && (
        <section
          aria-labelledby="faturamento-automatico"
          className={`rounded-2xl border p-4 text-sm ${
            cronStatus.tone === "ok"
              ? "border-[var(--color-border-dark)] bg-white/5"
              : "border-yellow-500/40 bg-yellow-500/10"
          }`}
        >
          <h2 id="faturamento-automatico" className="font-semibold">Faturamento automático</h2>
          <p className="mt-1 text-[var(--color-text-on-dark)]">{cronStatus.message}</p>
        </section>
      )}

      {missing.length > 0 && (
        <section aria-labelledby="sem-fatura" className="rounded-2xl border border-yellow-500/40 bg-yellow-500/10 p-4 text-sm">
          <h2 id="sem-fatura" className="font-semibold">Clientes sem fatura neste mês</h2>
          <p className="mt-1 text-[var(--color-text-on-dark)]">
            O vencimento cadastrado já passou, e o faturamento automático não emite data passada (o Asaas recusa).
            Use &quot;Preencher fatura&quot; para gerar com vencimento hoje e confirme em &quot;Gerar e enviar fatura&quot;.
          </p>
          <ul className="mt-3 space-y-2">
            {missing.map(m => (
              <li key={m.client_id} className="flex flex-wrap items-center justify-between gap-2">
                <span>
                  <strong>{m.name}</strong>, vencia dia {m.due_day}, R$ {m.value.toFixed(2).replace(".", ",")}
                </span>
                <button type="button" onClick={() => prefill(m)} aria-label={`Preencher fatura de ${m.name}`} className="text-xs text-[var(--color-accent)] hover:underline">
                  Preencher fatura
                </button>
              </li>
            ))}
          </ul>
        </section>
      )}

      <form id="gerar-fatura" onSubmit={e => { e.preventDefault(); handleCreate(); }} className="grid gap-4 sm:grid-cols-3 p-6 border border-[var(--color-border-dark)] rounded-2xl">
        <h2 className="text-xl font-semibold sm:col-span-3">Gerar fatura</h2>
        <label>Cliente
          <select required value={clientId} onChange={e => { setClientId(e.target.value); const c = clients.find(c => c.id === Number(e.target.value)); setValue(String(c?.monthly_value ?? "")); }} className="block mt-2 w-full bg-[var(--color-bg-dark)] border border-[var(--color-border-dark)] rounded-lg p-2">
            <option value="">Selecione o cliente</option>
            {clients.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </label>
        <label>Valor (R$)<input required type="number" min="5" step="0.01" value={value} onChange={e => setValue(e.target.value)} onWheel={e => e.currentTarget.blur()} className="block mt-2 w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg p-2" /></label>
        <label>Vencimento<input required type="date" value={dueDate} onChange={e => setDueDate(e.target.value)} className="block mt-2 w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg p-2" /></label>
        <p className="sm:col-span-3 text-sm text-[var(--color-text-on-dark)]">Gera a cobrança no Asaas e envia por e-mail e WhatsApp conforme o cadastro. Uma fatura já existente para o mesmo cliente e vencimento é reutilizada.</p>
        <button disabled={busy} className="rounded-full bg-[var(--color-accent)] text-[var(--color-bg-dark)] px-5 py-2 font-semibold">{busy ? "Processando..." : "Gerar e enviar fatura"}</button>
      </form>
      {feedback && <p role="status" className="text-sm">{feedback}</p>}
      <div className="bg-[rgba(255,255,255,0.04)] rounded-2xl border border-[var(--color-border-dark)] overflow-hidden">
        <div className="data-table-wrap">
          <table className="data-table text-white/85">
            <thead className="bg-black/40 text-[var(--color-text-on-dark)]">
              <tr>
                <th>Cliente</th>
                <th>Vencimento</th>
                <th>Valor</th>
                <th>Status</th>
                <th><span className="sr-only">Ações</span></th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={5} className="data-table-empty py-8 text-center text-white/50">Carregando...</td></tr>
              ) : invoices.length === 0 ? (
                <tr><td colSpan={5} className="data-table-empty py-8 text-center text-white/50">Nenhuma fatura encontrada.</td></tr>
              ) : invoices.map(inv => (
                <tr key={inv.id} className="xl:hover:bg-white/5">
                  <td data-label="Cliente" className="font-medium text-white">{inv.client_name}</td>
                  <td data-label="Vencimento" className="whitespace-nowrap">{formatOsDate(inv.due_date)}</td>
                  <td data-label="Valor" className="whitespace-nowrap">R$ {Number(inv.value).toFixed(2).replace('.', ',')}</td>
                  <td data-label="Status">
                    <span className={`h-fit whitespace-nowrap px-2.5 py-1 rounded-full text-xs font-semibold ${STATUS_STYLE[inv.status] ?? STATUS_STYLE_DEFAULT}`}>
                      {STATUS_LABEL[inv.status] ?? inv.status}
                    </span>
                  </td>
                  <td className="data-table-actions">
                    <div className="flex flex-wrap items-center justify-end gap-x-4 gap-y-1">
                      {['PENDING', 'OVERDUE'].includes(inv.status) && (
                        <>
                          <button disabled={busy} onClick={() => void submit({ action: "send", id: inv.id })} className="text-xs text-[var(--color-accent)] hover:underline">
                            Tentar envios pendentes
                          </button>
                          <button disabled={busy} onClick={() => void handleCancel(inv.id)} className="text-xs text-red-400 hover:text-red-300 hover:underline">
                            Cancelar
                          </button>
                        </>
                      )}
                      {inv.invoice_url && (
                        <a href={inv.invoice_url} target="_blank" rel="noreferrer" className="text-[var(--color-accent)] hover:underline text-xs">
                          Ver Fatura
                        </a>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
