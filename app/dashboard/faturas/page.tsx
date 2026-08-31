"use client";

import { useEffect, useState } from "react";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
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

  useEffect(() => {
    if (!isUserAdmin) return;
    
    fetch("/api/invoices/index.php")
      .then(res => res.json())
      .then(data => {
        if (data.ok) setInvoices(data.invoices);
      })
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

      <div className="bg-[rgba(255,255,255,0.04)] rounded-2xl border border-[var(--color-border-dark)] overflow-hidden">
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
                <td className="px-6 py-4">{new Date(inv.due_date).toLocaleDateString('pt-BR')}</td>
                <td className="px-6 py-4">R$ {Number(inv.value).toFixed(2).replace('.', ',')}</td>
                <td className="px-6 py-4">
                  <span className={`px-2.5 py-1 rounded-full text-xs font-semibold ${
                    inv.status === 'RECEIVED' ? 'bg-green-500/20 text-green-400' :
                    inv.status === 'OVERDUE' ? 'bg-red-500/20 text-red-400' :
                    'bg-yellow-500/20 text-yellow-400'
                  }`}>
                    {inv.status === 'RECEIVED' ? 'PAGO' : inv.status === 'OVERDUE' ? 'VENCIDA' : 'PENDENTE'}
                  </span>
                </td>
                <td className="px-6 py-4 text-right">
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
