"use client";

import { useEffect, useState, useCallback, FormEvent } from "react";
import { useDashboardAuth } from "@/components/DashboardGate";
import { DashboardAccessDenied } from "@/components/DashboardAccessDenied";
import Link from "next/link";
import { apiPostJson } from "@/lib/dashboard-api";
import { canManageGroups } from "@/lib/authz";

type Group = {
  id: number;
  name: string;
  powerbi_url: string | null;
  users_count: number;
  created_at: string;
  updated_at: string;
};

export default function GruposPage() {
  return <GruposList />;
}

function GruposList() {
  const { user: currentUser } = useDashboardAuth();
  const [groups, setGroups] = useState<Group[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [successMsg, setSuccessMsg] = useState("");

  // Modal: Criar Grupo
  const [isCreating, setIsCreating] = useState(false);
  const [name, setName] = useState("");
  const [powerbiUrl, setPowerbiUrl] = useState("");
  const [isSubmittingCreate, setIsSubmittingCreate] = useState(false);

  // Modal: Editar Grupo
  const [editTarget, setEditTarget] = useState<Group | null>(null);
  const [editName, setEditName] = useState("");
  const [editPowerbiUrl, setEditPowerbiUrl] = useState("");
  const [isSubmittingEdit, setIsSubmittingEdit] = useState(false);

  // Modal: Excluir Grupo
  const [deleteTarget, setDeleteTarget] = useState<Group | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);
  const [actionError, setActionError] = useState("");

  const canManage = canManageGroups(currentUser);

  const fetchGroups = useCallback(() => {
    // Sem permissão a tela nem chega a pedir os dados: o 403 da API é a segunda
    // linha de defesa, não a primeira.
    if (!canManage) return;

    fetch("/api/groups/index.php")
      .then((res) => {
        if (!res.ok) throw new Error("Acesso negado. Somente Root e Master podem gerenciar grupos.");
        return res.json();
      })
      .then((data) => {
        if (data.ok) setGroups(data.groups);
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [canManage]);

  useEffect(() => {
    fetchGroups();
  }, [fetchGroups]);

  // Bloqueio antes de qualquer render: não espera o 403 da API para esconder a tela.
  if (!canManage) {
    return <DashboardAccessDenied area="a gestão de grupos" />;
  }

  const handleCreateGroup = async (e: FormEvent) => {
    e.preventDefault();
    setError("");
    setSuccessMsg("");
    setIsSubmittingCreate(true);

    try {
      const res = await apiPostJson("/api/groups/index.php", { name, powerbi_url: powerbiUrl });
      const data = await res.json();

      if (res.ok && data.ok) {
        setSuccessMsg(`Grupo "${data.group.name}" criado com sucesso!`);
        setIsCreating(false);
        setName("");
        setPowerbiUrl("");
        fetchGroups();
      } else {
        setError(data.error || "Erro ao criar grupo.");
      }
    } catch {
      setError("Erro de conexão ao criar grupo.");
    } finally {
      setIsSubmittingCreate(false);
    }
  };

  const openEditModal = (group: Group) => {
    setEditTarget(group);
    setEditName(group.name);
    setEditPowerbiUrl(group.powerbi_url || "");
    setActionError("");
  };

  const handleConfirmEdit = async (e: FormEvent) => {
    e.preventDefault();
    if (!editTarget) return;

    setIsSubmittingEdit(true);
    setActionError("");

    try {
      const res = await apiPostJson("/api/groups/edit.php", {
        group_id: editTarget.id,
        name: editName,
        powerbi_url: editPowerbiUrl,
      });
      const data = await res.json();

      if (res.ok && data.ok) {
        setSuccessMsg(`Grupo "${data.group.name}" atualizado com sucesso!`);
        setEditTarget(null);
        fetchGroups();
      } else {
        setActionError(data.error || "Erro ao atualizar grupo.");
      }
    } catch {
      setActionError("Erro de conexão ao atualizar grupo.");
    } finally {
      setIsSubmittingEdit(false);
    }
  };

  const handleConfirmDelete = async () => {
    if (!deleteTarget) return;

    setIsDeleting(true);
    setActionError("");

    try {
      const res = await apiPostJson("/api/groups/delete.php", { group_id: deleteTarget.id });
      const data = await res.json();

      if (res.ok && data.ok) {
        setSuccessMsg(data.message || `Grupo "${deleteTarget.name}" excluído.`);
        setDeleteTarget(null);
        fetchGroups();
      } else {
        setActionError(data.error || "Erro ao excluir grupo.");
      }
    } catch {
      setActionError("Erro de conexão ao excluir grupo.");
    } finally {
      setIsDeleting(false);
    }
  };

  if (loading) return <div className="p-8 text-white">Carregando grupos...</div>;

  return (
    <div className="p-4 sm:p-8 max-w-6xl mx-auto h-full overflow-y-auto">
      {/* Cabeçalho */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
        <div>
          <h1 className="text-2xl font-bold text-white">Gestão de Grupos & Power BI</h1>
          <p className="text-sm text-[var(--color-text-on-dark)] mt-1">
            Configure os grupos de visualização e seus respectivos códigos/URLs de Power BI integrados via iframe.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <Link
            href="/dashboard/configuracoes/usuarios"
            className="px-4 py-2.5 rounded-full text-xs font-semibold bg-white/10 hover:bg-white/20 text-white transition-colors border border-white/15"
          >
            👥 Gerenciar Usuários
          </Link>
          <button
            onClick={() => {
              setIsCreating(!isCreating);
              setError("");
              setSuccessMsg("");
            }}
            className="bg-[var(--color-accent)] text-black px-5 py-2.5 rounded-full text-sm font-semibold hover:opacity-90 transition-opacity flex items-center gap-2 shadow-md cursor-pointer"
          >
            {isCreating ? "✕ Cancelar" : "+ Novo Grupo"}
          </button>
        </div>
      </div>

      {/* Mensagens de Sucesso e Erro */}
      {successMsg && (
        <div className="mb-6 p-4 bg-emerald-950/60 border border-emerald-500/50 rounded-2xl text-emerald-200 flex items-start justify-between">
          <div>
            <p className="font-semibold text-sm">{successMsg}</p>
            <p className="text-xs mt-1 text-emerald-300/80">
              As alterações são refletidas instantaneamente na visualização dos usuários vinculados.
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

      {/* Formulário: Criar Novo Grupo */}
      {isCreating && (
        <form onSubmit={handleCreateGroup} className="mb-8 p-6 bg-[rgba(255,255,255,0.04)] border border-[var(--color-border-dark)] rounded-2xl shadow-xl animate-in fade-in duration-200">
          <h2 className="text-lg font-semibold text-white mb-4">Cadastrar Novo Grupo</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                Nome do Grupo
              </label>
              <input
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Ex: Infectantes, Coleta, Reciclagem..."
                required
                className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
              />
            </div>
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                Código ou URL de Incorporação do Power BI
              </label>
              <input
                value={powerbiUrl}
                onChange={(e) => setPowerbiUrl(e.target.value)}
                placeholder="Cole o link (https://...) ou tag <iframe> completa"
                className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/30 px-3.5 py-2.5 text-white outline-none focus:border-[var(--color-accent)]"
              />
            </div>
          </div>
          <div className="mt-5 flex gap-3">
            <button
              type="submit"
              disabled={isSubmittingCreate}
              className="bg-[var(--color-accent)] text-black px-6 py-2.5 rounded-full text-sm font-semibold hover:opacity-90 transition-opacity cursor-pointer disabled:opacity-50"
            >
              {isSubmittingCreate ? "Salvando..." : "Salvar Grupo"}
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

      {/* Tabela de Grupos */}
      <div className="bg-[rgba(255,255,255,0.03)] border border-[var(--color-border-dark)] rounded-2xl overflow-hidden shadow-2xl">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm text-white/80">
            <thead className="bg-black/40 text-white border-b border-[var(--color-border-dark)] text-xs uppercase tracking-wider">
              <tr>
                <th className="px-6 py-4 font-semibold">Grupo</th>
                <th className="px-6 py-4 font-semibold">Status Power BI</th>
                <th className="px-6 py-4 font-semibold">URL / Código Embed</th>
                <th className="px-6 py-4 font-semibold text-center">Usuários</th>
                <th className="px-6 py-4 font-semibold text-right">Ações</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[var(--color-border-dark)]">
              {groups.map((g) => {
                const hasUrl = Boolean(g.powerbi_url && g.powerbi_url.trim().length > 0);
                return (
                  <tr key={g.id} className="hover:bg-white/5 transition-colors">
                    <td className="px-6 py-4 font-bold text-white">
                      <div className="flex items-center gap-2">
                        <span className="h-2 w-2 rounded-full bg-[var(--color-accent)]" />
                        <span>{g.name}</span>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      {hasUrl ? (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                          <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />
                          Configurado
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                          <span className="h-1.5 w-1.5 rounded-full bg-amber-400" />
                          Pendente URL
                        </span>
                      )}
                    </td>
                    <td className="px-6 py-4 text-xs font-mono text-white/60 max-w-xs truncate" title={g.powerbi_url || "Não configurado"}>
                      {g.powerbi_url ? g.powerbi_url : <span className="text-white/30 italic">Nenhum código configurado</span>}
                    </td>
                    <td className="px-6 py-4 text-center">
                      <span className="inline-block px-3 py-1 rounded-full text-xs font-semibold bg-white/10 text-white">
                        {g.users_count} {g.users_count === 1 ? "usuário" : "usuários"}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-right">
                      <div className="flex items-center justify-end gap-2">
                        <button
                          onClick={() => openEditModal(g)}
                          title="Editar nome e link do Power BI"
                          className="text-xs font-medium px-3.5 py-1.5 rounded-full border border-blue-500/40 text-blue-300 hover:bg-blue-500/20 hover:text-white transition-colors cursor-pointer"
                        >
                          ✏️ Editar
                        </button>
                        <button
                          onClick={() => {
                            setDeleteTarget(g);
                            setActionError("");
                          }}
                          title="Excluir grupo"
                          className="text-xs font-medium px-3.5 py-1.5 rounded-full border border-red-500/40 text-red-300 hover:bg-red-500/20 hover:text-white transition-colors cursor-pointer"
                        >
                          🗑️ Excluir
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
              {groups.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-6 py-8 text-center text-white/60">
                    Nenhum grupo cadastrado.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Modal: Editar Grupo */}
      {editTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-in fade-in duration-150">
          <form onSubmit={handleConfirmEdit} className="w-full max-w-lg bg-[#0D1F0F] border border-[var(--color-border-dark)] rounded-3xl p-6 shadow-2xl">
            <div className="flex items-center gap-3 mb-4 text-blue-400">
              <span className="text-2xl">✏️</span>
              <h3 className="text-xl font-bold text-white">Editar Grupo</h3>
            </div>
            <p className="text-sm text-[var(--color-text-on-dark)] mb-6">
              Configure o nome e a URL ou tag <code>&lt;iframe&gt;</code> do Power BI para o grupo <strong className="text-white">{editTarget.name}</strong>.
            </p>

            <div className="space-y-4 mb-6">
              <div>
                <label className="block text-xs font-semibold uppercase tracking-wider text-[var(--color-text-on-dark)] mb-1">
                  Nome do Grupo
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
                  URL ou Código Embed do Power BI
                </label>
                <textarea
                  rows={4}
                  value={editPowerbiUrl}
                  onChange={(e) => setEditPowerbiUrl(e.target.value)}
                  placeholder="Ex: https://app.powerbi.com/view?r=... ou cole a tag <iframe ...></iframe> completa"
                  className="w-full rounded-xl border border-[var(--color-border-dark)] bg-black/40 px-3.5 py-2.5 text-white text-xs font-mono outline-none focus:border-[var(--color-accent)]"
                />
                <p className="text-[11px] text-white/50 mt-1">
                  💡 Você pode colar diretamente o link de publicação do Power BI ou o código HTML completo do iframe gerado.
                </p>
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

      {/* Modal: Excluir Grupo */}
      {deleteTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-in fade-in duration-150">
          <div className="w-full max-w-md bg-[#0D1F0F] border border-red-500/30 rounded-3xl p-6 shadow-2xl">
            <div className="flex items-center gap-3 mb-4 text-red-400">
              <span className="text-2xl">🗑️</span>
              <h3 className="text-xl font-bold text-white">Excluir Grupo</h3>
            </div>
            <p className="text-sm text-[var(--color-text-on-dark)] mb-4">
              Tem certeza que deseja excluir o grupo <strong className="text-white">{deleteTarget.name}</strong>?
            </p>

            {deleteTarget.users_count > 0 ? (
              <div className="text-xs text-amber-300 bg-amber-950/40 p-4 rounded-2xl border border-amber-500/30 mb-6 space-y-2">
                <p className="font-semibold">⚠️ Existem {deleteTarget.users_count} usuário(s) vinculado(s) a este grupo.</p>
                <p className="text-amber-300/80">
                  Para proteger a integridade dos acessos, altere o grupo desses usuários na aba <strong>Usuários</strong> antes de excluir este grupo.
                </p>
              </div>
            ) : (
              <p className="text-xs text-red-300/80 mb-6 bg-red-950/40 p-3 rounded-xl border border-red-500/20">
                ⚠️ Esta ação não pode ser desfeita. O grupo e suas configurações de Power BI serão excluídos permanentemente.
              </p>
            )}

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
                disabled={isDeleting || deleteTarget.users_count > 0}
                className="bg-red-600 hover:bg-red-500 text-white px-6 py-2.5 rounded-full text-sm font-semibold transition-opacity disabled:opacity-40 cursor-pointer shadow-lg disabled:cursor-not-allowed"
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
