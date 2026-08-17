"use client";

import { useEffect, useState } from "react";
import { DashboardGate } from "@/components/DashboardGate";
import Link from "next/link";
import { useParams } from "next/navigation";

type User = {
  id: number;
  login: string;
  email: string | null;
  role: string;
  created_at: string;
};

type Log = {
  id: number;
  ip_address: string;
  user_agent: string;
  logged_at: string;
};

export default function ViewUsuarioPage() {
  return (
    <DashboardGate>
      <UsuarioDetails />
    </DashboardGate>
  );
}

function UsuarioDetails() {
  const params = useParams();
  const id = params.id as string;
  
  const [user, setUser] = useState<User | null>(null);
  const [logs, setLogs] = useState<Log[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    fetch(`/api/users/logs.php?user_id=${id}`)
      .then((res) => {
        if (!res.ok) throw new Error("Acesso negado ou usuário não encontrado.");
        return res.json();
      })
      .then((data) => {
        if (data.ok) {
          setUser(data.user);
          setLogs(data.logs);
        } else {
          throw new Error(data.error);
        }
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [id]);

  if (loading) return <div className="p-8 text-white">Carregando dados...</div>;
  if (error) return <div className="p-8 text-red-400">{error}</div>;
  if (!user) return <div className="p-8 text-white">Usuário não encontrado.</div>;

  return (
    <div className="p-8 max-w-6xl mx-auto h-full overflow-y-auto">
      <div className="mb-8">
        <Link href="/dashboard/usuarios" className="text-[var(--color-accent)] text-sm font-medium hover:underline flex items-center gap-2 mb-4">
          &larr; Voltar para Usuários
        </Link>
        <h1 className="text-3xl font-bold text-white mb-2">{user.login}</h1>
        <p className="text-[var(--color-text-on-dark)] text-sm mb-1">E-mail: <span className="text-white">{user.email || 'N/A'}</span></p>
        <p className="text-[var(--color-text-on-dark)] text-sm">Nível de acesso: <span className="text-white uppercase tracking-wider text-xs">{user.role}</span></p>
        <p className="text-[var(--color-text-on-dark)] text-sm mt-1">Criado em: <span className="text-white">{new Date(user.created_at).toLocaleString('pt-BR')}</span></p>
      </div>

      <div className="bg-[rgba(255,255,255,0.04)] border border-[var(--color-border-dark)] rounded-2xl overflow-hidden">
        <div className="px-6 py-4 border-b border-[var(--color-border-dark)] bg-black/20">
          <h2 className="text-lg font-semibold text-white">Histórico de Acessos (Últimos 100)</h2>
        </div>
        <table className="w-full text-left text-sm text-white/80">
          <thead className="bg-black/40 text-white border-b border-[var(--color-border-dark)]">
            <tr>
              <th className="px-6 py-4 font-semibold">Data / Hora</th>
              <th className="px-6 py-4 font-semibold">Endereço IP</th>
              <th className="px-6 py-4 font-semibold">User Agent</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[var(--color-border-dark)]">
            {logs.map(log => (
              <tr key={log.id} className="hover:bg-white/5 transition-colors">
                <td className="px-6 py-4 font-medium text-white whitespace-nowrap">
                  {new Date(log.logged_at).toLocaleString('pt-BR')}
                </td>
                <td className="px-6 py-4 text-xs font-mono">{log.ip_address}</td>
                <td className="px-6 py-4 text-xs max-w-md truncate" title={log.user_agent}>{log.user_agent}</td>
              </tr>
            ))}
            {logs.length === 0 && (
              <tr><td colSpan={3} className="px-6 py-8 text-center">Nenhum acesso registrado.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
