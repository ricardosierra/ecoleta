"use client";

import { useEffect, useState, FormEvent } from "react";
import { apiPostJson } from "@/lib/dashboard-api";

type Company = {
  id: number;
  name: string;
  logo_url: string;
  is_active: number;
};

export default function EmpresasPage() {
  const [companies, setCompanies] = useState<Company[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [successMsg, setSuccessMsg] = useState("");
  
  const [isCreating, setIsCreating] = useState(false);
  const [newName, setNewName] = useState("");
  const [newLogoUrl, setNewLogoUrl] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  const fetchData = async () => {
    try {
      const res = await fetch("/api/site/empresas.php");
      if (res.ok) {
        const data = await res.json();
        if (data.ok) setCompanies(data.companies);
      }
    } catch {
      setError("Erro ao carregar empresas.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    fetchData();
  }, []);

  const handleCreate = async (e: FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    try {
      const res = await apiPostJson("/api/site/empresas.php", {
        action: "create",
        name: newName,
        logo_url: newLogoUrl
      });
      const data = await res.json();
      if (res.ok && data.ok) {
        setSuccessMsg("Empresa cadastrada com sucesso!");
        setIsCreating(false);
        setNewName("");
        setNewLogoUrl("");
        fetchData();
      } else {
        setError(data.error || "Erro ao salvar.");
      }
    } catch {
      setError("Erro de conexão.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleToggle = async (c: Company) => {
    try {
      const res = await apiPostJson("/api/site/empresas.php", {
        action: "toggle_active",
        id: c.id,
        is_active: c.is_active ? 0 : 1
      });
      if (res.ok) {
        fetchData();
      }
    } catch {
      // ignora
    }
  };

  const handleDelete = async (id: number) => {
    if (!confirm("Tem certeza que deseja excluir?")) return;
    try {
      const res = await apiPostJson("/api/site/empresas.php", {
        action: "delete",
        id
      });
      if (res.ok) {
        fetchData();
      }
    } catch {
      // ignora
    }
  };

  if (loading) return <div className="p-8 text-white">Carregando...</div>;

  return (
    <div className="p-4 sm:p-8 max-w-6xl mx-auto h-full overflow-y-auto">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="text-2xl font-bold text-white mb-2">Empresas Parceiras</h1>
          <p className="text-sm text-[var(--color-text-on-dark)]">
            Cadastre as logomarcas que aparecem no carrossel da home page.
          </p>
        </div>
        <button
          onClick={() => setIsCreating(!isCreating)}
          className="bg-[var(--color-accent)] text-black px-5 py-2.5 rounded-full text-sm font-semibold transition-opacity"
        >
          {isCreating ? "✕ Cancelar" : "+ Nova Empresa"}
        </button>
      </div>

      {successMsg && (
        <div className="mb-6 p-4 bg-emerald-950/60 border border-emerald-500/50 rounded-2xl text-emerald-200 flex justify-between">
          <p className="font-semibold text-sm">{successMsg}</p>
          <button onClick={() => setSuccessMsg("")} className="text-emerald-400 hover:text-white">✕</button>
        </div>
      )}

      {error && !isCreating && (
        <div className="mb-6 p-4 bg-red-950/60 border border-red-500/50 rounded-2xl text-red-200">
          {error}
        </div>
      )}

      {isCreating && (
        <form onSubmit={handleCreate} className="mb-8 p-6 bg-[rgba(255,255,255,0.04)] border border-[var(--color-border-dark)] rounded-2xl">
          <h2 className="text-lg font-semibold text-white mb-4">Nova Empresa</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="block text-sm text-[var(--color-text-on-dark)]">Nome da Empresa
              <input 
                value={newName} onChange={e => setNewName(e.target.value)} required
                placeholder="Ex: Heineken"
                className="mt-1 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
              />
            </label>
            <label className="block text-sm text-[var(--color-text-on-dark)]">Caminho da Logo (URL ou /logos/...)
              <input 
                value={newLogoUrl} onChange={e => setNewLogoUrl(e.target.value)} required
                placeholder="Ex: /logos/heineken.png"
                className="mt-1 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
              />
            </label>
          </div>
          <div className="mt-5">
            <button type="submit" disabled={isSubmitting} className="bg-[var(--color-accent)] text-black px-6 py-2.5 rounded-full text-sm font-semibold transition-opacity">
              {isSubmitting ? "Salvando..." : "Salvar Empresa"}
            </button>
          </div>
        </form>
      )}

      <div className="bg-[rgba(255,255,255,0.03)] border border-[var(--color-border-dark)] rounded-2xl overflow-hidden shadow-2xl">
        <table className="w-full text-left text-sm text-white/80">
          <thead className="bg-black/40 text-white border-b border-[var(--color-border-dark)] text-xs uppercase tracking-wider">
            <tr>
              <th className="px-6 py-4 font-semibold">Empresa</th>
              <th className="px-6 py-4 font-semibold">Logo URL</th>
              <th className="px-6 py-4 font-semibold">Status</th>
              <th className="px-6 py-4 font-semibold text-right">Ação</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[var(--color-border-dark)]">
            {companies.map(c => (
              <tr key={c.id} className="hover:bg-white/5 transition-colors">
                <td className="px-6 py-4 font-medium text-white flex items-center gap-3">
                  <div className="w-10 h-10 bg-white rounded flex items-center justify-center p-1">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={c.logo_url} alt={c.name} className="max-w-full max-h-full object-contain" />
                  </div>
                  {c.name}
                </td>
                <td className="px-6 py-4 text-xs font-mono">{c.logo_url}</td>
                <td className="px-6 py-4">
                  <button onClick={() => handleToggle(c)} className={`px-2 py-1 rounded text-xs ${c.is_active ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400'}`}>
                    {c.is_active ? 'Ativo' : 'Inativo'}
                  </button>
                </td>
                <td className="px-6 py-4 text-right">
                  <button onClick={() => handleDelete(c.id)} className="text-red-400 hover:text-red-300">🗑️ Excluir</button>
                </td>
              </tr>
            ))}
            {companies.length === 0 && (
              <tr><td colSpan={4} className="px-6 py-8 text-center text-white/60">Nenhuma empresa cadastrada.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
