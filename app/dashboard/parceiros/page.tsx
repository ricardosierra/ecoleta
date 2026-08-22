"use client";

import { useEffect, useState, FormEvent } from "react";
import { DashboardGate, useDashboardAuth } from "@/components/DashboardGate";
import Link from "next/link";
import Image from "next/image";

type Partner = {
  id: number;
  name: string;
  src: string;
  order_index: number;
  is_active: number | boolean;
  created_at?: string;
  updated_at?: string;
};

type PresetLogo = {
  filename: string;
  path: string;
};

export default function ParceirosPage() {
  return (
    <DashboardGate>
      <ParceirosList />
    </DashboardGate>
  );
}

function ParceirosList() {
  const { user: currentUser } = useDashboardAuth();
  const [partners, setPartners] = useState<Partner[]>([]);
  const [presetLogos, setPresetLogos] = useState<PresetLogo[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [successMsg, setSuccessMsg] = useState("");

  // Modal: Criar Parceiro
  const [isCreating, setIsCreating] = useState(false);
  const [name, setName] = useState("");
  const [src, setSrc] = useState("");
  const [orderIndex, setOrderIndex] = useState<number>(0);
  const [isActive, setIsActive] = useState(true);
  const [isSubmittingCreate, setIsSubmittingCreate] = useState(false);

  // Modal: Editar Parceiro
  const [editTarget, setEditTarget] = useState<Partner | null>(null);
  const [editName, setEditName] = useState("");
  const [editSrc, setEditSrc] = useState("");
  const [editOrderIndex, setEditOrderIndex] = useState<number>(0);
  const [editIsActive, setEditIsActive] = useState(true);
  const [isSubmittingEdit, setIsSubmittingEdit] = useState(false);

  // Modal: Excluir Parceiro
  const [deleteTarget, setDeleteTarget] = useState<Partner | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);
  const [actionError, setActionError] = useState("");

  const fetchPartners = () => {
    fetch("/api/partners/index.php")
      .then((res) => {
        if (!res.ok) throw new Error("Acesso negado. Apenas Root e Master podem gerenciar parceiros.");
        return res.json();
      })
      .then((data) => {
        if (data.ok) {
          setPartners(data.partners);
          setOrderIndex(data.partners.length);
        }
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };

  const fetchPresetLogos = () => {
    fetch("/api/partners/logos.php")
      .then((res) => res.json())
      .then((data) => {
        if (data.ok && Array.isArray(data.logos)) {
          setPresetLogos(data.logos);
        }
      })
      .catch(() => {});
  };

  useEffect(() => {
    fetchPartners();
    fetchPresetLogos();
  }, []);

  const isAdmin = currentUser?.role === "root" || currentUser?.role === "master";

  if (!loading && !isAdmin) {
    return (
      <div className="p-8 text-center text-white">
        <div className="max-w-md mx-auto p-8 rounded-3xl bg-[rgba(255,255,255,0.03)] border border-red-500/30">
          <p className="text-3xl mb-3">🔒</p>
          <h2 className="text-xl font-bold text-white mb-2">Acesso Restrito</h2>
          <p className="text-sm text-[var(--color-text-on-dark)] mb-6">
            Apenas usuários com perfil <strong>Root</strong> ou <strong>Master</strong> têm permissão para acessar a gestão de parceiros.
          </p>
          <Link
            href="/dashboard"
            className="inline-block bg-[var(--color-accent)] text-black px-6 py-2.5 rounded-full text-sm font-semibold hover:opacity-90"
          >
            Voltar ao Dashboard
          </Link>
        </div>
      </div>
    );
  }

  const handleCreatePartner = async (e: FormEvent) => {
    e.preventDefault();
    setError("");
    setSuccessMsg("");
    setIsSubmittingCreate(true);

    try {
      const res = await fetch("/api/partners/index.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name,
          src,
          order_index: Number(orderIndex),
          is_active: isActive ? 1 : 0,
        }),
      });
      const data = await res.json();

      if (res.ok && data.ok) {
        setSuccessMsg(`Parceiro "${data.partner.name}" cadastrado com sucesso!`);
        setIsCreating(false);
        setName("");
        setSrc("");
        setIsActive(true);
        fetchPartners();
      } else {
        setError(data.error || "Erro ao cadastrar parceiro.");
      }
    } catch {
      setError("Erro de conexão ao cadastrar parceiro.");
    } finally {
      setIsSubmittingCreate(false);
    }
  };

  const openEditModal = (partner: Partner) => {
    setEditTarget(partner);
    setEditName(partner.name);
    setEditSrc(partner.src);
    setEditOrderIndex(partner.order_index);
    setEditIsActive(Boolean(partner.is_active));
    setActionError("");
  };

  const handleConfirmEdit = async (e: FormEvent) => {
    e.preventDefault();
    if (!editTarget) return;

    setIsSubmittingEdit(true);
    setActionError("");

    try {
      const res = await fetch("/api/partners/edit.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          id: editTarget.id,
          name: editName,
          src: editSrc,
          order_index: Number(editOrderIndex),
          is_active: editIsActive ? 1 : 0,
        }),
      });
      const data = await res.json();

      if (res.ok && data.ok) {
        setSuccessMsg(`Parceiro "${data.partner.name}" atualizado com sucesso!`);
        setEditTarget(null);
        fetchPartners();
      } else {
        setActionError(data.error || "Erro ao atualizar parceiro.");
      }
    } catch {
      setActionError("Erro de conexão ao atualizar parceiro.");
    } finally {
      setIsSubmittingEdit(false);
    }
  };

  const handleToggleActive = async (partner: Partner) => {
    const nextStatus = partner.is_active ? 0 : 1;
    try {
      const res = await fetch("/api/partners/edit.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          id: partner.id,
          name: partner.name,
          src: partner.src,
          order_index: partner.order_index,
          is_active: nextStatus,
        }),
      });
      const data = await res.json();
      if (res.ok && data.ok) {
        setSuccessMsg(`Status de "${partner.name}" alterado para ${nextStatus ? "Ativo" : "Inativo"}.`);
        fetchPartners();
      } else {
        setError(data.error || "Erro ao alterar status.");
      }
    } catch {
      setError("Erro de conexão ao alterar status.");
    }
  };

  const handleConfirmDelete = async () => {
    if (!deleteTarget) return;

    setIsDeleting(true);
    setActionError("");

    try {
      const res = await fetch("/api/partners/delete.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: deleteTarget.id }),
      });
      const data = await res.json();

      if (res.ok && data.ok) {
        setSuccessMsg(data.message || `Parceiro "${deleteTarget.name}" excluído.`);
        setDeleteTarget(null);
        fetchPartners();
      } else {
        setActionError(data.error || "Erro ao excluir parceiro.");
      }
    } catch {
      setActionError("Erro de conexão ao excluir parceiro.");
    } finally {
      setIsDeleting(false);
    }
  };

  if (loading) return <div className="p-8 text-white">Carregando parceiros...</div>;

  return (
    <div className="p-4 sm:p-8 max-w-6xl mx-auto h-full overflow-y-auto">
      {/* Cabeçalho */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
        <div>
          <h1 className="text-2xl font-bold text-white">Gestão de Empresas Parceiras</h1>
          <p className="text-sm text-[var(--color-text-on-dark)] mt-1">
            Adicione, edite a ordem e gerencie as logos das empresas e marcas exibidas no carrossel da Home.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <button
            onClick={() => {
              setIsCreating(!isCreating);
              setError("");
              setSuccessMsg("");
            }}
            className="bg-[var(--color-accent)] text-black px-5 py-2.5 rounded-full text-sm font-semibold hover:opacity-90 transition-opacity flex items-center gap-2 shadow-md cursor-pointer"
          >
            {isCreating ? "✕ Cancelar" : "+ Nova Empresa"}
          </button>
        </div>
      </div>

      {/* Mensagens de Sucesso e Erro */}
      {successMsg && (
        <div className="mb-6 p-4 bg-emerald-950/60 border border-emerald-500/50 rounded-2xl text-emerald-200 flex items-start justify-between">
          <div>
            <p className="font-semibold text-sm">{successMsg}</p>
            <p className="text-xs mt-1 text-emerald-300/80">
              O carrossel da página inicial é atualizado automaticamente com as alterações.
            </p>
          </div>
          <button onClick={() => setSuccessMsg("")} className="text-emerald-400 hover:text-white text-sm font-bold ml-4 cursor-pointer">
            ✕
          </button>
        </div>
      )}

      {error && (
        <div className="mb-6 p-4 bg-red-950/60 border border-red-500/50 rounded-2xl text-red-200">
          {error}
        </div>
      )}

      {/* Formulário: Criar Novo Parceiro */}
      {isCreating && (
        <form onSubmit={handleCreatePartner} className="mb-8 p-6 bg-[rgba(255,255,255,0.04)] border border-[var(--color-border-dark)] rounded-2xl shadow-xl animate-in fade-in duration-200">
          <h2 className="text-lg font-semibold text-white mb-4">Cadastrar Nova Empresa Parceira</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                Nome da Empresa / Marca
              </label>
              <input
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Ex: Petrobras, Coca-Cola, etc."
                required
                className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
              />
            </div>
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                Ordem de Exibição
              </label>
              <input
                type="number"
                value={orderIndex}
                onChange={(e) => setOrderIndex(Number(e.target.value))}
                required
                className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
              />
            </div>
            <div className="sm:col-span-2">
              <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                Caminho da Logo ou URL da Imagem
              </label>
              <input
                value={src}
                onChange={(e) => setSrc(e.target.value)}
                placeholder="Ex: /logos/heineken.png ou https://exemplo.com/logo.png"
                required
                className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
              />

              {presetLogos.length > 0 && (
                <div className="mt-3">
                  <p className="text-xs text-white/60 mb-2">Ou selecione uma logo já enviada na pasta de arquivos:</p>
                  <div className="flex flex-wrap gap-2 max-h-36 overflow-y-auto p-2 bg-black/40 rounded-xl border border-white/10">
                    {presetLogos.map((item) => (
                      <button
                        key={item.path}
                        type="button"
                        onClick={() => setSrc(item.path)}
                        className={`text-xs px-2.5 py-1 rounded-lg border transition-colors cursor-pointer ${
                          src === item.path
                            ? "bg-[var(--color-accent)] text-black border-[var(--color-accent)] font-semibold"
                            : "bg-white/5 border-white/10 text-white/80 hover:bg-white/15"
                        }`}
                      >
                        {item.filename}
                      </button>
                    ))}
                  </div>
                </div>
              )}
            </div>

            {src && (
              <div className="sm:col-span-2 p-4 bg-black/30 rounded-xl border border-white/10 flex items-center gap-4">
                <span className="text-xs text-white/60 font-medium">Prévia:</span>
                <div className="relative h-12 w-28 bg-white/5 rounded-lg p-2 flex items-center justify-center border border-white/10">
                  <Image
                    src={src}
                    alt="Prévia"
                    width={100}
                    height={40}
                    className="max-h-full max-w-full object-contain"
                    unoptimized
                  />
                </div>
              </div>
            )}

            <div className="sm:col-span-2 flex items-center gap-3">
              <label className="relative inline-flex items-center cursor-pointer">
                <input
                  type="checkbox"
                  checked={isActive}
                  onChange={(e) => setIsActive(e.target.checked)}
                  className="sr-only peer"
                />
                <div className="w-11 h-6 bg-white/20 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[var(--color-accent)]"></div>
                <span className="ml-3 text-sm font-medium text-white">
                  {isActive ? "Empresa Ativa (Visível no site)" : "Inativa (Oculta do site)"}
                </span>
              </label>
            </div>
          </div>
          <div className="mt-5 flex gap-3">
            <button
              type="submit"
              disabled={isSubmittingCreate}
              className="bg-[var(--color-accent)] text-black px-6 py-2.5 rounded-full text-sm font-semibold hover:opacity-90 transition-opacity cursor-pointer disabled:opacity-50"
            >
              {isSubmittingCreate ? "Cadastrando..." : "Cadastrar Empresa"}
            </button>
            <button
              type="button"
              onClick={() => setIsCreating(false)}
              className="px-5 py-2.5 rounded-full text-sm font-medium text-white/70 hover:text-white hover:bg-white/5 transition-colors cursor-pointer"
            >
              Cancelar
            </button>
          </div>
        </form>
      )}

      {/* Grid de Parceiros */}
      <div className="bg-[rgba(255,255,255,0.03)] border border-[var(--color-border-dark)] rounded-2xl overflow-hidden shadow-2xl">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm text-white/80">
            <thead className="bg-black/40 text-white border-b border-[var(--color-border-dark)] text-xs uppercase tracking-wider">
              <tr>
                <th className="px-6 py-4 font-semibold">Ordem</th>
                <th className="px-6 py-4 font-semibold">Logo</th>
                <th className="px-6 py-4 font-semibold">Nome da Empresa</th>
                <th className="px-6 py-4 font-semibold">Caminho / URL</th>
                <th className="px-6 py-4 font-semibold text-center">Status</th>
                <th className="px-6 py-4 font-semibold text-right">Ações</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[var(--color-border-dark)]">
              {partners.map((p) => {
                const active = Boolean(p.is_active);
                return (
                  <tr key={p.id} className="hover:bg-white/5 transition-colors">
                    <td className="px-6 py-4 font-mono text-xs text-white/60">
                      #{p.order_index}
                    </td>
                    <td className="px-6 py-4">
                      <div className="relative h-12 w-24 bg-white/5 rounded-lg p-1.5 flex items-center justify-center border border-white/10">
                        {p.src ? (
                          <Image
                            src={p.src}
                            alt={p.name}
                            width={80}
                            height={36}
                            className="max-h-full max-w-full object-contain"
                            unoptimized
                          />
                        ) : (
                          <span className="text-[10px] text-white/40">Sem logo</span>
                        )}
                      </div>
                    </td>
                    <td className="px-6 py-4 font-bold text-white">
                      {p.name}
                    </td>
                    <td className="px-6 py-4 text-xs font-mono text-white/60 max-w-xs truncate" title={p.src}>
                      {p.src}
                    </td>
                    <td className="px-6 py-4 text-center">
                      <button
                        onClick={() => handleToggleActive(p)}
                        title="Clique para alternar visibilidade"
                        className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold border transition-all cursor-pointer ${
                          active
                            ? "bg-emerald-500/20 text-emerald-300 border-emerald-500/30 hover:bg-emerald-500/30"
                            : "bg-zinc-700/40 text-zinc-400 border-zinc-600/40 hover:bg-zinc-700/60"
                        }`}
                      >
                        <span className={`h-1.5 w-1.5 rounded-full ${active ? "bg-emerald-400" : "bg-zinc-400"}`} />
                        {active ? "Ativo" : "Inativo"}
                      </button>
                    </td>
                    <td className="px-6 py-4 text-right">
                      <div className="flex items-center justify-end gap-2">
                        <button
                          onClick={() => openEditModal(p)}
                          title="Editar empresa e logo"
                          className="text-xs font-medium px-3.5 py-1.5 rounded-full border border-blue-500/40 text-blue-300 hover:bg-blue-500/20 hover:text-white transition-colors cursor-pointer"
                        >
                          ✏️ Editar
                        </button>
                        <button
                          onClick={() => {
                            setDeleteTarget(p);
                            setActionError("");
                          }}
                          title="Excluir empresa"
                          className="text-xs font-medium px-3.5 py-1.5 rounded-full border border-red-500/40 text-red-300 hover:bg-red-500/20 hover:text-white transition-colors cursor-pointer"
                        >
                          🗑️ Excluir
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
              {partners.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-6 py-8 text-center text-white/60">
                    Nenhuma empresa parceira cadastrada.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Modal: Editar Parceiro */}
      {editTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-in fade-in duration-150">
          <form onSubmit={handleConfirmEdit} className="w-full max-w-lg bg-[#0D1F0F] border border-[var(--color-border-dark)] rounded-3xl p-6 shadow-2xl">
            <div className="flex items-center gap-3 mb-4 text-blue-400">
              <span className="text-2xl">✏️</span>
              <h3 className="text-xl font-bold text-white">Editar Parceiro</h3>
            </div>
            <p className="text-sm text-[var(--color-text-on-dark)] mb-6">
              Altere o nome, caminho da logo ou ordem de exibição da empresa <strong className="text-white">{editTarget.name}</strong>.
            </p>

            <div className="space-y-4 mb-6">
              <div>
                <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                  Nome da Empresa
                </label>
                <input
                  value={editName}
                  onChange={(e) => setEditName(e.target.value)}
                  required
                  className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/40 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                  Ordem de Exibição
                </label>
                <input
                  type="number"
                  value={editOrderIndex}
                  onChange={(e) => setEditOrderIndex(Number(e.target.value))}
                  required
                  className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/40 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                  Caminho da Logo ou URL
                </label>
                <input
                  value={editSrc}
                  onChange={(e) => setEditSrc(e.target.value)}
                  required
                  className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/40 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
                />

                {presetLogos.length > 0 && (
                  <div className="mt-3">
                    <p className="text-xs text-white/60 mb-2">Ou selecione uma logo do repositório:</p>
                    <div className="flex flex-wrap gap-2 max-h-32 overflow-y-auto p-2 bg-black/40 rounded-xl border border-white/10">
                      {presetLogos.map((item) => (
                        <button
                          key={item.path}
                          type="button"
                          onClick={() => setEditSrc(item.path)}
                          className={`text-xs px-2.5 py-1 rounded-lg border transition-colors cursor-pointer ${
                            editSrc === item.path
                              ? "bg-[var(--color-accent)] text-black border-[var(--color-accent)] font-semibold"
                              : "bg-white/5 border-white/10 text-white/80 hover:bg-white/15"
                          }`}
                        >
                          {item.filename}
                        </button>
                      ))}
                    </div>
                  </div>
                )}
              </div>

              {editSrc && (
                <div className="p-3 bg-black/30 rounded-xl border border-white/10 flex items-center gap-4">
                  <span className="text-xs text-white/60 font-medium">Prévia:</span>
                  <div className="relative h-12 w-28 bg-white/5 rounded-lg p-2 flex items-center justify-center border border-white/10">
                    <Image
                      src={editSrc}
                      alt="Prévia"
                      width={100}
                      height={40}
                      className="max-h-full max-w-full object-contain"
                      unoptimized
                    />
                  </div>
                </div>
              )}

              <div>
                <label className="relative inline-flex items-center cursor-pointer">
                  <input
                    type="checkbox"
                    checked={editIsActive}
                    onChange={(e) => setEditIsActive(e.target.checked)}
                    className="sr-only peer"
                  />
                  <div className="w-11 h-6 bg-white/20 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[var(--color-accent)]"></div>
                  <span className="ml-3 text-sm font-medium text-white">
                    {editIsActive ? "Empresa Ativa (Visível)" : "Inativa (Oculta)"}
                  </span>
                </label>
              </div>
            </div>

            {actionError && (
              <p className="text-xs text-red-300 mb-4 p-3 bg-red-950/60 border border-red-500/40 rounded-xl">{actionError}</p>
            )}

            <div className="flex justify-end gap-3">
              <button
                type="button"
                onClick={() => {
                  setEditTarget(null);
                  setActionError("");
                }}
                disabled={isSubmittingEdit}
                className="px-5 py-2.5 rounded-full text-sm text-white/70 hover:text-white hover:bg-white/5 transition-colors cursor-pointer"
              >
                Cancelar
              </button>
              <button
                type="submit"
                disabled={isSubmittingEdit}
                className="bg-blue-600 hover:bg-blue-500 text-white px-6 py-2.5 rounded-full text-sm font-semibold transition-opacity disabled:opacity-50 cursor-pointer shadow-lg"
              >
                {isSubmittingEdit ? "Salvando..." : "Salvar Alterações"}
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Modal: Excluir Parceiro */}
      {deleteTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-in fade-in duration-150">
          <div className="w-full max-w-md bg-[#0D1F0F] border border-red-500/30 rounded-3xl p-6 shadow-2xl">
            <div className="flex items-center gap-3 mb-4 text-red-400">
              <span className="text-2xl">🗑️</span>
              <h3 className="text-xl font-bold text-white">Excluir Empresa</h3>
            </div>
            <p className="text-sm text-[var(--color-text-on-dark)] mb-4">
              Tem certeza que deseja excluir a empresa parceira <strong className="text-white">{deleteTarget.name}</strong>?
            </p>
            <p className="text-xs text-red-300/80 mb-6 bg-red-950/40 p-3 rounded-xl border border-red-500/20">
              ⚠️ A empresa deixará de ser exibida no carrossel de clientes da página inicial.
            </p>

            {actionError && (
              <p className="text-xs text-red-300 mb-4 p-3 bg-red-950/60 border border-red-500/40 rounded-xl">{actionError}</p>
            )}

            <div className="flex justify-end gap-3">
              <button
                onClick={() => {
                  setDeleteTarget(null);
                  setActionError("");
                }}
                disabled={isDeleting}
                className="px-5 py-2.5 rounded-full text-sm text-white/70 hover:text-white hover:bg-white/5 transition-colors cursor-pointer"
              >
                Cancelar
              </button>
              <button
                onClick={handleConfirmDelete}
                disabled={isDeleting}
                className="bg-red-600 hover:bg-red-500 text-white px-6 py-2.5 rounded-full text-sm font-semibold transition-opacity disabled:opacity-40 cursor-pointer shadow-lg"
              >
                {isDeleting ? "Excluindo..." : "Confirmar Exclusão"}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
