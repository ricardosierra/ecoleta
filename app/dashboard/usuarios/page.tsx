"use client";

import { useEffect, useState, FormEvent } from "react";
import { DashboardGate } from "@/components/DashboardGate";
import Link from "next/link";

type User = {
  id: number;
  login: string;
  email: string | null;
  role: string;
  force_password_change: boolean;
  created_at: string;
};

export default function UsuariosPage() {
  return (
    <DashboardGate>
      <UsuariosList />
    </DashboardGate>
  );
}

function UsuariosList() {
  const [users, setUsers] = useState<User[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [isCreating, setIsCreating] = useState(false);

  // Form
  const [login, setLogin] = useState("");
  const [email, setEmail] = useState("");
  const [role, setRole] = useState("user");
  const [successMsg, setSuccessMsg] = useState("");

  const fetchUsers = () => {
    fetch("/api/users/index.php")
      .then((res) => {
        if (!res.ok) throw new Error("Acesso negado.");
        return res.json();
      })
      .then((data) => {
        if (data.ok) setUsers(data.users);
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    fetchUsers();
  }, []);

  const handleCreate = async (e: FormEvent) => {
    e.preventDefault();
    setError("");
    setSuccessMsg("");
    
    try {
      const res = await fetch("/api/users/index.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ login, email, role }),
      });
      const data = await res.json();
      if (res.ok && data.ok) {
        setSuccessMsg(`Usuário criado! A senha gerada é: ${data.generated_password}`);
        setIsCreating(false);
        setLogin("");
        setEmail("");
        setRole("user");
        fetchUsers();
      } else {
        setError(data.error || "Erro ao criar usuário.");
      }
    } catch (e) {
      setError("Erro de conexão.");
    }
  };

  if (loading) return <div className="p-8 text-white">Carregando usuários...</div>;
  if (error && !isCreating) return <div className="p-8 text-red-400">{error}</div>;

  return (
    <div className="p-8 max-w-6xl mx-auto h-full overflow-y-auto">
      <div className="flex justify-between items-center mb-8">
        <h1 className="text-2xl font-bold text-white">Gerenciar Usuários</h1>
        <button 
          onClick={() => { setIsCreating(!isCreating); setError(""); setSuccessMsg(""); }}
          className="bg-[var(--color-accent)] text-black px-4 py-2 rounded-full text-sm font-semibold hover:opacity-90"
        >
          {isCreating ? "Cancelar" : "Novo Usuário"}
        </button>
      </div>

      {successMsg && (
        <div className="mb-6 p-4 bg-green-900/50 border border-green-500 rounded-xl text-green-100">
          {successMsg}
          <p className="text-xs mt-1 opacity-70">Copie a senha acima, ela não será mostrada novamente.</p>
        </div>
      )}

      {error && isCreating && (
        <div className="mb-6 p-4 bg-red-900/50 border border-red-500 rounded-xl text-red-100">
          {error}
        </div>
      )}

      {isCreating && (
        <form onSubmit={handleCreate} className="mb-8 p-6 bg-[rgba(255,255,255,0.04)] border border-[var(--color-border-dark)] rounded-2xl">
          <h2 className="text-lg font-semibold text-white mb-4">Novo Usuário</h2>
          <div className="grid gap-4 sm:grid-cols-3">
            <label className="block text-sm">Login
              <input value={login} onChange={e=>setLogin(e.target.value)} required className="mt-1 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/20 px-3 py-2 text-white" />
            </label>
            <label className="block text-sm">E-mail
              <input type="email" value={email} onChange={e=>setEmail(e.target.value)} required className="mt-1 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/20 px-3 py-2 text-white" />
            </label>
            <label className="block text-sm">Nível
              <select value={role} onChange={e=>setRole(e.target.value)} className="mt-1 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/20 px-3 py-2 text-white">
                <option value="user">Usuário Padrão</option>
                <option value="master">Master</option>
              </select>
            </label>
          </div>
          <button type="submit" className="mt-4 bg-[var(--color-accent)] text-black px-4 py-2 rounded-full text-sm font-semibold hover:opacity-90">Salvar</button>
        </form>
      )}

      <div className="bg-[rgba(255,255,255,0.04)] border border-[var(--color-border-dark)] rounded-2xl overflow-hidden">
        <table className="w-full text-left text-sm text-white/80">
          <thead className="bg-black/40 text-white border-b border-[var(--color-border-dark)]">
            <tr>
              <th className="px-6 py-4 font-semibold">Login</th>
              <th className="px-6 py-4 font-semibold">E-mail</th>
              <th className="px-6 py-4 font-semibold">Nível</th>
              <th className="px-6 py-4 font-semibold">Status</th>
              <th className="px-6 py-4 font-semibold text-right">Ações</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[var(--color-border-dark)]">
            {users.map(u => (
              <tr key={u.id} className="hover:bg-white/5 transition-colors">
                <td className="px-6 py-4 font-medium text-white">{u.login}</td>
                <td className="px-6 py-4">{u.email || '-'}</td>
                <td className="px-6 py-4">
                  <span className={`px-2 py-1 rounded text-xs uppercase tracking-wider ${u.role === 'root' ? 'bg-red-500/20 text-red-300' : u.role === 'master' ? 'bg-blue-500/20 text-blue-300' : 'bg-white/10'}`}>
                    {u.role}
                  </span>
                </td>
                <td className="px-6 py-4">
                  {u.force_password_change ? <span className="text-amber-400 text-xs">Pendente troca de senha</span> : <span className="text-green-400 text-xs">Ativo</span>}
                </td>
                <td className="px-6 py-4 text-right">
                  <Link href={`/dashboard/usuarios/ver?id=${u.id}`} className="text-[var(--color-accent)] hover:underline text-sm font-semibold">
                    Ver Histórico
                  </Link>
                </td>
              </tr>
            ))}
            {users.length === 0 && (
              <tr><td colSpan={5} className="px-6 py-8 text-center">Nenhum usuário encontrado.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
