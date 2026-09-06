"use client";

import { useEffect, useState } from "react";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import { isAdmin } from "@/lib/authz";
import { apiPostJson } from "@/lib/dashboard-api";
import { normalizePhone } from "@/lib/phone";

type Client = {
  id: number;
  name: string;
  email: string | null;
  whatsapp: string | null;
  document: string | null;
  monthly_value: number;
  due_day: number;
  status: "active" | "inactive";
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
  const [editingId, setEditingId] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  
  // Form states
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [whatsapp, setWhatsapp] = useState("");
  const [document, setDocument] = useState("");
  const [monthlyValue, setMonthlyValue] = useState("0");
  const [dueDay, setDueDay] = useState("10");
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  const isUserAdmin = isAdmin(user);

  /** Cliente com mensalidade só é faturável com CPF/CNPJ — ver o campo abaixo. */
  const cobraMensalidade = parseFloat(monthlyValue) > 0;

  useEffect(() => {
    if (!isUserAdmin) return;
    
    fetch("/api/clients/index.php")
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          setClients(data.clients);
        } else { throw new Error(data.error || "Erro ao carregar clientes."); }
      })
      .catch(e => setError(e.message || "Erro de conexão."))
      .finally(() => setLoading(false));
  }, [isUserAdmin]);

  if (!isUserAdmin) {
    return <div className="p-8 text-white">Acesso negado.</div>;
  }

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setSuccess("");
    setSubmitting(true);
    try {
      const res = await apiPostJson(editingId === null ? "/api/clients/index.php" : "/api/clients/edit.php", {
        ...(editingId === null ? {} : { client_id: editingId }),
        name,
        email,
        whatsapp: normalizePhone(whatsapp),
        document,
        monthly_value: parseFloat(monthlyValue),
        due_day: parseInt(dueDay, 10) || 10
      });
      const data = await res.json();
      
      if (res.ok && data.ok) {
        setSuccess(editingId === null ? "Cliente cadastrado com sucesso." : "Cliente atualizado com sucesso.");
        setClients(current => editingId === null ? [data.client, ...current] : current.map(c => c.id === editingId ? data.client : c));
        setEditingId(null);
        setName("");
        setEmail("");
        setWhatsapp("");
        setDocument("");
        setMonthlyValue("0");
        setDueDay("10");
      } else {
        setError(data.error || "Erro ao cadastrar cliente.");
      }
    } catch {
      setError("Erro de comunicação com o servidor.");
    } finally { setSubmitting(false); }
  };

  const handleToggleStatus = async (client: Client) => {
    const next = client.status === "active" ? "inactive" : "active";
    setError("");

    try {
      const res = await apiPostJson("/api/clients/edit.php", {
        client_id: client.id,
        status: next
      });
      const data = await res.json();

      if (res.ok && data.ok) {
        setClients(prev => prev.map(c => c.id === client.id ? { ...c, status: next } : c));
      } else {
        setError(data.error || "Erro ao atualizar status do cliente.");
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
        <h2 className="text-xl font-semibold mb-4">{editingId === null ? "Novo Cliente" : "Editar Cliente"}</h2>
        {editingId !== null && <button type="button" onClick={() => { setEditingId(null); setName(""); setEmail(""); setWhatsapp(""); setDocument(""); setMonthlyValue("0"); setDueDay("10"); }} className="mb-4 text-sm text-[var(--color-accent)]">Cancelar edição</button>}
        <form onSubmit={handleCreate} className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]" htmlFor="cliente-nome">Nome / Empresa *</label>
            <input id="cliente-nome" required value={name} onChange={e => setName(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
          </div>
          <div>
            <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]" htmlFor="cliente-email">E-mail</label>
            <input id="cliente-email" type="email" value={email} onChange={e => setEmail(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
          </div>
          <div>
            <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]" htmlFor="cliente-whatsapp">WhatsApp</label>
            <input
              id="cliente-whatsapp"
              value={whatsapp}
              onChange={e => setWhatsapp(e.target.value)}
              onBlur={e => setWhatsapp(normalizePhone(e.target.value))}
              placeholder="Ex: 5511999999999"
              className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]"
            />
          </div>
          <div>
            <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]" htmlFor="cliente-documento">
              CPF/CNPJ{cobraMensalidade ? " *" : ""}
            </label>
            {/* Obrigatório assim que há valor mensal: o Asaas cadastra o cliente
                sem documento mas recusa gerar a cobrança, e a recusa só
                apareceria no dia 30, no log do cron. */}
            <input id="cliente-documento" required={cobraMensalidade} value={document} onChange={e => setDocument(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
          </div>
          <div>
            <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]" htmlFor="cliente-valor">Valor Mensal Fixo (R$)</label>
            <input id="cliente-valor" type="number" step="0.01" min="0" value={monthlyValue} onChange={e => setMonthlyValue(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
            <p className="text-xs text-white/40 mt-1">Só clientes ativos e com valor positivo entram na cobrança automática.</p>
          </div>
          <div>
            <label className="block text-sm mb-1 text-[var(--color-text-on-dark)]" htmlFor="cliente-vencimento">Dia de Vencimento</label>
            <input id="cliente-vencimento" type="number" min="1" max="31" value={dueDay} onChange={e => setDueDay(e.target.value)} className="w-full bg-black/20 border border-[var(--color-border-dark)] rounded-lg px-3 py-2 outline-none focus:border-[var(--color-accent)]" />
            <p className="text-xs text-white/40 mt-1">Dia do mês em que a fatura vence (1 a 31).</p>
          </div>

          <div className="sm:col-span-2 flex items-center justify-between mt-4">
            <div>
              {error && <span className="text-red-400 text-sm">{error}</span>}
              {success && <span className="text-[var(--color-accent)] text-sm">{success}</span>}
            </div>
            <button type="submit" disabled={submitting} className="bg-[var(--color-accent)] text-[var(--color-bg-dark)] px-5 py-2 rounded-full font-semibold hover:opacity-90 transition">
              {submitting ? "Salvando..." : "Salvar Cliente"}
            </button>
          </div>
        </form>
      </div>

      <div className="bg-[rgba(255,255,255,0.04)] rounded-2xl border border-[var(--color-border-dark)] overflow-x-auto">
        <table className="w-full text-left text-sm whitespace-nowrap">
          <thead className="bg-black/40 text-[var(--color-text-on-dark)] border-b border-[var(--color-border-dark)]">
            <tr>
              <th className="px-6 py-3 font-medium">Nome</th>
              <th className="px-6 py-3 font-medium">Contato</th>
              <th className="px-6 py-3 font-medium">Fatura (R$)</th>
              <th className="px-6 py-3 font-medium">Vencimento</th>
              <th className="px-6 py-3 font-medium">Status</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[var(--color-border-dark)]">
            {loading ? (
              <tr><td colSpan={5} className="px-6 py-4 text-center text-white/50">Carregando...</td></tr>
            ) : clients.length === 0 ? (
              <tr><td colSpan={5} className="px-6 py-4 text-center text-white/50">Nenhum cliente cadastrado.</td></tr>
            ) : clients.map(client => (
              <tr key={client.id} className="hover:bg-white/5">
                <td className="px-6 py-4">
                  <p className="font-semibold">{client.name}</p>
                  <p className="text-xs text-white/50">{client.document || "Sem documento"}</p>
                  <button aria-label={`Editar ${client.name}`} className="mt-2 text-xs text-[var(--color-accent)]" onClick={() => {
                    setEditingId(client.id); setName(client.name); setEmail(client.email ?? ""); setWhatsapp(client.whatsapp ?? "");
                    setDocument(client.document ?? ""); setMonthlyValue(String(client.monthly_value)); setDueDay(String(client.due_day)); setError(""); setSuccess("");
                  }}>Editar</button>
                </td>
                <td className="px-6 py-4">
                  <p>{client.email || "-"}</p>
                  <p className="text-xs text-white/50">{client.whatsapp || "-"}</p>
                </td>
                <td className="px-6 py-4">
                  R$ {Number(client.monthly_value).toFixed(2).replace('.', ',')}
                </td>
                <td className="px-6 py-4">
                  Dia {client.due_day}
                </td>
                <td className="px-6 py-4">
                  <div className="flex items-center gap-3">
                    <span className={client.status === "active"
                      ? "inline-block px-2 py-0.5 rounded-full text-xs font-semibold bg-[var(--color-accent)]/15 text-[var(--color-accent)]"
                      : "inline-block px-2 py-0.5 rounded-full text-xs font-semibold bg-white/10 text-white/50"}>
                      {client.status === "active" ? "Ativo" : "Inativo"}
                    </span>
                    <button
                      type="button"
                      onClick={() => handleToggleStatus(client)}
                      className="text-xs text-white/50 hover:text-white underline underline-offset-2 transition"
                    >
                      {client.status === "active" ? "Desativar" : "Ativar"}
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
