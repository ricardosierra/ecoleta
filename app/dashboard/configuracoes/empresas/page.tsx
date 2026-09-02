"use client";

import { useEffect, useRef, useState, FormEvent } from "react";
import { apiFetch, apiPostJson } from "@/lib/dashboard-api";

type Company = {
  id: number;
  name: string;
  logo_url: string;
  is_active: number;
};

const ACCEPTED_TYPES = "image/png,image/jpeg,image/webp";
const MAX_LOGO_BYTES = 4 * 1024 * 1024;

export default function EmpresasPage() {
  const [companies, setCompanies] = useState<Company[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [successMsg, setSuccessMsg] = useState("");

  const [isCreating, setIsCreating] = useState(false);
  const [newName, setNewName] = useState("");
  const [newLogoUrl, setNewLogoUrl] = useState("");
  const [logoFile, setLogoFile] = useState<File | null>(null);
  const [logoPreview, setLogoPreview] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const [togglingId, setTogglingId] = useState<number | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);

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

  // O preview usa um object URL; revogar o anterior evita vazar blobs.
  useEffect(() => {
    return () => {
      if (logoPreview) URL.revokeObjectURL(logoPreview);
    };
  }, [logoPreview]);

  const handleFileChange = (file: File | null) => {
    if (logoPreview) URL.revokeObjectURL(logoPreview);

    if (!file) {
      setLogoFile(null);
      setLogoPreview("");
      return;
    }

    if (file.size > MAX_LOGO_BYTES) {
      setError("Imagem muito grande — o limite é 4 MB.");
      setLogoFile(null);
      setLogoPreview("");
      if (fileInputRef.current) fileInputRef.current.value = "";
      return;
    }

    setError("");
    setLogoFile(file);
    setLogoPreview(URL.createObjectURL(file));
  };

  const resetForm = () => {
    setNewName("");
    setNewLogoUrl("");
    handleFileChange(null);
    if (fileInputRef.current) fileInputRef.current.value = "";
  };

  const handleCreate = async (e: FormEvent) => {
    e.preventDefault();
    if (!logoFile && !newLogoUrl.trim()) {
      setError("Envie a imagem da logo ou informe um caminho.");
      return;
    }

    setError("");
    setIsSubmitting(true);
    try {
      let res: Response;
      if (logoFile) {
        // Com arquivo o corpo vai como multipart — o navegador define o
        // Content-Type com o boundary sozinho.
        const form = new FormData();
        form.append("action", "create");
        form.append("name", newName);
        form.append("logo", logoFile);
        res = await apiFetch("/api/site/empresas.php", { method: "POST", body: form });
      } else {
        res = await apiPostJson("/api/site/empresas.php", {
          action: "create",
          name: newName,
          logo_url: newLogoUrl,
        });
      }

      const data = await res.json();
      if (res.ok && data.ok) {
        setSuccessMsg("Empresa cadastrada com sucesso!");
        setIsCreating(false);
        resetForm();
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
    if (togglingId !== null) return;
    setError("");
    setTogglingId(c.id);
    try {
      const res = await apiPostJson("/api/site/empresas.php", {
        action: "toggle_active",
        id: c.id,
        is_active: c.is_active ? 0 : 1,
      });
      const data = await res.json().catch(() => null);
      if (res.ok && data?.ok) {
        // Atualiza só a linha, sem recarregar a lista inteira.
        setCompanies((prev) =>
          prev.map((row) => (row.id === c.id ? { ...row, is_active: data.is_active } : row))
        );
      } else {
        setError(data?.error || "Não consegui alterar o status.");
      }
    } catch {
      setError("Erro de conexão ao alterar o status.");
    } finally {
      setTogglingId(null);
    }
  };

  const handleDelete = async (c: Company) => {
    if (!confirm(`Tem certeza que deseja excluir "${c.name}"?`)) return;
    setError("");
    setDeletingId(c.id);
    try {
      const res = await apiPostJson("/api/site/empresas.php", { action: "delete", id: c.id });
      const data = await res.json().catch(() => null);
      if (res.ok && data?.ok) {
        setCompanies((prev) => prev.filter((row) => row.id !== c.id));
      } else {
        setError(data?.error || "Não consegui excluir a empresa.");
      }
    } catch {
      setError("Erro de conexão ao excluir.");
    } finally {
      setDeletingId(null);
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
          className="bg-[var(--color-accent)] text-black px-5 py-2.5 rounded-full text-sm font-semibold transition-opacity cursor-pointer hover:opacity-90"
        >
          {isCreating ? "✕ Cancelar" : "+ Nova Empresa"}
        </button>
      </div>

      {successMsg && (
        <div className="mb-6 p-4 bg-emerald-950/60 border border-emerald-500/50 rounded-2xl text-emerald-200 flex justify-between">
          <p className="font-semibold text-sm">{successMsg}</p>
          <button onClick={() => setSuccessMsg("")} className="text-emerald-400 hover:text-white cursor-pointer">✕</button>
        </div>
      )}

      {error && (
        <div className="mb-6 p-4 bg-red-950/60 border border-red-500/50 rounded-2xl text-red-200 flex justify-between">
          <p className="text-sm">{error}</p>
          <button onClick={() => setError("")} className="text-red-400 hover:text-white cursor-pointer">✕</button>
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
            <label className="block text-sm text-[var(--color-text-on-dark)]">Imagem da Logo (PNG, JPEG ou WebP — até 4 MB)
              <input
                ref={fileInputRef}
                type="file"
                accept={ACCEPTED_TYPES}
                onChange={e => handleFileChange(e.target.files?.[0] ?? null)}
                className="mt-1 w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2 text-white outline-none focus:border-[var(--color-accent)] file:mr-3 file:rounded-full file:border-0 file:bg-[var(--color-accent)] file:px-4 file:py-1.5 file:text-xs file:font-semibold file:text-black file:cursor-pointer cursor-pointer"
              />
            </label>
          </div>

          {logoPreview && (
            <div className="mt-4 flex items-center gap-4">
              <div className="w-24 h-16 bg-white rounded-lg flex items-center justify-center p-2">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={logoPreview} alt="Prévia da logo" className="max-w-full max-h-full object-contain" />
              </div>
              <p className="text-xs text-[var(--color-text-on-dark)]">
                A imagem será otimizada no servidor (PNG, no máximo 600×360).
              </p>
            </div>
          )}

          {!logoFile && (
            <div className="mt-4">
              <label className="block text-sm text-[var(--color-text-on-dark)]">Ou informe um caminho já existente (URL ou /logos/...)
                <input
                  value={newLogoUrl} onChange={e => setNewLogoUrl(e.target.value)}
                  placeholder="Ex: /logos/heineken.png"
                  className="mt-1 w-full sm:w-1/2 rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
                />
              </label>
            </div>
          )}

          <div className="mt-5">
            <button type="submit" disabled={isSubmitting} className="bg-[var(--color-accent)] text-black px-6 py-2.5 rounded-full text-sm font-semibold transition-opacity cursor-pointer hover:opacity-90 disabled:opacity-60 disabled:cursor-wait">
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
            {companies.map(c => {
              const isToggling = togglingId === c.id;
              const isDeleting = deletingId === c.id;
              return (
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
                    <button
                      onClick={() => handleToggle(c)}
                      disabled={isToggling}
                      title={c.is_active ? "Clique para desativar" : "Clique para ativar"}
                      className={`group inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs transition-colors cursor-pointer disabled:cursor-wait ${
                        c.is_active
                          ? "bg-emerald-500/20 text-emerald-400 hover:bg-red-500/20 hover:text-red-400"
                          : "bg-red-500/20 text-red-400 hover:bg-emerald-500/20 hover:text-emerald-400"
                      }`}
                    >
                      {isToggling ? (
                        <>
                          <span
                            role="status"
                            aria-label="Alterando status"
                            className="inline-block w-3 h-3 rounded-full border-2 border-current border-t-transparent animate-spin"
                          />
                          Alterando...
                        </>
                      ) : (
                        <>
                          <span className="group-hover:hidden">{c.is_active ? "Ativo" : "Inativo"}</span>
                          <span className="hidden group-hover:inline">{c.is_active ? "Desativar" : "Ativar"}</span>
                        </>
                      )}
                    </button>
                  </td>
                  <td className="px-6 py-4 text-right">
                    <button
                      onClick={() => handleDelete(c)}
                      disabled={isDeleting}
                      className="text-red-400 hover:text-red-300 cursor-pointer disabled:cursor-wait disabled:opacity-60"
                    >
                      {isDeleting ? "Excluindo..." : "🗑️ Excluir"}
                    </button>
                  </td>
                </tr>
              );
            })}
            {companies.length === 0 && (
              <tr><td colSpan={4} className="px-6 py-8 text-center text-white/60">Nenhuma empresa cadastrada.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
