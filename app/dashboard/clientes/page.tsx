"use client";

import { useEffect, useState } from "react";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import { isAdmin } from "@/lib/authz";

type Client = {
  id: number;
  name: string;
  email: string | null;
  whatsapp: string | null;
  document: string | null;
  monthly_value: number;
};

export default function ClientesPage() {
  return (
    <DashboardGate>
      <ClientesMain />
    </DashboardGate>
  );
}

function ClientesMain() {
  const { user } = useDashboardAuth();
  const [clients, setClients] = useState<Client[]>([]);
  const [loading, setLoading] = useState(true);
  
  // Form states
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [whatsapp, setWhatsapp] = useState("");
  const [document, setDocument] = useState("");
  const [monthlyValue, setMonthlyValue] = useState("0");
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  const isUserAdmin = isAdmin(user);

  useEffect(() => {
    if (!isUserAdmin) return;
    
    fetch("/api/clients/index.php")
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          setClients(data.clients);
        }
      })
      .finally(() => setLoading(false));
  }, [isUserAdmin]);

  if (!isUserAdmin) {
    return <div className="p-8 text-white">Acesso negado.</div>;
  }

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setSuccess("");
    
    try {
      const res = await fetch("/api/clients/index.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name, email, whatsapp, document, monthly_value: parseFloat(monthlyValue)
        })
      });
      const data = await res.json();
      
      if (res.ok && data.ok) {
        setSuccess("Cliente cadastrado com sucesso.");
        setClients([data.client, ...clients]);
        setName("");
        setEmail("");
        setWhatsapp("");
        setDocument("");
        setMonthlyValue("0");
      } else {
        setError(data.error || "Erro ao cadastrar cliente.");
      }
    } catch {
      setError("Erro de comunicação com o servidor.");
    }
  };

  return (
    <div className="max-w-6xl mx-auto p-6 sm:p-8 space-y-8 text-white">
      <div>
        <h1 className="text-3xl font-bold">Clientes</h1>
        <p className="text-[var(--color-text-on-dark)] mt-2">
          Gestão de clientes e faturamento automático. Ao cadastrar um cliente, ele será sincronizado com o Asaas.
        </p>
      </div>

      <div className="bg-[rgba(255,255,255,0.04)] rounded-2xl border border-[var(--color-border-dark)] p-6">
        <h2 className="text-xl font-semibold mb-4">Novo Cliente</h2>
        <form onSubmit={handleCreate} className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]">Nome / Empresa *</label>
            <input required value={name} onChange={e => setName(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
          </div>
          <div>
            <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]">E-mail</label>
            <input type="email" value={email} onChange={e => setEmail(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
          </div>
          <div>
            <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]">WhatsApp</label>
            <input value={whatsapp} onChange={e => setWhatsapp(e.target.value)} placeholder="Ex: 5511999999999" className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
          </div>
          <div>
            <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]">CPF/CNPJ</label>
            <input value={document} onChange={e => setDocument(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
          </div>
          <div>
            <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]">Valor Mensal Fixo (R$)</label>
            <input type="number" step="0.01" min="0" value={monthlyValue} onChange={e => setMonthlyValue(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
          </div>
          
          <div className="sm:col-span-2 flex items-center justify-between mt-4">
            <div>
              {error && <span className="text-red-400 text-sm">{error}</span>}
              {success && <span className="text-[var(--color-accent)] text-sm">{success}</span>}
            </div>
            <button type="submit" className="bg-[var(--color-accent)] text-[var(--color-bg-dark)] px-5 py-2 rounded-full font-semibold hover:opacity-90 transition">
              Salvar Cliente
            </button>
          </div>
        </form>
      </div>

      <div className="bg-[rgba(255,255,255,0.04)] rounded-2xl border border-[var(--color-border-dark)] overflow-hidden">
        <table className="w-full text-left text-sm whitespace-nowrap">
          <thead className="bg-black/40 text-[var(--color-text-on-dark)] border-b border-[var(--color-border-dark)]">
            <tr>
              <th className="px-6 py-3 font-medium">Nome</th>
              <th className="px-6 py-3 font-medium">Contato</th>
              <th className="px-6 py-3 font-medium">Fatura (R$)</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[var(--color-border-dark)]">
            {loading ? (
              <tr><td colSpan={3} className="px-6 py-4 text-center text-white/50">Carregando...</td></tr>
            ) : clients.length === 0 ? (
              <tr><td colSpan={3} className="px-6 py-4 text-center text-white/50">Nenhum cliente cadastrado.</td></tr>
            ) : clients.map(client => (
              <tr key={client.id} className="hover:bg-white/5">
                <td className="px-6 py-4">
                  <p className="font-semibold">{client.name}</p>
                  <p className="text-xs text-white/50">{client.document || "Sem documento"}</p>
                </td>
                <td className="px-6 py-4">
                  <p>{client.email || "-"}</p>
                  <p className="text-xs text-white/50">{client.whatsapp || "-"}</p>
                </td>
                <td className="px-6 py-4">
                  R$ {Number(client.monthly_value).toFixed(2).replace('.', ',')}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
