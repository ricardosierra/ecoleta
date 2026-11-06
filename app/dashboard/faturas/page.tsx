"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import { DashboardModal, ModalActions } from "@/components/DashboardModal";
import { BotIcon, ClockIcon, EyeIcon, MailIcon, PlusIcon, TrashIcon, WhatsAppIcon } from "@/components/icons";
import { apiPostJson } from "@/lib/dashboard-api";
import { describeBillingRun, type BillingCronRun, type BillingCronStatus } from "@/lib/billing-status";
import { invoiceWhatsAppLink } from "@/lib/invoice-share";
import { canViewWhatsAppPanel, isAdmin } from "@/lib/authz";

type DeliveryChannel = "email" | "whatsapp";
type DeliveryState = "never_sent" | "sent" | "uncertain" | "failed";
type ChannelActions = Partial<Record<DeliveryChannel, "send" | "resend">>;

type Invoice = {
  id: number;
  client_id: number;
  client_name: string;
  value: string;
  due_date: string;
  created_at?: string | null;
  status: string;
  invoice_url: string;
  /** `monthly` = ciclo automático; `manual` = gerada na tela. */
  origin?: "monthly" | "manual";
  delivery?: Record<DeliveryChannel, DeliveryState> & { whatsapp_error?: string | null; email_error?: string | null };
  /** Conversa já aberta no painel, quando o cliente tem uma. */
  whatsapp_conversation_id?: number | null;
  client_whatsapp?: string | null;
  /** Texto da cobrança para o "Meu WhatsApp" (só fatura em aberto). */
  whatsapp_text?: string | null;
};

const ORIGIN_LABEL: Record<"monthly" | "manual", string> = {
  monthly: "Mensal",
  manual: "Avulsa",
};

const ORIGIN_HINT: Record<"monthly" | "manual", string> = {
  monthly: "Cobrança mensal automática",
  manual: "Cobrança avulsa",
};

/** Botão só de ícone: o nome fica no tooltip e no leitor de tela. */
const iconActionClass = "inline-flex h-7 w-7 items-center justify-center rounded-full text-white/75 transition-colors hover:bg-white/10 hover:text-white disabled:opacity-40";

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

function deliveryAction(state: DeliveryState): "send" | "resend" | null {
  if (state === "uncertain") return null;
  return state === "sent" ? "resend" : "send";
}

function channelActionLabel(channel: DeliveryChannel, state: DeliveryState): string {
  const name = channel === "email" ? "e-mail" : "WhatsApp";
  if (state === "uncertain") return `Conferir envio do ${name}`;
  if (state === "failed") return "Tentar WhatsApp de novo";
  return `${state === "sent" ? "Reenviar" : "Enviar"} ${name}`;
}

/** Mostra o número como a operadora disca, para o aviso dizer para onde foi. */
const MESES = [
  "janeiro", "fevereiro", "março", "abril", "maio", "junho",
  "julho", "agosto", "setembro", "outubro", "novembro", "dezembro",
];

/**
 * Data do banco em português, sem passar por `Date` (meia-noite UTC vira o dia anterior em Brasília).
 * Com hora, inclui "às HH:MM".
 */
function dataLegivel(value?: string | null, comHora = false): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec((value ?? "").trim());
  if (!match || match[1] === "0000") return "—";
  const mes = MESES[Number(match[2]) - 1];
  if (!mes) return "—";
  const texto = `${Number(match[3])} de ${mes} de ${match[1]}`;
  return comHora && match[4] ? `${texto} às ${match[4]}:${match[5]}` : texto;
}

type SortKey = "client_name" | "created_at" | "due_date" | "value" | "origin" | "status";

const SORT_LABEL: Record<SortKey, string> = {
  client_name: "Cliente",
  created_at: "Criada em",
  due_date: "Vencimento",
  value: "Valor",
  origin: "Tipo",
  status: "Status",
};

const DELIVERY_FILTER: { value: "" | DeliveryState; label: string }[] = [
  { value: "", label: "Todos" },
  { value: "sent", label: "Enviado" },
  { value: "never_sent", label: "Não enviado" },
  { value: "failed", label: "Falhou" },
  { value: "uncertain", label: "Incerto" },
];

const fieldClass = "mt-1 block w-full rounded-lg border border-[var(--color-border-dark)] bg-black/20 p-2 text-sm";

function formatWhatsApp(phone?: string | null): string {
  const digits = (phone ?? "").replace(/\D/g, "");
  const local = digits.startsWith("55") && digits.length >= 12 ? digits.slice(2) : digits;
  if (local.length === 11) return `(${local.slice(0, 2)}) ${local.slice(2, 7)}-${local.slice(7)}`;
  if (local.length === 10) return `(${local.slice(0, 2)}) ${local.slice(2, 6)}-${local.slice(6)}`;
  return digits;
}

function combinedActionLabel(delivery: Record<DeliveryChannel, DeliveryState>): string | null {
  const emailAction = deliveryAction(delivery.email);
  const whatsappAction = deliveryAction(delivery.whatsapp);
  if (!emailAction || !whatsappAction) return null;
  if (emailAction === whatsappAction) {
    return `${emailAction === "send" ? "Enviar" : "Reenviar"} e-mail e WhatsApp`;
  }
  return `${emailAction === "send" ? "Enviar" : "Reenviar"} e-mail e ${whatsappAction === "send" ? "enviar" : "reenviar"} WhatsApp`;
}

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
  const podeWhatsApp = canViewWhatsAppPanel(user);
  const [clients, setClients] = useState<{ id: number; name: string; monthly_value: number }[]>([]);
  const [clientId, setClientId] = useState("");
  const [value, setValue] = useState("");
  const [dueDate, setDueDate] = useState("");
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState("");
  const [cronStatus, setCronStatus] = useState<BillingCronStatus | null>(null);
  const [missing, setMissing] = useState<MissingClient[]>([]);
  const [today, setToday] = useState("");
  const [composerOpen, setComposerOpen] = useState(false);
  const [composerError, setComposerError] = useState("");
  const [busca, setBusca] = useState("");
  const [filtroStatus, setFiltroStatus] = useState("");
  const [filtroTipo, setFiltroTipo] = useState("");
  const [filtroEmail, setFiltroEmail] = useState<"" | DeliveryState>("");
  const [filtroWhatsapp, setFiltroWhatsapp] = useState<"" | DeliveryState>("");
  const [vencimentoDe, setVencimentoDe] = useState("");
  const [vencimentoAte, setVencimentoAte] = useState("");
  const [criadaDe, setCriadaDe] = useState("");
  const [criadaAte, setCriadaAte] = useState("");
  const [valorMin, setValorMin] = useState("");
  const [valorMax, setValorMax] = useState("");
  const [sortKey, setSortKey] = useState<SortKey>("due_date");
  const [sortDir, setSortDir] = useState<"asc" | "desc">("desc");

  const filtrosAtivos = Boolean(
    busca || filtroStatus || filtroTipo || filtroEmail || filtroWhatsapp
    || vencimentoDe || vencimentoAte || criadaDe || criadaAte || valorMin || valorMax,
  );

  function limparFiltros() {
    setBusca("");
    setFiltroStatus("");
    setFiltroTipo("");
    setFiltroEmail("");
    setFiltroWhatsapp("");
    setVencimentoDe("");
    setVencimentoAte("");
    setCriadaDe("");
    setCriadaAte("");
    setValorMin("");
    setValorMax("");
  }

  function alternarOrdem(key: SortKey) {
    if (sortKey === key) setSortDir(dir => dir === "asc" ? "desc" : "asc");
    else {
      setSortKey(key);
      setSortDir(key === "client_name" || key === "origin" || key === "status" ? "asc" : "desc");
    }
  }

  const visiveis = useMemo(() => {
    const texto = busca.trim().toLocaleLowerCase("pt-BR");
    const min = valorMin === "" ? null : Number(valorMin);
    const max = valorMax === "" ? null : Number(valorMax);
    const lista = invoices.filter(inv => {
      if (texto && !inv.client_name.toLocaleLowerCase("pt-BR").includes(texto)) return false;
      if (filtroStatus && inv.status !== filtroStatus) return false;
      if (filtroTipo && inv.origin !== filtroTipo) return false;
      const delivery = inv.delivery ?? { email: "never_sent" as const, whatsapp: "never_sent" as const };
      if (filtroEmail && delivery.email !== filtroEmail) return false;
      if (filtroWhatsapp && delivery.whatsapp !== filtroWhatsapp) return false;
      const vencimento = inv.due_date.slice(0, 10);
      if (vencimentoDe && vencimento < vencimentoDe) return false;
      if (vencimentoAte && vencimento > vencimentoAte) return false;
      const criada = (inv.created_at ?? "").slice(0, 10);
      if (criadaDe && (criada === "" || criada < criadaDe)) return false;
      if (criadaAte && (criada === "" || criada > criadaAte)) return false;
      const valor = Number(inv.value);
      if (min !== null && !Number.isNaN(min) && valor < min) return false;
      if (max !== null && !Number.isNaN(max) && valor > max) return false;
      return true;
    });
    const fator = sortDir === "asc" ? 1 : -1;
    lista.sort((a, b) => {
      let cmp = 0;
      if (sortKey === "value") cmp = Number(a.value) - Number(b.value);
      else if (sortKey === "status") cmp = (STATUS_LABEL[a.status] ?? a.status).localeCompare(STATUS_LABEL[b.status] ?? b.status, "pt-BR");
      else if (sortKey === "origin") cmp = (ORIGIN_LABEL[a.origin ?? "monthly"]).localeCompare(ORIGIN_LABEL[b.origin ?? "monthly"], "pt-BR");
      else if (sortKey === "client_name") cmp = a.client_name.localeCompare(b.client_name, "pt-BR");
      else cmp = (a[sortKey] ?? "").localeCompare(b[sortKey] ?? "", "pt-BR");
      return cmp * fator || b.id - a.id;
    });
    return lista;
  }, [invoices, busca, filtroStatus, filtroTipo, filtroEmail, filtroWhatsapp, vencimentoDe, vencimentoAte, criadaDe, criadaAte, valorMin, valorMax, sortKey, sortDir]);

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
  function openComposer(prefill?: { clientId: string; value: string; dueDate: string }) {
    setComposerError("");
    setClientId(prefill?.clientId ?? "");
    setValue(prefill?.value ?? "");
    setDueDate(prefill?.dueDate ?? today);
    setComposerOpen(true);
  }

  /**
   * Antes de gerar, avisa se o cliente já tem fatura no mesmo mês: gerar uma segunda por
   * engano (outra data no mesmo mês) cobra o cliente em dobro. O servidor permite, porque
   * uma cobrança extra é legítima, então quem confirma é a operadora.
   */
  function handleCreate() {
    const id = Number(clientId);
    const sameInvoice = invoices.find(
      inv => Number(inv.client_id) === id && inv.status !== "DELETED" && inv.due_date === dueDate && Math.abs(Number(inv.value) - Number(value)) < 0.001,
    );
    if (sameInvoice) {
      setComposerError("Já existe uma fatura com este vencimento e valor. Feche este formulário e reenvie a fatura existente.");
      return;
    }
    const existing = invoices.find(
      inv => Number(inv.client_id) === id && inv.status !== "DELETED" && inv.due_date.slice(0, 7) === dueDate.slice(0, 7),
    );
    let allowDuplicateMonth = false;
    if (existing) {
      const valor = `R$ ${Number(existing.value).toFixed(2).replace(".", ",")}`;
      const ok = window.confirm(
        `${existing.client_name} já tem uma fatura neste mês, com vencimento em ${dataLegivel(existing.due_date)} (${valor}). Gerar outra mesmo assim?`,
      );
      if (!ok) return;
      allowDuplicateMonth = true;
    }
    setComposerOpen(false);
    void submit({ action: "create", client_id: id, value: Number(value), due_date: dueDate, ...(allowDuplicateMonth ? { allow_duplicate_month: true } : {}) });
  }

  /** Confirma o envio ou reenvio conforme o histórico de cada canal. */
  async function handleDelivery(invoice: Invoice, channels: DeliveryChannel[]) {
    const delivery: Record<DeliveryChannel, DeliveryState> = invoice.delivery ?? { email: "never_sent", whatsapp: "never_sent" };
    const channelActions: ChannelActions = {};
    for (const channel of channels) {
      const action = deliveryAction(delivery[channel]);
      if (action) channelActions[channel] = action;
    }
    if (Object.keys(channelActions).length === 0) return;

    const numero = formatWhatsApp(invoice.client_whatsapp);
    const description = Object.entries(channelActions).map(([channel, action]) => {
      if (channel === "whatsapp") {
        const verbo = action === "send" ? "enviar" : "reenviar";
        return numero ? `${verbo} pelo WhatsApp para ${numero}` : `${verbo} pelo WhatsApp`;
      }
      return `${action === "send" ? "enviar" : "reenviar"} por e-mail`;
    }).join(" e ");
    if (!window.confirm(`Confirma ${description}? Nenhuma nova cobrança será criada.`)) return;
    await submit({ action: "deliver", id: invoice.id, channel_actions: channelActions });
  }

  async function submit(body: Record<string, unknown>) {
    setBusy(true); setFeedback("");
    try {
      const res = await apiPostJson("/api/invoices/index.php", body);
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || "Não foi possível processar a fatura.");
      if (body.action === "cancel") {
        setFeedback(data.message || "Fatura cancelada com sucesso.");
      } else if (body.action === "resend_email") {
        const delivery = data.delivery ?? {};
        const problem = delivery.errors?.email;
        setFeedback(problem ? `O e-mail não saiu: ${problem}` : delivery.email === "sent" ? "E-mail reenviado." : "O e-mail não saiu nesta tentativa.");
      } else if (body.action === "deliver") {
        const delivery = data.delivery ?? {};
        const errors = delivery.errors ?? {};
        const actions = body.channel_actions as ChannelActions;
        const destino = formatWhatsApp(delivery.whatsapp_to);
        const results = Object.entries(actions).map(([channel, action]) => {
          if (channel === "email") {
            if (errors.email) return `E-mail não saiu: ${errors.email}`;
            if (delivery.email === "sent") return action === "resend" ? "E-mail reenviado." : "E-mail enviado.";
            if (delivery.email === "already_sent") return "E-mail já havia sido enviado.";
            return "E-mail não saiu nesta tentativa.";
          }
          const para = destino ? ` para ${destino}` : "";
          if (errors.whatsapp) return `WhatsApp não saiu${para}: ${errors.whatsapp}`;
          if (delivery.whatsapp === "accepted") return `WhatsApp enviado${para}.`;
          if (delivery.whatsapp === "already_sent") return `WhatsApp já tinha sido enviado${para}.`;
          return `WhatsApp não saiu${para}.`;
        });
        setFeedback(results.join(" "));
      } else if (body.action === "resend") {
        const delivery = data.delivery ?? {};
        const errors = delivery.errors ?? {};
        const channels = body.channels as ("email" | "whatsapp")[];
        const results = channels.map(channel => {
          if (errors[channel]) return `${channel === "email" ? "Falha no e-mail" : "Falha no WhatsApp"}: ${errors[channel]}`;
          if (channel === "email") return delivery.email === "sent" ? "E-mail reenviado." : "E-mail já processado.";
          return delivery.whatsapp === "accepted" ? "WhatsApp: solicitação aceita pela Meta." : "WhatsApp já processado.";
        });
        setFeedback(results.join(" "));
      } else {
        const delivery = data.delivery ?? {};
        const problems = Object.values(delivery.errors ?? {}).join(" ");
        const states = [delivery.email, delivery.whatsapp];
        const sent = states.some((s: string) => s === "sent" || s === "accepted");
        setFeedback(
          problems
            ? `Fatura registrada. Há falha no envio: ${problems}`
            : sent
              ? "Fatura registrada e enviada."
              : "Fatura registrada. Nenhum envio saiu nesta tentativa.",
        );
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

  /**
   * Abre o WhatsApp do próprio operador com a cobrança escrita. Não passa pelo servidor
   * nem pela API da Meta, então não tem custo e não registra envio: quem aperta enviar é o operador.
   */
  function enviarPeloMeuWhatsApp(invoice: Invoice) {
    window.open(invoiceWhatsAppLink(invoice.client_whatsapp, invoice.whatsapp_text ?? ""), "_blank", "noopener,noreferrer");
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
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">Faturas</h1>
          <p className="text-[var(--color-text-on-dark)] mt-2">
            Acompanhamento das cobranças geradas. O status é sincronizado automaticamente via Webhook do Asaas.
          </p>
        </div>
        <button type="button" onClick={() => openComposer()} className="rounded-full bg-[var(--color-accent)] text-[var(--color-bg-dark)] px-5 py-2.5 font-semibold">
          Nova fatura avulsa
        </button>
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
            Use &quot;Preencher fatura&quot; para abrir o cadastro com vencimento hoje.
          </p>
          <ul className="mt-3 space-y-2">
            {missing.map(m => (
              <li key={m.client_id} className="flex flex-wrap items-center justify-between gap-2">
                <span>
                  <strong>{m.name}</strong>, vencia dia {m.due_day}, R$ {m.value.toFixed(2).replace(".", ",")}
                </span>
                <button type="button" onClick={() => openComposer({ clientId: String(m.client_id), value: String(m.value), dueDate: today })} aria-label={`Preencher fatura de ${m.name}`} className="text-xs text-[var(--color-accent)] hover:underline">
                  Preencher fatura
                </button>
              </li>
            ))}
          </ul>
        </section>
      )}

      {composerOpen && (
        <DashboardModal
          as="form"
          title="Nova fatura avulsa"
          size="lg"
          onClose={() => setComposerOpen(false)}
          onSubmit={e => { e.preventDefault(); handleCreate(); }}
        >
          <div className="grid gap-4 sm:grid-cols-3">
            <label className="sm:col-span-3">Cliente
              <select required value={clientId} onChange={e => { setClientId(e.target.value); const c = clients.find(c => c.id === Number(e.target.value)); setValue(String(c?.monthly_value ?? "")); }} className="block mt-2 w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg p-2">
                <option value="">Selecione o cliente</option>
                {clients.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </label>
            <label className="sm:col-span-1">Valor (R$)
              <input required type="number" min="5" step="0.01" value={value} onChange={e => setValue(e.target.value)} onWheel={e => e.currentTarget.blur()} className="block mt-2 w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg p-2" />
            </label>
            <label className="sm:col-span-2">Vencimento
              <input required type="date" value={dueDate} onChange={e => setDueDate(e.target.value)} className="block mt-2 w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg p-2" />
            </label>
          </div>
          {composerError && <p role="alert" className="mt-4 text-sm text-red-300">{composerError}</p>}
          <p className="mt-4 text-sm text-[var(--color-text-on-dark)]">Gera a cobrança no Asaas e envia o e-mail e o WhatsApp do cadastro. Uma segunda fatura no mesmo mês pede confirmação; para o mesmo vencimento e valor, use os botões de envio da fatura existente.</p>
          <ModalActions>
            <button type="button" onClick={() => setComposerOpen(false)} className="rounded-full px-5 py-2 text-sm text-white/70 hover:bg-white/5">Cancelar</button>
            <button disabled={busy} className="rounded-full bg-[var(--color-accent)] text-[var(--color-bg-dark)] px-5 py-2 font-semibold">{busy ? "Processando..." : "Gerar e enviar fatura"}</button>
          </ModalActions>
        </DashboardModal>
      )}
      {feedback && (
        <p role={/não saiu|não chegou|Falha/i.test(feedback) ? "alert" : "status"} className="text-sm">{feedback}</p>
      )}
      <form className="grid gap-3 rounded-2xl border border-[var(--color-border-dark)] bg-white/5 p-4 sm:grid-cols-2 lg:grid-cols-4" onSubmit={e => e.preventDefault()} aria-label="Filtros das faturas">
        <label className="sm:col-span-2">Buscar cliente
          <input value={busca} onChange={e => setBusca(e.target.value)} placeholder="Nome do cliente" className={fieldClass} />
        </label>
        <label>Status
          <select value={filtroStatus} onChange={e => setFiltroStatus(e.target.value)} className={fieldClass}>
            <option value="">Todos</option>
            {Object.entries(STATUS_LABEL).map(([code, label]) => <option key={code} value={code}>{label}</option>)}
          </select>
        </label>
        <label>Tipo
          <select value={filtroTipo} onChange={e => setFiltroTipo(e.target.value)} className={fieldClass}>
            <option value="">Todos</option>
            <option value="monthly">Mensal</option>
            <option value="manual">Avulsa</option>
          </select>
        </label>
        <label>E-mail
          <select value={filtroEmail} onChange={e => setFiltroEmail(e.target.value as "" | DeliveryState)} className={fieldClass}>
            {DELIVERY_FILTER.map(op => <option key={op.value || "todos"} value={op.value}>{op.label}</option>)}
          </select>
        </label>
        <label>WhatsApp
          <select value={filtroWhatsapp} onChange={e => setFiltroWhatsapp(e.target.value as "" | DeliveryState)} className={fieldClass}>
            {DELIVERY_FILTER.map(op => <option key={op.value || "todos"} value={op.value}>{op.label}</option>)}
          </select>
        </label>
        <label>Vencimento de
          <input type="date" value={vencimentoDe} onChange={e => setVencimentoDe(e.target.value)} className={fieldClass} />
        </label>
        <label>Vencimento até
          <input type="date" value={vencimentoAte} onChange={e => setVencimentoAte(e.target.value)} className={fieldClass} />
        </label>
        <label>Criada de
          <input type="date" value={criadaDe} onChange={e => setCriadaDe(e.target.value)} className={fieldClass} />
        </label>
        <label>Criada até
          <input type="date" value={criadaAte} onChange={e => setCriadaAte(e.target.value)} className={fieldClass} />
        </label>
        <label>Valor mínimo
          <input type="number" min="0" step="0.01" value={valorMin} onChange={e => setValorMin(e.target.value)} onWheel={e => e.currentTarget.blur()} className={fieldClass} />
        </label>
        <label>Valor máximo
          <input type="number" min="0" step="0.01" value={valorMax} onChange={e => setValorMax(e.target.value)} onWheel={e => e.currentTarget.blur()} className={fieldClass} />
        </label>
        <div className="flex flex-wrap items-end justify-between gap-3 sm:col-span-2 lg:col-span-4">
          <p className="text-sm text-[var(--color-text-on-dark)]">
            {loading ? "Carregando…" : `${visiveis.length} de ${invoices.length} faturas · ordenado por ${SORT_LABEL[sortKey].toLocaleLowerCase("pt-BR")}, ${sortDir === "asc" ? "crescente" : "decrescente"}`}
          </p>
          {filtrosAtivos && (
            <button type="button" onClick={limparFiltros} className="rounded-full px-4 py-2 text-sm text-white/80 hover:bg-white/10">Limpar filtros</button>
          )}
        </div>
      </form>

      <div className="bg-[rgba(255,255,255,0.04)] rounded-2xl border border-[var(--color-border-dark)] overflow-hidden">
        <div className="data-table-wrap">
          <table className="data-table data-table-slim text-white/85">
            <thead className="bg-black/40 text-[var(--color-text-on-dark)]">
              <tr>
                {(["client_name", "created_at", "due_date", "value", "origin", "status"] as SortKey[]).map(key => (
                  <th key={key} aria-sort={sortKey === key ? (sortDir === "asc" ? "ascending" : "descending") : "none"}>
                    <button type="button" onClick={() => alternarOrdem(key)} className="inline-flex items-center gap-1 uppercase tracking-wide hover:text-white">
                      {SORT_LABEL[key]}
                      {sortKey === key ? (sortDir === "asc" ? "↑" : "↓") : ""}
                    </button>
                  </th>
                ))}
                <th><span className="sr-only">Ações</span></th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={7} className="data-table-empty py-8 text-center text-white/50">Carregando...</td></tr>
              ) : invoices.length === 0 ? (
                <tr><td colSpan={7} className="data-table-empty py-8 text-center text-white/50">Nenhuma fatura encontrada.</td></tr>
              ) : visiveis.length === 0 ? (
                <tr><td colSpan={7} className="data-table-empty py-8 text-center text-white/50">Nenhuma fatura com esses filtros.</td></tr>
              ) : visiveis.map(inv => {
                const delivery: Record<DeliveryChannel, DeliveryState> = inv.delivery ?? { email: "never_sent", whatsapp: "never_sent" };
                const combinedLabel = combinedActionLabel(delivery);
                const aberta = ["PENDING", "OVERDUE"].includes(inv.status);
                const emailPendente = delivery.email === "never_sent" || delivery.email === "failed";
                const whatsappPendente = delivery.whatsapp === "never_sent" || delivery.whatsapp === "failed";
                const semEnvio = aberta && (emailPendente || whatsappPendente);
                const comErro = delivery.email === "failed" || delivery.whatsapp === "failed";
                const avisos: string[] = [];
                if (aberta && delivery.email === "never_sent") avisos.push("E-mail ainda não enviado.");
                if (aberta && delivery.whatsapp === "never_sent") avisos.push("WhatsApp ainda não enviado.");
                if (delivery.email === "failed") avisos.push(`E-mail não saiu${inv.delivery?.email_error ? `: ${inv.delivery.email_error}` : ""}.`);
                if (delivery.whatsapp === "failed") avisos.push(`WhatsApp não chegou${inv.delivery?.whatsapp_error ? `: ${inv.delivery.whatsapp_error}` : ""}.`);
                const tomIcone = (state: DeliveryState) => state === "failed"
                  ? "text-red-300 ring-1 ring-red-400/70"
                  : state === "never_sent" && aberta
                    ? "text-amber-200 ring-1 ring-amber-300/70"
                    : "";
                return (
                <tr key={inv.id} className={`xl:hover:bg-white/5 ${semEnvio ? (comErro ? "bg-red-500/10" : "bg-amber-400/10") : ""}`}>
                  <td data-label="Cliente" className="font-medium text-white">{inv.client_name}</td>
                  <td data-label="Criada em" className="whitespace-nowrap">{dataLegivel(inv.created_at, true)}</td>
                  <td data-label="Vencimento" className="whitespace-nowrap">{dataLegivel(inv.due_date)}</td>
                  <td data-label="Valor" className="whitespace-nowrap">R$ {Number(inv.value).toFixed(2).replace('.', ',')}</td>
                  <td data-label="Tipo" className="whitespace-nowrap">
                    {inv.origin ? (
                      <span title={ORIGIN_HINT[inv.origin]} className="inline-flex h-7 w-7 items-center justify-center text-[var(--color-accent)]">
                        {inv.origin === "manual" ? <PlusIcon width={15} height={15} /> : <ClockIcon width={15} height={15} />}
                        <span className="sr-only">{ORIGIN_LABEL[inv.origin]}</span>
                      </span>
                    ) : "—"}
                  </td>
                  <td data-label="Status">
                    <span className={`h-fit whitespace-nowrap px-2.5 py-1 rounded-full text-xs font-semibold ${STATUS_STYLE[inv.status] ?? STATUS_STYLE_DEFAULT}`}>
                      {STATUS_LABEL[inv.status] ?? inv.status}
                    </span>
                  </td>
                  <td className="data-table-actions">
                    <div className="flex flex-wrap items-center justify-end gap-0.5">
                      {['PENDING', 'OVERDUE'].includes(inv.status) && (
                        <>
                          {delivery.email === "uncertain" ? (
                            <span title="Confira o provedor antes de tentar novamente para evitar envio duplicado." className={`${iconActionClass} text-yellow-300`}>
                              <MailIcon width={15} height={15} />
                              <span className="sr-only">{channelActionLabel("email", delivery.email)}</span>
                            </span>
                          ) : (
                            <button type="button" disabled={busy} title={channelActionLabel("email", delivery.email)} aria-label={channelActionLabel("email", delivery.email)} onClick={() => void handleDelivery(inv, ["email"])} className={`${iconActionClass} ${tomIcone(delivery.email)}`}>
                              <MailIcon width={15} height={15} />
                            </button>
                          )}
                          {delivery.whatsapp === "uncertain" ? (
                            <span title="Confira o provedor antes de tentar novamente para evitar envio duplicado." className={`${iconActionClass} text-yellow-300`}>
                              <BotIcon width={15} height={15} />
                              <span className="sr-only">{channelActionLabel("whatsapp", delivery.whatsapp)}</span>
                            </span>
                          ) : (
                            <button type="button" disabled={busy} title={channelActionLabel("whatsapp", delivery.whatsapp)} aria-label={channelActionLabel("whatsapp", delivery.whatsapp)} onClick={() => void handleDelivery(inv, ["whatsapp"])} className={`${iconActionClass} ${tomIcone(delivery.whatsapp)}`}>
                              <BotIcon width={15} height={15} />
                            </button>
                          )}
                          {combinedLabel && (
                            <button type="button" disabled={busy} title={combinedLabel} aria-label={combinedLabel} onClick={() => void handleDelivery(inv, ["email", "whatsapp"])} className={iconActionClass}>
                              <MailIcon width={13} height={13} />
                              <BotIcon width={13} height={13} />
                            </button>
                          )}
                          <button type="button" disabled={busy} title="Cancelar" aria-label="Cancelar" onClick={() => void handleCancel(inv.id)} className={`${iconActionClass} text-red-400 hover:text-red-300`}>
                            <TrashIcon width={15} height={15} />
                          </button>
                        </>
                      )}
                      {podeWhatsApp && (inv.whatsapp_conversation_id || inv.client_whatsapp) && (
                        <Link
                          href={inv.whatsapp_conversation_id
                            ? `/dashboard/whatsapp?conversation_id=${inv.whatsapp_conversation_id}`
                            : `/dashboard/whatsapp?phone=${encodeURIComponent(inv.client_whatsapp ?? "")}&client_id=${inv.client_id}`}
                          title="Abrir conversa no WhatsApp"
                          aria-label="Abrir conversa no WhatsApp"
                          className={`${iconActionClass} text-[var(--color-accent)]`}
                        >
                          <WhatsAppIcon width={15} height={15} />
                        </Link>
                      )}
                      {inv.invoice_url && (
                        <a href={inv.invoice_url} target="_blank" rel="noreferrer" title="Ver fatura" aria-label="Ver fatura" className={iconActionClass}>
                          <EyeIcon width={15} height={15} />
                        </a>
                      )}
                      {inv.whatsapp_text && (
                        // Sem botão visível: o menu de botão direito da linha (DashboardQuickActions) dispara este controle.
                        <button type="button" hidden data-quick-only aria-label="Enviar pelo Meu WhatsApp" onClick={() => enviarPeloMeuWhatsApp(inv)} />
                      )}
                      {avisos.length > 0 && (
                        <p role={comErro ? "alert" : "status"} className={`basis-full text-right text-xs ${comErro ? "text-red-300" : "text-amber-200"}`}>
                          {avisos.join(" ")}
                        </p>
                      )}
                    </div>
                  </td>
                </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
