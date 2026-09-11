"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import { DashboardAccessDenied } from "@/components/DashboardAccessDenied";
import { PencilIcon, PlusIcon } from "@/components/icons";
import { isAdmin } from "@/lib/authz";
import { apiPostJson } from "@/lib/dashboard-api";

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
  const [error, setError] = useState("");
  const [statusTogglingId, setStatusTogglingId] = useState<number | null>(null);

  const isUserAdmin = isAdmin(user);

  useEffect(() => {
    if (!isUserAdmin) return;

    fetch("/api/clients/index.php")
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          setClients(data.clients);
        } else {
          throw new Error(data.error || "Erro ao carregar clientes.");
        }
      })
      .catch(e => setError(e.message || "Erro de conexão."))
      .finally(() => setLoading(false));
  }, [isUserAdmin]);

  if (!isUserAdmin) {
    return <DashboardAccessDenied area="a gestão de clientes" />;
  }

  const handleToggleStatus = async (client: Client) => {
    const next = client.status === "active" ? "inactive" : "active";
    setError("");
    setStatusTogglingId(client.id);

    try {
      const res = await apiPostJson("/api/clients/edit.php", {
        client_id: client.id,
        status: next
      });
      const data = await res.json();

      if (res.ok && data.ok) {
        setClients(prev => prev.map(c => (c.id === client.id ? { ...c, status: next } : c)));
      } else {
        setError(data.error || "Erro ao atualizar status do cliente.");
      }
    } catch {
      setError("Erro de comunicação com o servidor.");
    } finally {
      setStatusTogglingId(null);
    }
  };

  return (
    <div className="max-w-6xl mx-auto p-6 sm:p-8 space-y-8 text-white">
      {/* Cabeçalho */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">Clientes</h1>
          <p className="text-[var(--color-text-on-dark)] mt-1.5 max-w-2xl">
            Gestão de clientes e faturamento. Cadastre novos clientes e configure faturas automáticas via Asaas.
          </p>
        </div>
        <Link
          href="/dashboard/clientes/novo"
          className="inline-flex items-center justify-center gap-2 bg-[var(--color-accent)] text-[var(--color-bg-dark)] px-5 py-2.5 rounded-full font-semibold text-sm hover:opacity-90 transition shrink-0"
        >
          <PlusIcon className="w-4 h-4 stroke-[2.5]" />
          <span>Novo Cliente</span>
        </Link>
      </div>

      {error && (
        <div className="rounded-xl border border-red-500/40 bg-red-950/50 p-4 text-sm text-red-200">
          {error}
        </div>
      )}

      {/* Tabela de Clientes */}
      <div className="bg-[rgba(255,255,255,0.04)] rounded-2xl border border-[var(--color-border-dark)] overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm whitespace-nowrap">
            <thead className="bg-black/40 text-[var(--color-text-on-dark)] border-b border-[var(--color-border-dark)]">
              <tr>
                <th className="px-6 py-3.5 font-semibold">Cliente</th>
                <th className="px-6 py-3.5 font-semibold">Contato</th>
                <th className="px-6 py-3.5 font-semibold">Cobrança Mensal</th>
                <th className="px-6 py-3.5 font-semibold">Vencimento</th>
                <th className="px-6 py-3.5 font-semibold">Status</th>
                <th className="px-6 py-3.5 font-semibold text-right">Ações</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[var(--color-border-dark)]">
              {loading ? (
                <tr>
                  <td colSpan={6} className="px-6 py-8 text-center text-white/50">
                    Carregando clientes...
                  </td>
                </tr>
              ) : clients.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-6 py-12 text-center">
                    <p className="text-white/60 mb-3">Nenhum cliente cadastrado ainda.</p>
                    <Link
                      href="/dashboard/clientes/novo"
                      className="inline-flex items-center gap-2 bg-[var(--color-accent)] text-[var(--color-bg-dark)] px-5 py-2 rounded-full font-semibold text-xs hover:opacity-90 transition"
                    >
                      <PlusIcon className="w-3.5 h-3.5 stroke-[2.5]" />
                      <span>Cadastrar Primeiro Cliente</span>
                    </Link>
                  </td>
                </tr>
              ) : (
                clients.map(client => {
                  const hasBilling = Number(client.monthly_value) > 0;
                  return (
                    <tr key={client.id} className="hover:bg-white/5 transition-colors">
                      <td className="px-6 py-4">
                        <p className="font-semibold text-white">{client.name}</p>
                        <p className="text-xs text-white/50">
                          {client.document || "Sem documento"}
                        </p>
                      </td>
                      <td className="px-6 py-4">
                        <p className="text-white/90">{client.email || "-"}</p>
                        <p className="text-xs text-white/50">{client.whatsapp || "-"}</p>
                      </td>
                      <td className="px-6 py-4">
                        {hasBilling ? (
                          <span className="font-medium text-[var(--color-accent)]">
                            R$ {Number(client.monthly_value).toFixed(2).replace(".", ",")}
                          </span>
                        ) : (
                          <span className="inline-block px-2 py-0.5 rounded text-xs bg-white/5 text-white/40">
                            Sem mensalidade
                          </span>
                        )}
                      </td>
                      <td className="px-6 py-4">
                        {hasBilling ? (
                          <span className="text-white/80">Dia {client.due_day}</span>
                        ) : (
                          <span className="text-white/30">-</span>
                        )}
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <span
                            className={
                              client.status === "active"
                                ? "inline-block px-2.5 py-0.5 rounded-full text-xs font-semibold bg-[var(--color-accent)]/15 text-[var(--color-accent)]"
                                : "inline-block px-2.5 py-0.5 rounded-full text-xs font-semibold bg-white/10 text-white/50"
                            }
                          >
                            {client.status === "active" ? "Ativo" : "Inativo"}
                          </span>
                          <button
                            type="button"
                            disabled={statusTogglingId === client.id}
                            onClick={() => handleToggleStatus(client)}
                            className="text-xs text-white/50 hover:text-white underline underline-offset-2 transition disabled:opacity-40 cursor-pointer"
                          >
                            {statusTogglingId === client.id
                              ? "Salvando..."
                              : client.status === "active"
                                ? "Desativar"
                                : "Ativar"}
                          </button>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-right">
                        <Link
                          href={`/dashboard/clientes/editar?id=${client.id}`}
                          aria-label={`Editar ${client.name}`}
                          className="inline-flex items-center gap-1.5 text-xs text-[var(--color-accent)] hover:underline font-medium py-1 px-2.5 rounded-lg hover:bg-[var(--color-accent)]/10 transition"
                        >
                          <PencilIcon className="w-3.5 h-3.5" />
                          <span>Editar</span>
                        </Link>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
