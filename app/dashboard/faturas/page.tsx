"use client";

import { useEffect, useState } from "react";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import { apiPostJson } from "@/lib/dashboard-api";
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

  async function submit(body: Record<string, unknown>) {
    setBusy(true); setFeedback("");
    try {
      const res = await apiPostJson("/api/invoices/index.php", body);
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || "Não foi possível gerar a fatura.");
      const problems = Object.values(data.delivery?.errors ?? {}).join(" ");
      setFeedback(problems ? `Fatura registrada. Há falha no envio: ${problems}` : "Fatura registrada e notificações processadas.");
      const list = await fetch("/api/invoices/index.php");
      const result = await list.json();
      if (list.ok && result.ok) setInvoices(result.invoices);
    } catch (e) { setFeedback(e instanceof Error ? e.message : "Erro de conexão."); }
    finally { setBusy(false); }
  }

  useEffect(() => {
    if (!isUserAdmin) return;
    
    fetch("/api/clients/index.php").then(res => res.json()).then(data => {
      if (data.ok) setClients(data.clients.filter((c: { status: string }) => c.status === "active"));
    }).catch(() => setFeedback("Erro ao carregar clientes."));
    fetch("/api/invoices/index.php")
      .then(res => res.json())
      .then(data => {
        if (data.ok) setInvoices(data.invoices);
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

      <form onSubmit={e => { e.preventDefault(); void submit({ action: "create", client_id: Number(clientId), value: Number(value), due_date: dueDate }); }} className="grid gap-4 sm:grid-cols-3 p-6 border border-[var(--color-border-dark)] rounded-2xl">
        <h2 className="text-xl font-semibold sm:col-span-3">Gerar fatura</h2>
        <label>Cliente
          <select required value={clientId} onChange={e => { setClientId(e.target.value); const c = clients.find(c => c.id === Number(e.target.value)); setValue(String(c?.monthly_value ?? "")); }} className="block mt-2 w-full bg-[var(--color-bg-dark)] border border-[var(--color-border-dark)] rounded-lg p-2">
            <option value="">Selecione o cliente</option>
            {clients.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </label>
        <label>Valor (R$)<input required type="number" min="5" step="0.01" value={value} onChange={e => setValue(e.target.value)} className="block mt-2 w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg p-2" /></label>
        <label>Vencimento<input required type="date" value={dueDate} onChange={e => setDueDate(e.target.value)} className="block mt-2 w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg p-2" /></label>
        <p className="sm:col-span-3 text-sm text-[var(--color-text-on-dark)]">Gera a cobrança no Asaas e envia por e-mail e WhatsApp conforme o cadastro. Uma fatura já existente para o mesmo cliente e vencimento é reutilizada.</p>
        <button disabled={busy} className="rounded-full bg-[var(--color-accent)] text-[var(--color-bg-dark)] px-5 py-2 font-semibold">{busy ? "Processando..." : "Gerar e enviar fatura"}</button>
      </form>
      {feedback && <p role="status" className="text-sm">{feedback}</p>}
      <div className="bg-[rgba(255,255,255,0.04)] rounded-2xl border border-[var(--color-border-dark)] overflow-x-auto">
        <table className="w-full text-left text-sm whitespace-nowrap">
          <thead className="bg-black/40 text-[var(--color-text-on-dark)] border-b border-[var(--color-border-dark)]">
            <tr>
              <th className="px-6 py-3 font-medium">Cliente</th>
              <th className="px-6 py-3 font-medium">Vencimento</th>
              <th className="px-6 py-3 font-medium">Valor</th>
              <th className="px-6 py-3 font-medium">Status</th>
              <th className="px-6 py-3 font-medium"></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[var(--color-border-dark)]">
            {loading ? (
              <tr><td colSpan={5} className="px-6 py-4 text-center text-white/50">Carregando...</td></tr>
            ) : invoices.length === 0 ? (
              <tr><td colSpan={5} className="px-6 py-4 text-center text-white/50">Nenhuma fatura encontrada.</td></tr>
            ) : invoices.map(inv => (
              <tr key={inv.id} className="hover:bg-white/5">
                <td className="px-6 py-4 font-medium">{inv.client_name}</td>
                <td className="px-6 py-4">{formatOsDate(inv.due_date)}</td>
                <td className="px-6 py-4">R$ {Number(inv.value).toFixed(2).replace('.', ',')}</td>
                <td className="px-6 py-4">
                  <span className={`px-2.5 py-1 rounded-full text-xs font-semibold ${
                    inv.status === 'RECEIVED' ? 'bg-green-500/20 text-green-400' :
                    inv.status === 'OVERDUE' ? 'bg-red-500/20 text-red-400' :
                    'bg-yellow-500/20 text-yellow-400'
                  }`}>
                    {{ RECEIVED: 'PAGO', CONFIRMED: 'CONFIRMADO', OVERDUE: 'VENCIDA', PENDING: 'PENDENTE', DELETED: 'CANCELADA', REFUNDED: 'ESTORNADA', CHARGEBACK_REQUESTED: 'CONTESTADA' }[inv.status] ?? inv.status}
                  </span>
                </td>
                <td className="px-6 py-4 text-right">
                  {['PENDING', 'OVERDUE'].includes(inv.status) && <button disabled={busy} onClick={() => void submit({ action: "send", id: inv.id })} className="mr-4 text-xs text-[var(--color-accent)]">Tentar envios pendentes</button>}
                  {inv.invoice_url && (
                    <a href={inv.invoice_url} target="_blank" rel="noreferrer" className="text-[var(--color-accent)] hover:underline text-xs">
                      Ver Fatura
                    </a>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
